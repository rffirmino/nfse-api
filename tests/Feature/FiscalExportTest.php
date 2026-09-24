<?php

namespace Tests\Feature;

use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.hmac_secret' => 'test-secret']);
    }

    private function seedData(): void
    {
        FiscalConfiguration::create([
            'establishment_external_id' => 'est-001',
            'municipality_code' => '2211001',
            'municipality_name' => 'Teresina',
            'provider' => 'asaas',
            'environment' => 'sandbox',
            'provider_registration' => '123',
            'tax_regime' => 'MEI',
            'service_code' => '8.02',
            'active' => true,
        ]);
        NfseInvoice::create([
            'establishment_external_id' => 'est-001',
            'appointment_external_id' => 'apt-1',
            'customer_external_id' => 'cli-1',
            'provider' => 'asaas',
            'status' => 'authorized',
            'amount' => 50.00,
            'idempotency_key' => 'exp:1',
        ]);
    }

    public function test_export_json_and_csv(): void
    {
        $this->seedData();

        $this->signedGet('/api/v1/fiscal/export?format=json&establishment_external_id=est-001')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoices.0.establishment_external_id', 'est-001')
            ->assertJsonPath('configurations.0.service_code', '8.02');

        $csv = $this->signedGet('/api/v1/fiscal/export?format=csv&establishment_external_id=est-001');
        $csv->assertStatus(200);
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
    }

    public function test_export_command_writes_file(): void
    {
        $this->seedData();
        $path = storage_path('app/test-export.json');
        @unlink($path);

        $this->artisan('fiscal:export', ['--path' => $path])->assertSuccessful();

        $this->assertFileExists($path);
        $data = json_decode(file_get_contents($path), true);
        $this->assertSame(1, count($data['invoices']));
        @unlink($path);
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
