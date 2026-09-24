<?php

namespace Tests\Feature;

use App\Fiscal\AsaasFiscalProvider;
use App\Fiscal\FakeFiscalProvider;
use App\Models\FiscalAccount;
use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use App\Services\InvoiceIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsaasFiscalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.hmac_secret' => 'test-secret']);
        config(['services.asaas.base_url' => 'https://asaas.test']);
        config(['services.asaas.timeout' => 5]);
    }

    private function invoice(string $provider = 'asaas', array $requestPayload = []): NfseInvoice
    {
        return NfseInvoice::create([
            'establishment_external_id' => 'est-001',
            'appointment_external_id' => 'apt-001',
            'customer_external_id' => 'cli-001',
            'provider' => $provider,
            'status' => 'pending',
            'amount' => 150.00,
            'idempotency_key' => 'asaas:' . uniqid(),
            'request_payload' => $requestPayload + [
                'service_code' => '6.02',
                'asaas_customer_id' => 'cus_123',
            ],
        ]);
    }

    public function test_provider_issues_invoice_and_maps_status(): void
    {
        Http::fake(['https://asaas.test/v3/invoices' => Http::response([
            'id' => 'inv_1', 'status' => 'AUTHORIZED', 'number' => '123',
        ], 200)]);

        $result = (new AsaasFiscalProvider('tok-asaas'))->issueInvoice($this->invoice());

        $this->assertSame('authorized', $result->status);
        $this->assertSame('inv_1', $result->externalId);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/invoices')
            && $request->hasHeader('Authorization', 'Bearer tok-asaas')
            && $request['municipalServiceCode'] === '6.02'
            && $request['customer'] === 'cus_123');
    }

    public function test_provider_maps_scheduled_to_processing(): void
    {
        Http::fake(['https://asaas.test/v3/invoices' => Http::response([
            'id' => 'inv_2', 'status' => 'SCHEDULED',
        ], 200)]);

        $this->assertSame('processing', (new AsaasFiscalProvider('tok'))->issueInvoice($this->invoice())->status);
    }

    public function test_provider_creates_customer_when_missing(): void
    {
        Http::fake([
            'https://asaas.test/v3/customers' => Http::response(['id' => 'cus_novo'], 200),
            'https://asaas.test/v3/invoices' => Http::response(['id' => 'inv_3', 'status' => 'AUTHORIZED'], 200),
        ]);

        $invoice = $this->invoice('asaas', [
            'asaas_customer_id' => null,
            'customer_name' => 'Maria',
            'customer_cpf_cnpj' => '123.456.789-09',
        ]);
        // remove o id nulo para forçar a criação
        $invoice->request_payload = ['service_code' => '6.02', 'customer_name' => 'Maria', 'customer_cpf_cnpj' => '12345678909'];
        $invoice->save();

        $result = (new AsaasFiscalProvider('tok'))->issueInvoice($invoice->fresh());
        $this->assertSame('authorized', $result->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/customers'));
    }

    public function test_issuance_service_uses_establishment_asaas_account(): void
    {
        FiscalAccount::create([
            'establishment_external_id' => 'est-001',
            'provider' => 'asaas',
            'access_token' => 'token-subconta',
            'base_url' => 'https://asaas.test',
            'active' => true,
        ]);

        Http::fake(['https://asaas.test/v3/invoices' => Http::response([
            'id' => 'inv_sub', 'status' => 'AUTHORIZED', 'number' => '9',
        ], 200)]);

        $invoice = $this->invoice('asaas');

        (new InvoiceIssuanceService(new FakeFiscalProvider()))->issue($invoice->id);

        $invoice->refresh();
        $this->assertSame('authorized', $invoice->status);
        $this->assertSame('inv_sub', $invoice->external_id);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-subconta'));
    }

    public function test_fiscal_account_token_is_encrypted(): void
    {
        $account = FiscalAccount::create([
            'establishment_external_id' => 'est-001',
            'provider' => 'asaas',
            'access_token' => 'segredo',
            'active' => true,
        ]);

        $raw = DB::table('fiscal_accounts')->where('id', $account->id)->value('access_token');
        $this->assertNotSame('segredo', $raw);
        $this->assertSame('segredo', $account->fresh()->access_token);
    }

    public function test_fiscal_accounts_endpoint_and_coverage_provider_check(): void
    {
        Http::fake(['https://asaas.test/v3/fiscalInfo/municipalOptions' => Http::response([
            'authenticationType' => 'USER_AND_PASSWORD',
            'supportsCancellation' => true,
        ], 200)]);

        $this->signedPost('/api/v1/fiscal/accounts', [
            'establishment_external_id' => 'est-001',
            'access_token' => 'tok',
        ], 'acc:1')->assertStatus(200)->assertJsonPath('success', true);

        $this->signedGet('/api/v1/fiscal/accounts')
            ->assertStatus(200)
            ->assertJsonPath('items.0.establishment_external_id', 'est-001')
            ->assertJsonMissingPath('items.0.access_token');

        FiscalConfiguration::create([
            'establishment_external_id' => 'est-001',
            'municipality_code' => '2211001',
            'municipality_name' => 'Teresina',
            'provider' => 'asaas',
            'environment' => 'sandbox',
            'provider_registration' => '123',
            'tax_regime' => 'MEI',
            'service_code' => '6.02',
            'active' => true,
        ]);

        $this->signedGet('/api/v1/fiscal/coverage?establishment_external_id=est-001&municipality_code=2211001&service_code=6.02')
            ->assertStatus(200)
            ->assertJsonPath('covered', true)
            ->assertJsonPath('provider_check.checked', true)
            ->assertJsonPath('provider_check.requires_certificate', false);
    }

    public function test_asaas_webhook_updates_invoice_idempotently(): void
    {
        config(['services.asaas.webhook_token' => 'wh-token']);

        $invoice = NfseInvoice::create([
            'establishment_external_id' => 'est-001',
            'appointment_external_id' => 'apt-1',
            'customer_external_id' => 'cli-1',
            'provider' => 'asaas',
            'status' => 'processing',
            'amount' => 100.00,
            'external_id' => 'inv_1',
            'idempotency_key' => 'wh:1',
        ]);

        $body = [
            'event' => 'INVOICE_AUTHORIZED',
            'invoice' => ['id' => 'inv_1', 'status' => 'AUTHORIZED', 'number' => '10'],
        ];
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ASAAS_ACCESS_TOKEN' => 'wh-token'];

        $this->call('POST', '/api/v1/fiscal/webhook/asaas', [], [], [], $headers, json_encode($body))->assertStatus(200);
        // reenvio não duplica
        $this->call('POST', '/api/v1/fiscal/webhook/asaas', [], [], [], $headers, json_encode($body))->assertStatus(200);

        $invoice->refresh();
        $this->assertSame('authorized', $invoice->status);
        $this->assertNotNull($invoice->issued_at);
        $this->assertSame(1, \App\Models\FiscalEvent::where('invoice_external_id', 'inv_1')->count());
    }

    public function test_asaas_webhook_rejects_invalid_token(): void
    {
        config(['services.asaas.webhook_token' => 'wh-token']);

        $this->call('POST', '/api/v1/fiscal/webhook/asaas', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ASAAS_ACCESS_TOKEN' => 'errado',
        ], json_encode(['event' => 'INVOICE_AUTHORIZED']))->assertStatus(401);
    }

    public function test_reconciliation_command_updates_processing_invoice(): void
    {
        FiscalAccount::create([
            'establishment_external_id' => 'est-001',
            'provider' => 'asaas',
            'access_token' => 'tok',
            'base_url' => 'https://asaas.test',
            'active' => true,
        ]);

        $invoice = NfseInvoice::create([
            'establishment_external_id' => 'est-001',
            'appointment_external_id' => 'apt-2',
            'customer_external_id' => 'cli-2',
            'provider' => 'asaas',
            'status' => 'processing',
            'amount' => 90.00,
            'external_id' => 'inv_rec',
            'idempotency_key' => 'rec:1',
        ]);
        // Envelhece o registro para o worker considerar (updated_at > 5 min).
        DB::table('nfse_invoices')->where('id', $invoice->id)->update(['updated_at' => now()->subMinutes(10)]);

        Http::fake(['https://asaas.test/v3/invoices/inv_rec' => Http::response([
            'id' => 'inv_rec', 'status' => 'AUTHORIZED', 'number' => '77',
        ], 200)]);

        $this->artisan('fiscal:reconcile')->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('authorized', $invoice->status);
        $this->assertNotNull($invoice->issued_at);
    }

    public function test_fallback_to_manual_when_asaas_credential_missing(): void
    {
        // Sem FiscalAccount: deve cair no ManualFiscalProvider (erro orientando registro manual),
        // em vez de quebrar a emissão.
        $invoice = $this->invoice('asaas');

        (new InvoiceIssuanceService(new FakeFiscalProvider()))->issue($invoice->id);

        $invoice->refresh();
        $this->assertSame('error', $invoice->status);
        $this->assertStringContainsStringIgnoringCase('manual', (string) $invoice->error_message);
    }

    private function signedPost(string $uri, array $payload, string $idempotency): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $nonce = uniqid('nonce-', true);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, 'test-secret');

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => 'test-client',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => $idempotency,
        ], $body);
    }

    private function signedGet(string $uri): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $nonce = uniqid('nonce-', true);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n", 'test-secret');

        return $this->call('GET', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => 'test-client',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => 'get:' . $nonce,
        ]);
    }
}
