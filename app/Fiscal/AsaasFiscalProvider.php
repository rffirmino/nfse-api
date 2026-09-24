<?php

namespace App\Fiscal;

use App\Contracts\FiscalProvider;
use App\Contracts\FiscalResult;
use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adapter do provedor fiscal Asaas (NFS-e via API v3).
 *
 * Credencial é por estabelecimento/subconta (token). O fluxo oficial é:
 * municipalOptions -> fiscalInfo -> fiscalInfo/services -> invoices -> webhooks.
 * Aqui a emissão usa POST /v3/invoices e o status é acompanhado por consulta
 * (os webhooks do Asaas podem atualizar depois via job/webhook, conforme evolução).
 */
class AsaasFiscalProvider implements FiscalProvider
{
    public function __construct(
        private string $accessToken,
        private ?string $baseUrl = null,
    ) {
        $this->baseUrl = rtrim($this->baseUrl ?: (string) config('services.asaas.base_url', 'https://api-sandbox.asaas.com'), '/');
        if ($this->accessToken === '') {
            throw new RuntimeException('Asaas não configurado: token da subconta ausente.');
        }
    }

    public function issueInvoice(NfseInvoice $invoice): FiscalResult
    {
        $payload = $this->buildPayload($invoice);
        $response = $this->client()->post($this->baseUrl . '/v3/invoices', $payload);

        if ($response->failed()) {
            return new FiscalResult(
                status: 'error',
                externalId: null,
                protocol: null,
                message: $this->errorMessage($response->json(), $response->status()),
                payload: $response->json() ?? [],
            );
        }

        $data = $response->json() ?? [];
        $status = self::mapStatus($data['status'] ?? null);

        return new FiscalResult(
            status: $status,
            externalId: isset($data['id']) ? (string) $data['id'] : null,
            protocol: isset($data['number']) ? (string) $data['number'] : null,
            message: $status === 'authorized' ? null : 'Aguardando autorização da prefeitura (Asaas).',
            payload: $data,
        );
    }

    public function getInvoice(NfseInvoice $invoice): FiscalResult
    {
        return $this->consultar($invoice);
    }

    public function getStatus(NfseInvoice $invoice): FiscalResult
    {
        return $this->consultar($invoice);
    }

    public function cancelInvoice(NfseInvoice $invoice, string $reason): FiscalResult
    {
        if (empty($invoice->external_id)) {
            return new FiscalResult('error', null, null, 'Nota sem identificador no Asaas.');
        }

        $response = $this->client()->post(
            $this->baseUrl . '/v3/invoices/' . $invoice->external_id . '/cancel',
            ['reason' => $reason],
        );

        if ($response->failed()) {
            return new FiscalResult('error', $invoice->external_id, null,
                $this->errorMessage($response->json(), $response->status()), $response->json() ?? []);
        }

        $data = $response->json() ?? [];

        return new FiscalResult(
            status: self::mapStatus($data['status'] ?? 'CANCELED') === 'cancelled' ? 'cancelled' : 'processing',
            externalId: $invoice->external_id,
            protocol: $invoice->protocol,
            message: $reason,
            payload: $data,
        );
    }

    /**
     * Exigências fiscais do município do CNPJ da conta (usado na checagem de cobertura).
     */
    public function municipalOptions(): array
    {
        $response = $this->client()->get($this->baseUrl . '/v3/fiscalInfo/municipalOptions');

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        return $response->json() ?? [];
    }

    private function consultar(NfseInvoice $invoice): FiscalResult
    {
        if (empty($invoice->external_id)) {
            return new FiscalResult($invoice->status, null, $invoice->protocol);
        }

        $response = $this->client()->get($this->baseUrl . '/v3/invoices/' . $invoice->external_id);

        if ($response->failed()) {
            return new FiscalResult('error', $invoice->external_id, null,
                $this->errorMessage($response->json(), $response->status()), $response->json() ?? []);
        }

        $data = $response->json() ?? [];

        return new FiscalResult(
            status: self::mapStatus($data['status'] ?? null),
            externalId: isset($data['id']) ? (string) $data['id'] : $invoice->external_id,
            protocol: isset($data['number']) ? (string) $data['number'] : $invoice->protocol,
            payload: $data,
        );
    }

    private function buildPayload(NfseInvoice $invoice): array
    {
        $requestPayload = $invoice->request_payload ?? [];

        $serviceCode = $requestPayload['service_code'] ?? null;
        if ($invoice->fiscal_configuration_id) {
            $configuration = FiscalConfiguration::find($invoice->fiscal_configuration_id);
            $serviceCode = $serviceCode ?: ($configuration->service_code ?? null);
        }

        if (empty($serviceCode)) {
            throw new RuntimeException('Código de serviço municipal (municipalServiceCode) ausente para a emissão no Asaas.');
        }

        $customerId = $requestPayload['asaas_customer_id'] ?? null;
        if (empty($customerId)) {
            $customerId = $this->obterOuCriarCliente($requestPayload);
        }

        return [
            'effectiveDate' => now()->toDateString(),
            'value' => (float) $invoice->amount,
            'customer' => $customerId,
            'municipalServiceCode' => (string) $serviceCode,
            'description' => (string) ($requestPayload['description'] ?? 'Serviço prestado'),
        ];
    }

    private function obterOuCriarCliente(array $requestPayload): string
    {
        $cpfCnpj = preg_replace('/\D/', '', (string) ($requestPayload['customer_cpf_cnpj'] ?? ''));
        $name = (string) ($requestPayload['customer_name'] ?? '');

        if ($cpfCnpj === '' || $name === '') {
            throw new RuntimeException(
                'Para emitir no Asaas informe asaas_customer_id ou os dados do tomador (customer_name e customer_cpf_cnpj).'
            );
        }

        $response = $this->client()->post($this->baseUrl . '/v3/customers', [
            'name' => $name,
            'cpfCnpj' => $cpfCnpj,
            'email' => $requestPayload['customer_email'] ?? null,
            'mobilePhone' => $requestPayload['customer_phone'] ?? null,
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $id = $response->json('id');
        if (empty($id)) {
            throw new RuntimeException('Asaas não retornou o id do cliente.');
        }

        return (string) $id;
    }

    private function client()
    {
        return Http::withToken($this->accessToken)->acceptJson()->timeout((int) config('services.asaas.timeout', 20));
    }

    public static function mapStatus(?string $asaasStatus): string
    {
        return match (strtoupper((string) $asaasStatus)) {
            'AUTHORIZED' => 'authorized',
            'CANCELED', 'CANCELLED' => 'cancelled',
            'ERROR' => 'error',
            'SCHEDULED', 'SYNCHRONIZED', 'PROCESSING_CANCELLATION', 'CANCELLATION_DENIED' => 'processing',
            default => 'pending',
        };
    }

    private function errorMessage(?array $body, int $status): string
    {
        $description = $body['errors'][0]['description'] ?? null;

        return 'Asaas HTTP ' . $status . ($description ? ': ' . $description : '');
    }
}
