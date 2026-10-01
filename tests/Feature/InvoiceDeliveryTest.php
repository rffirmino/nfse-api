<?php

namespace Tests\Feature;

use App\Jobs\DeliverNfseInvoice;
use App\Mail\NfseInvoiceMail;
use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use App\Services\InvoiceDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvoiceDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.hmac_secret' => 'test-secret']);
        config(['services.fiscal.provider' => 'manual']);
        config(['services.whatsapp.provider' => 'fake']);
        config(['services.nfse_delivery.whatsapp_template' => '']);
        config(['mail.default' => 'array']);
    }

    private function configuration(array $attributes = []): FiscalConfiguration
    {
        return FiscalConfiguration::create(array_merge([
            'establishment_external_id' => 'est-001',
            'municipality_code' => '3509502',
            'municipality_name' => 'Campinas',
            'provider' => 'manual',
            'environment' => 'restricted',
            'provider_registration' => '68272117000168',
            'tax_regime' => 'Simples Nacional',
            'service_code' => '6.02',
            'iss_rate' => 2.0,
            'active' => true,
        ], $attributes));
    }

    private function invoice(array $channels = [], string $status = 'pending', array $attributes = []): NfseInvoice
    {
        return NfseInvoice::create(array_merge([
            'establishment_external_id' => 'est-001',
            'appointment_external_id' => 'apt-001',
            'customer_external_id' => 'cli-001',
            'provider' => 'manual',
            'status' => $status,
            'amount' => 150.00,
            'invoice_number' => $status === 'authorized' ? '1234' : null,
            'document_url' => $status === 'authorized' ? 'https://fiscal.test/nfse/1234.pdf' : null,
            'customer_name' => 'Maria Silva',
            'customer_phone' => '11999999999',
            'customer_email' => 'maria@exemplo.com',
            'idempotency_key' => 'inv:' . uniqid(),
            'delivery_channels' => $channels,
            'delivery_status' => $channels === [] ? 'not_requested' : 'pending',
        ], $attributes));
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
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => 'test-client',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => 'doc:1',
        ]);
    }

    public function test_post_registers_customer_and_delivery_channels(): void
    {
        $this->configuration();

        $response = $this->signedPost('/api/v1/invoices', [
            'establishment_external_id' => 'est-001',
            'municipality_code' => '3509502',
            'service_code' => '6.02',
            'appointment_external_id' => 'apt-001',
            'customer_external_id' => 'cli-001',
            'amount' => '150.00',
            'customer' => ['name' => 'Maria', 'phone' => '11999999999', 'email' => 'maria@exemplo.com', 'cpf_cnpj' => '12345678909'],
            'delivery' => ['whatsapp' => true, 'email' => true, 'download' => true],
        ], 'nfse:appointment:apt-001');

        $response->assertStatus(202);
        $invoice = NfseInvoice::firstOrFail();
        $this->assertSame('Maria', $invoice->customer_name);
        $this->assertSame(['whatsapp' => true, 'email' => true, 'download' => true], $invoice->requestedDeliveryChannels());
        $this->assertSame('pending', $invoice->delivery_status);
        // O provider fiscal (Asaas) lê o tomador no topo do payload.
        $this->assertSame('12345678909', $invoice->request_payload['customer_cpf_cnpj']);
        $this->assertSame('Maria', $invoice->request_payload['customer_name']);
    }

    public function test_post_without_delivery_is_not_requested_by_default(): void
    {
        $this->configuration();

        $this->signedPost('/api/v1/invoices', [
            'establishment_external_id' => 'est-001',
            'municipality_code' => '3509502',
            'service_code' => '6.02',
            'appointment_external_id' => 'apt-002',
            'customer_external_id' => 'cli-002',
            'amount' => '90.00',
        ], 'nfse:appointment:apt-002')->assertStatus(202);

        $invoice = NfseInvoice::firstOrFail();
        $this->assertFalse($invoice->hasRequestedDelivery());
        $this->assertSame('not_requested', $invoice->delivery_status);
    }

    public function test_post_uses_configuration_default_when_delivery_is_omitted(): void
    {
        $this->configuration([
            'default_delivery_channels' => ['whatsapp' => true, 'email' => false, 'download' => true],
        ]);

        $this->signedPost('/api/v1/invoices', [
            'establishment_external_id' => 'est-001',
            'municipality_code' => '3509502',
            'service_code' => '6.02',
            'appointment_external_id' => 'apt-003',
            'customer_external_id' => 'cli-003',
            'amount' => '90.00',
        ], 'nfse:appointment:apt-003')->assertStatus(202);

        $invoice = NfseInvoice::firstOrFail();
        $this->assertTrue($invoice->requestedDeliveryChannels()['whatsapp']);
        $this->assertFalse($invoice->requestedDeliveryChannels()['email']);
        $this->assertSame('pending', $invoice->delivery_status);
    }

    public function test_manual_authorization_dispatches_delivery_job(): void
    {
        Bus::fake();
        $this->configuration();
        $invoice = $this->invoice(['whatsapp' => true, 'email' => true, 'download' => true]);

        $this->signedPost('/api/v1/invoices/' . $invoice->id . '/manual', [
            'invoice_number' => '1234',
            'document_url' => 'https://fiscal.test/nfse/1234.pdf',
        ], 'manual:1')->assertStatus(200);

        Bus::assertDispatched(DeliverNfseInvoice::class);
    }

    public function test_authorized_invoice_is_delivered_by_whatsapp_and_email(): void
    {
        Queue::fake();
        Mail::fake();

        $invoice = $this->invoice(['whatsapp' => true, 'email' => true, 'download' => true], 'authorized');
        $updated = (new InvoiceDeliveryService())->deliver($invoice->id);

        $this->assertSame('sent', $updated->delivery_status);
        $this->assertNotNull($updated->delivered_at);
        $this->assertNull($updated->delivery_errors);

        $this->assertDatabaseHas('whatsapp_messages', [
            'idempotency_key' => "nfse-delivery:{$invoice->id}:whatsapp",
            'recipient_phone' => '+5511999999999',
            'appointment_external_id' => 'apt-001',
        ]);
        Mail::assertSent(NfseInvoiceMail::class, fn ($mail) => $mail->hasTo('maria@exemplo.com'));
        Queue::assertPushed(\App\Jobs\SendWhatsAppMessage::class);
    }

    public function test_delivery_waits_for_authorization(): void
    {
        Queue::fake();
        Mail::fake();

        $invoice = $this->invoice(['whatsapp' => true, 'email' => true], 'pending');
        $updated = (new InvoiceDeliveryService())->deliver($invoice->id);

        $this->assertSame('pending', $updated->delivery_status);
        $this->assertSame(0, $updated->delivery_attempts);
        $this->assertDatabaseCount('whatsapp_messages', 0);
        Mail::assertNothingSent();
    }

    public function test_delivery_is_skipped_when_no_channel_requested(): void
    {
        Mail::fake();
        $invoice = $this->invoice([], 'authorized');

        $updated = (new InvoiceDeliveryService())->deliver($invoice->id);

        $this->assertSame('not_requested', $updated->delivery_status);
        Mail::assertNothingSent();
    }

    public function test_download_only_channel_sends_nothing(): void
    {
        Queue::fake();
        Mail::fake();

        $invoice = $this->invoice(['download' => true], 'authorized');
        $updated = (new InvoiceDeliveryService())->deliver($invoice->id);

        $this->assertSame('sent', $updated->delivery_status);
        $this->assertDatabaseCount('whatsapp_messages', 0);
        Mail::assertNothingSent();
    }

    public function test_channel_failures_are_recorded_without_blocking_the_others(): void
    {
        Queue::fake();
        Mail::fake();

        $invoice = $this->invoice(['whatsapp' => true, 'email' => true], 'authorized', [
            'customer_phone' => null,
        ]);

        $updated = (new InvoiceDeliveryService())->deliver($invoice->id);

        $this->assertSame('partial', $updated->delivery_status);
        $this->assertArrayHasKey('whatsapp', $updated->delivery_errors);
        Mail::assertSent(NfseInvoiceMail::class);
    }

    public function test_email_channel_fails_when_mailer_is_not_configured(): void
    {
        Queue::fake();
        config(['mail.default' => 'log']);

        $invoice = $this->invoice(['email' => true], 'authorized');
        $updated = (new InvoiceDeliveryService())->deliver($invoice->id);

        $this->assertSame('failed', $updated->delivery_status);
        $this->assertStringContainsString('MAIL_MAILER', (string) $updated->delivery_errors['email']);
    }

    public function test_document_endpoint_requires_download_channel(): void
    {
        $this->configuration();
        $invoice = $this->invoice(['whatsapp' => true], 'authorized');

        $this->signedGet('/api/v1/invoices/' . $invoice->id . '/document')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'download_not_enabled');
    }

    public function test_document_endpoint_returns_document_when_download_enabled(): void
    {
        $this->configuration();
        $invoice = $this->invoice(['download' => true], 'authorized', ['xml' => '<NFe/>']);

        $this->signedGet('/api/v1/invoices/' . $invoice->id . '/document')
            ->assertStatus(200)
            ->assertJsonPath('invoice_number', '1234')
            ->assertJsonPath('document_url', 'https://fiscal.test/nfse/1234.pdf')
            ->assertJsonPath('xml', '<NFe/>');
    }

    public function test_document_endpoint_returns_conflict_when_not_authorized(): void
    {
        $this->configuration();
        $invoice = $this->invoice(['download' => true], 'pending');

        $this->signedGet('/api/v1/invoices/' . $invoice->id . '/document')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'document_unavailable');
    }

    public function test_show_exposes_delivery_information(): void
    {
        $this->configuration();
        $invoice = $this->invoice(['whatsapp' => true, 'download' => true], 'authorized');

        $this->signedGet('/api/v1/invoices/' . $invoice->id)
            ->assertStatus(200)
            ->assertJsonPath('delivery_channels.whatsapp', true)
            ->assertJsonPath('delivery_channels.email', false)
            ->assertJsonPath('has_document', true);
    }
}