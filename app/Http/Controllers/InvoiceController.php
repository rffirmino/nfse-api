<?php

namespace App\Http\Controllers;

use App\Models\NfseInvoice;
use App\Models\NfseInvoiceEvent;
use App\Models\FiscalConfiguration;
use App\Models\FiscalAccount;
use App\Fiscal\AsaasFiscalProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\IssueNfseInvoice;

use OpenApi\Attributes as OA;

class InvoiceController extends Controller
{
    #[OA\Post(
        path: '/api/v1/invoices',
        tags: ['Fiscal'],
        summary: 'Enfileira a emissão de uma NFS-e',
        description: 'Autenticação HMAC entre sistemas (mesmo esquema do módulo WhatsApp). '
            . 'O `service_code` deve ser exatamente o código de serviço configurado para o '
            . 'estabelecimento/município no serviço (ex.: item da LC 116 como "6.02"); não é um '
            . 'identificador interno da API.',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'agendamentos')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer', example: 1788000000), description: 'Unix timestamp (segundos); tolerância de 300s'),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'a1b2c3d4e5f6')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'sha256=...'), description: 'HMAC-SHA256(timestamp + "\n" + nonce + "\n" + raw_body) com o segredo do serviço'),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'nfse:appointment:apt-001')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['establishment_external_id', 'appointment_external_id', 'customer_external_id', 'amount'],
                properties: [
                    new OA\Property(property: 'establishment_external_id', type: 'string', example: 'est-001', description: 'Estabelecimento/prestador no sistema do cliente'),
                    new OA\Property(property: 'fiscal_configuration_id', type: 'string', format: 'uuid', nullable: true, description: 'Opcional: força uma configuração fiscal específica'),
                    new OA\Property(property: 'municipality_code', type: 'string', example: '2211001', description: 'Código IBGE do município de incidência (obrigatório sem fiscal_configuration_id)'),
                    new OA\Property(property: 'service_code', type: 'string', example: '6.02', description: 'Código de serviço configurado para o estabelecimento/município (LC 116)'),
                    new OA\Property(property: 'appointment_external_id', type: 'string', example: 'apt-001'),
                    new OA\Property(property: 'customer_external_id', type: 'string', example: 'usr-001'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 150.00),
                    new OA\Property(property: 'emission_requested', type: 'boolean', default: true, description: 'false apenas registra a solicitação (not_requested), sem enviar ao emissor'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 202, description: 'Aceito para processamento (retorna invoice_id e status)'),
            new OA\Response(response: 200, description: 'Requisição duplicada idempotente (retorna o registro original)'),
            new OA\Response(response: 401, description: 'Assinatura HMAC inválida'),
            new OA\Response(response: 422, description: 'Dados inválidos ou sem configuração fiscal ativa'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_external_id' => ['required', 'string', 'max:150'],
            'fiscal_configuration_id' => ['nullable', 'uuid'],
            'municipality_code' => ['required_without:fiscal_configuration_id', 'nullable', 'string', 'max:20'],
            'service_code' => ['required_without:fiscal_configuration_id', 'nullable', 'string', 'max:40'],
            'appointment_external_id' => ['required', 'string', 'max:150'],
            'customer_external_id' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'emission_requested' => ['sometimes', 'boolean'],
        ]);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        if ($idempotencyKey === '') {
            return response()->json(['error' => [
                'code' => 'missing_idempotency_key',
                'message' => 'Idempotency-Key é obrigatório.',
                'request_id' => $request->attributes->get('request_id'),
            ]], 422);
        }

        $existing = NfseInvoice::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return response()->json([
                'invoice_id' => $existing->id,
                'status' => $existing->status,
                'idempotent' => true,
                'request_id' => $request->attributes->get('request_id'),
            ], 200);
        }

        $requested = (bool) ($data['emission_requested'] ?? true);

        $configuration = null;
        if (!empty($data['fiscal_configuration_id'])) {
            $configuration = FiscalConfiguration::whereKey($data['fiscal_configuration_id'])
                ->where('establishment_external_id', $data['establishment_external_id'])
                ->where('active', true)->first();
        } else {
            $configuration = FiscalConfiguration::where('establishment_external_id', $data['establishment_external_id'])
                ->where('municipality_code', $data['municipality_code'])
                ->where('service_code', $data['service_code'])
                ->where('active', true)->first();
        }

        if ($configuration === null) {
            return response()->json(['error' => [
                'code' => 'fiscal_configuration_not_found',
                'message' => 'Nenhuma configuração fiscal ativa foi encontrada.',
                'request_id' => $request->attributes->get('request_id'),
            ]], 422);
        }

        $invoice = DB::transaction(function () use ($data, $requested, $idempotencyKey, $request, $configuration): NfseInvoice {
            return NfseInvoice::create(array_merge($data, [
                'status' => $requested ? 'pending' : 'not_requested',
                'fiscal_configuration_id' => $configuration->id,
                'provider' => $configuration->provider,
                'idempotency_key' => $idempotencyKey,
                'request_payload' => $request->all(),
            ]));
        });

        if ($requested && $configuration->provider !== 'manual') {
            IssueNfseInvoice::dispatch($invoice->id);
        }

        return response()->json([
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'idempotent' => false,
            'message' => 'Solicitação fiscal registrada para processamento.',
            'request_id' => $request->attributes->get('request_id'),
        ], 202);
    }

    #[OA\Get(
        path: '/api/v1/invoices/{invoice}',
        tags: ['Fiscal'],
        summary: 'Consulta o status de uma NFS-e',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status da solicitação fiscal'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
            new OA\Response(response: 404, description: 'Não encontrada'),
        ],
    )]
    public function show(Request $request, NfseInvoice $invoice): JsonResponse
    {
        return response()->json([
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'provider' => $invoice->provider,
            'external_id' => $invoice->external_id,
            'attempts' => $invoice->attempts,
            'issued_at' => $invoice->issued_at,
            'cancelled_at' => $invoice->cancelled_at,
            'error_message' => $invoice->error_message,
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/invoices',
        tags: ['Fiscal'],
        summary: 'Lista solicitações fiscais (histórico)',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'establishment_external_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'appointment_external_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 20)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 0)),
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Lista de notas'), new OA\Response(response: 401, description: 'Assinatura inválida')],
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $offset = max((int) $request->query('offset', 0), 0);

        $query = NfseInvoice::query();
        foreach (['establishment_external_id', 'status', 'appointment_external_id'] as $field) {
            $value = $request->query($field);
            if ($value !== null && $value !== '') {
                $query->where($field, $value);
            }
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return response()->json(['success' => true, 'total' => $total, 'items' => $items]);
    }

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/manual',
        tags: ['Fiscal'],
        summary: 'Registra uma NFS-e emitida fora do sistema (provedor manual)',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['invoice_number'],
            properties: [
                new OA\Property(property: 'invoice_number', type: 'string', example: '1234'),
                new OA\Property(property: 'series', type: 'string', nullable: true),
                new OA\Property(property: 'access_key', type: 'string', nullable: true),
                new OA\Property(property: 'protocol', type: 'string', nullable: true),
                new OA\Property(property: 'document_url', type: 'string', nullable: true),
                new OA\Property(property: 'xml', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Nota registrada'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
            new OA\Response(response: 404, description: 'Não encontrada'),
            new OA\Response(response: 422, description: 'Estado inválido para registro manual'),
        ],
    )]
    public function manual(Request $request, NfseInvoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:60'],
            'series' => ['nullable', 'string', 'max:20'],
            'access_key' => ['nullable', 'string', 'max:80'],
            'protocol' => ['nullable', 'string', 'max:80'],
            'document_url' => ['nullable', 'string', 'max:500'],
            'xml' => ['nullable', 'string'],
        ]);

        if (!in_array($invoice->status, ['pending', 'processing', 'error', 'not_requested'], true)) {
            return response()->json(['error' => [
                'code' => 'invalid_state',
                'message' => 'Nota não pode ser registrada manualmente no estado atual.',
                'request_id' => $request->attributes->get('request_id'),
            ]], 422);
        }

        $from = $invoice->status;
        $invoice->forceFill([
            'status' => 'authorized',
            'invoice_number' => $data['invoice_number'],
            'series' => $data['series'] ?? null,
            'access_key' => $data['access_key'] ?? null,
            'protocol' => $data['protocol'] ?? null,
            'document_url' => $data['document_url'] ?? null,
            'xml' => $data['xml'] ?? null,
            'error_message' => null,
            'issued_at' => now(),
        ])->save();

        NfseInvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'from_status' => $from,
            'to_status' => 'authorized',
            'event' => 'manual.registered',
            'message' => 'Nota emitida fora do sistema e registrada pela API.',
        ]);

        return response()->json(['success' => true, 'invoice_id' => $invoice->id, 'status' => $invoice->status]);
    }

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/cancel',
        tags: ['Fiscal'],
        summary: 'Cancela uma NFS-e (mantendo histórico)',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', example: 'Erro de emissão')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Nota cancelada'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
            new OA\Response(response: 422, description: 'Estado inválido para cancelamento'),
        ],
    )]
    public function cancel(Request $request, NfseInvoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        if (!in_array($invoice->status, ['authorized', 'rejected', 'error'], true)) {
            return response()->json(['error' => [
                'code' => 'invalid_state',
                'message' => 'Nota não pode ser cancelada no estado atual.',
                'request_id' => $request->attributes->get('request_id'),
            ]], 422);
        }

        $from = $invoice->status;
        $invoice->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'error_message' => $data['reason'],
        ])->save();

        NfseInvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'from_status' => $from,
            'to_status' => 'cancelled',
            'event' => 'invoice.cancelled',
            'message' => $data['reason'],
        ]);

        return response()->json(['success' => true, 'invoice_id' => $invoice->id, 'status' => 'cancelled']);
    }

    #[OA\Get(
        path: '/api/v1/fiscal/coverage',
        tags: ['Fiscal'],
        summary: 'Verifica cobertura/configuração fiscal de um município/serviço (onboarding)',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'establishment_external_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'municipality_code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service_code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Cobertura/configuração'), new OA\Response(response: 401, description: 'Assinatura inválida')],
    )]
    public function coverage(Request $request): JsonResponse
    {
        $establishment = (string) $request->query('establishment_external_id', '');
        $municipality = (string) $request->query('municipality_code', '');
        $service = (string) $request->query('service_code', '');

        $configuration = FiscalConfiguration::where('establishment_external_id', $establishment)
            ->where('municipality_code', $municipality)
            ->where('service_code', $service)
            ->where('active', true)
            ->first();

        $missing = [];
        if ($configuration !== null) {
            // MEI não tem alíquota de ISS (taxa mensal fixa — DAS); a alíquota
            // segue configurável para a evolução do regime (ex.: Simples/Lucro).
            $required = ['provider', 'environment', 'provider_registration', 'tax_regime'];
            if (strtoupper((string) $configuration->tax_regime) !== 'MEI') {
                $required[] = 'iss_rate';
            }
            foreach ($required as $field) {
                if ($configuration->{$field} === null || $configuration->{$field} === '') {
                    $missing[] = $field;
                }
            }
        }

        return response()->json([
            'success' => true,
            'covered' => $configuration !== null,
            'complete' => $configuration !== null && $missing === [],
            'missing' => $missing,
            'configuration' => $configuration === null ? null : [
                'id' => $configuration->id,
                'provider' => $configuration->provider,
                'environment' => $configuration->environment,
                'municipality_code' => $configuration->municipality_code,
                'service_code' => $configuration->service_code,
                'tax_regime' => $configuration->tax_regime,
                'iss_rate' => $configuration->iss_rate,
            ],
            'provider_check' => $this->checkProviderCoverage($establishment),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    /**
     * Quando o estabelecimento tem credencial Asaas, confirma na fonte
     * (municipalOptions) se o município é atendido e se exige certificado.
     */
    private function checkProviderCoverage(string $establishmentExternalId): ?array
    {
        $account = FiscalAccount::where('establishment_external_id', $establishmentExternalId)
            ->where('active', true)
            ->first();

        if ($account === null || strtolower((string) $account->provider) !== 'asaas') {
            return null;
        }

        try {
            $options = (new AsaasFiscalProvider((string) $account->access_token, $account->base_url))
                ->municipalOptions();

            return [
                'provider' => 'asaas',
                'checked' => true,
                'authentication_type' => $options['authenticationType'] ?? null,
                'supports_cancellation' => $options['supportsCancellation'] ?? null,
                'requires_certificate' => strtoupper((string) ($options['authenticationType'] ?? '')) === 'CERTIFICATE',
            ];
        } catch (\Throwable) {
            return [
                'provider' => 'asaas',
                'checked' => true,
                'error' => 'Não foi possível confirmar a cobertura do município no Asaas.',
            ];
        }
    }
}
