<?php

namespace Tests\Feature;

use App\Models\FiscalAccount;
use App\Models\FiscalConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigureEstablishmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_configuration_with_pilot_data(): void
    {
        $this->artisan('fiscal:establishment', [
            '--establishment' => 'qa-establishment-001',
            '--municipality-code' => '3509502',
            '--municipality-name' => 'Campinas',
            '--service-code' => '6.02',
            '--cnpj' => '68272117000168',
            '--inscricao-municipal' => '161150100118',
            '--tax-regime' => 'Simples Nacional',
            '--iss-rate' => '2.00',
            '--delivery' => 'whatsapp,email,download',
        ])->assertSuccessful();

        $this->assertDatabaseHas('fiscal_configurations', [
            'establishment_external_id' => 'qa-establishment-001',
            'municipality_code' => '3509502',
            'service_code' => '6.02',
            'provider_registration' => '68272117000168',
            'municipal_inscription' => '161150100118',
            'tax_regime' => 'Simples Nacional',
            'active' => true,
        ]);

        $configuration = FiscalConfiguration::firstOrFail();
        $this->assertSame('2.0000', $configuration->iss_rate);
        $this->assertSame(
            ['whatsapp' => true, 'email' => true, 'download' => true],
            $configuration->default_delivery_channels
        );
    }

    public function test_command_is_idempotent(): void
    {
        $options = [
            '--establishment' => 'qa-establishment-001',
            '--municipality-code' => '3509502',
            '--service-code' => '6.02',
            '--tax-regime' => 'Simples Nacional',
        ];

        $this->artisan('fiscal:establishment', $options)->assertSuccessful();
        $this->artisan('fiscal:establishment', $options + ['--iss-rate' => '2.00'])->assertSuccessful();

        $this->assertSame(1, FiscalConfiguration::count());
        $this->assertSame('2.0000', FiscalConfiguration::firstOrFail()->iss_rate);
    }

    public function test_command_registers_provider_credentials_encrypted(): void
    {
        $this->artisan('fiscal:establishment', [
            '--establishment' => 'qa-establishment-001',
            '--municipality-code' => '3509502',
            '--service-code' => '6.02',
            '--tax-regime' => 'Simples Nacional',
            '--provider' => 'asaas',
            '--token' => 'tok-sandbox-123',
            '--base-url' => 'https://api-sandbox.asaas.com/v3',
        ])->assertSuccessful();

        $account = FiscalAccount::firstOrFail();
        $this->assertSame('asaas', $account->provider);
        $this->assertSame('tok-sandbox-123', $account->access_token);
        $this->assertSame('https://api-sandbox.asaas.com/v3', $account->base_url);
        $this->assertArrayNotHasKey('access_token', $account->toArray());
    }

    public function test_command_requires_establishment_and_municipality(): void
    {
        $this->artisan('fiscal:establishment', [])->assertFailed();
        $this->assertSame(0, FiscalConfiguration::count());
    }

    public function test_command_delivery_accepts_all(): void
    {
        $this->artisan('fiscal:establishment', [
            '--establishment' => 'est-001',
            '--municipality-code' => '3509502',
            '--service-code' => '6.02',
            '--delivery' => 'all',
        ])->assertSuccessful();

        $this->assertSame(
            ['whatsapp' => true, 'email' => true, 'download' => true],
            FiscalConfiguration::firstOrFail()->default_delivery_channels
        );
    }

    public function test_doctor_fails_and_points_out_missing_email_and_whatsapp(): void
    {
        config([
            'mail.default' => 'log',
            'services.whatsapp.provider' => 'fake',
            'services.whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.nfse_delivery.whatsapp_template' => '',
            'services.nfse_delivery.email_from' => 'hello@example.com',
        ]);

        $this->artisan('nfse:doctor')->assertFailed();

        $this->artisan('nfse:doctor')
            ->expectsOutputToContain('MAIL_MAILER')
            ->expectsOutputToContain('META_ACCESS_TOKEN')
            ->expectsOutputToContain('NFS-e')
            ->assertFailed();
    }

    public function test_doctor_passes_when_delivery_is_configured(): void
    {
        FiscalConfiguration::create([
            'establishment_external_id' => 'qa-establishment-001',
            'municipality_code' => '3509502',
            'municipality_name' => 'Campinas',
            'provider' => 'asaas',
            'environment' => 'restricted',
            'provider_registration' => '68272117000168',
            'municipal_inscription' => '161150100118',
            'tax_regime' => 'Simples Nacional',
            'iss_rate' => '2.00',
            'service_code' => '6.02',
            'default_delivery_channels' => ['whatsapp' => true, 'email' => true, 'download' => true],
            'active' => true,
        ]);

        config([
            'mail.default' => 'smtp',
            'services.whatsapp.provider' => 'meta',
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123456',
            'services.nfse_delivery.whatsapp_template' => 'nfse_disponivel',
            'services.nfse_delivery.email_from' => 'contato@seudominio.com',
        ]);

        $this->artisan('nfse:doctor')->assertSuccessful();
    }
}