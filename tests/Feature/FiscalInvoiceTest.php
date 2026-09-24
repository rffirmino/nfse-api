<?php

namespace Tests\Feature;

use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.hmac_secret' => 'test-secret']);
        config(['services.fiscal.provider' => 'manual']);
    }

    private function configuration(): FiscalConfiguration
    {
        return FiscalConfiguration::create([
            'establishment_external_id' => 'est-001',
            'municipality_code' => '2211001',
            'municipality_name' => 'Teresina',
            'provider' => 'manual',
            'environment' => 'restricted',
            'provider_registration' => '123',
            'tax_regime' => 'MEI',
            'service_code' => '6.02',
            'iss_rate' => 5.0,
            'active' => true,
        ]);
    }

    private function invoice(string $status = 'pending'): NfseInvoice
    {
        return NfseInvoice::create([
            'establishment_external_id' => 'est-001',
            'appointment_external_id' => 'apt-001',
            'customer_external_id' => 'cli-001',
            'provider' => 'manual',
            'status' => $status,
            'amount' => 150.00,
            'idempotency_key' => 'inv:' . uniqid(),
        ]);
    }

    public function test_manual_registration_authorizes_invoice(): void
    {
        $invoice = $this->invoice('pending');

        $response = $this->signedPost(
            '/api/v1/invoices/' . $invoice->id . '/manual',
            ['invoice_number' => '1234', 'protocol' => 'p-1', 'document_url' => 'https://x/pdf'],
            'manual:1'
        );

        $response->assertStatus(200)->assertJsonPath('status', 'authorized');
        $invoice->refresh();
        $this->assertSame('authorized', $invoice->status);
        $this->assertSame('1234', $invoice->invoice_number);
        $this->assertNotNull($invoice->issued_at);
        $this->assertDatabaseHas('nfse_invoice_events', [
            'invoice_id' => $invoice->id,
            'event' => 'manual.registered',
        ]);
    }

    public function test_cancel_keeps_history(): void
    {
        $invoice = $this->invoice('authorized');

        $this->signedPost('/api/v1/invoices/' . $invoice->id . '/cancel', ['reason' => 'Erro de emissão'], 'cancel:1')
            ->assertStatus(200)->assertJsonPath('status', 'cancelled');

        $invoice->refresh();
        $this->assertSame('cancelled', $invoice->status);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertDatabaseHas('nfse_invoice_events', [
            'invoice_id' => $invoice->id,
            'event' => 'invoice.cancelled',
        ]);
    }

    public function test_coverage_reports_covered_and_missing(): void
    {
        $this->configuration();

        $this->signedGet('/api/v1/fiscal/coverage?establishment_external_id=est-001&municipality_code=2211001&service_code=6.02')
            ->assertStatus(200)
            ->assertJsonPath('covered', true)
            ->assertJsonPath('complete', true);

        $this->signedGet('/api/v1/fiscal/coverage?establishment_external_id=est-001&municipality_code=3550308&service_code=6.02')
            ->assertStatus(200)
            ->assertJsonPath('covered', false);
    }

    public function test_coverage_mei_does_not_require_iss_rate(): void
    {
        FiscalConfiguration::create([
            'establishment_external_id' => 'est-mei',
            'municipality_code' => '2211001',
            'municipality_name' => 'Teresina',
            'provider' => 'manual',
            'environment' => 'restricted',
            'provider_registration' => '123',
            'tax_regime' => 'MEI',
            'service_code' => '6.02',
            'active' => true,
        ]);

        $this->signedGet('/api/v1/fiscal/coverage?establishment_external_id=est-mei&municipality_code=2211001&service_code=6.02')
            ->assertStatus(200)
            ->assertJsonPath('covered', true)
            ->assertJsonPath('complete', true)
            ->assertJsonPath('missing', []);
    }

    public function test_coverage_other_regime_requires_iss_rate(): void
    {
        FiscalConfiguration::create([
            'establishment_external_id' => 'est-sn',
            'municipality_code' => '2211001',
            'municipality_name' => 'Teresina',
            'provider' => 'manual',
            'environment' => 'restricted',
            'provider_registration' => '123',
            'tax_regime' => 'SIMPLES_NACIONAL',
            'service_code' => '6.02',
            'active' => true,
        ]);

        $this->signedGet('/api/v1/fiscal/coverage?establishment_external_id=est-sn&municipality_code=2211001&service_code=6.02')
            ->assertStatus(200)
            ->assertJsonPath('covered', true)
            ->assertJsonPath('complete', false);
    }

    public function test_history_lists_invoices(): void
    {
        $this->invoice('authorized');

        $this->signedGet('/api/v1/invoices?establishment_external_id=est-001')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 1);
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
