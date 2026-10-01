<?php

namespace App\Console\Commands;

use App\Models\FiscalAccount;
use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use Illuminate\Console\Command;

/**
 * Cadastro fiscal self-service: o próprio cliente registra o estabelecimento
 * (credencial do provedor + configuração fiscal por município/serviço) sem
 * depender de ninguém da operação.
 */
class ConfigureEstablishment extends Command
{
    protected $signature = 'fiscal:establishment
        {--establishment= : establishment_external_id (obrigatório)}
        {--municipality-code= : Código IBGE do município de incidência (obrigatório)}
        {--municipality-name= : Nome do município}
        {--service-code= : Código de serviço (LC 116, ex.: 6.02)}
        {--cnpj= : CNPJ/CPF do prestador (provider_registration)}
        {--inscricao-municipal= : Inscrição municipal do prestador}
        {--tax-regime= : Regime tributário (ex.: MEI, Simples Nacional)}
        {--iss-rate= : Alíquota de ISS (%) — não usar em MEI}
        {--cnae= : CNAE do prestador}
        {--operation-nature= : Natureza da operação}
        {--provider= : Provedor fiscal (asaas | nfse_nacional | manual)}
        {--environment= : restricted | production}
        {--token= : Token do provedor (Asaas) — cria/atualiza a credencial}
        {--base-url= : Base URL do provedor (ex.: https://api-sandbox.asaas.com/v3)}
        {--delivery= : Canais padrão de entrega (whatsapp,email,download)}
        {--inactive : Cadastra sem ativar}';

    protected $description = 'Cadastra/atualiza a configuração fiscal de um estabelecimento (e a credencial do provedor)';

    public function handle(): int
    {
        $establishment = (string) $this->option('establishment');
        $municipalityCode = (string) $this->option('municipality-code');

        if ($establishment === '' || $municipalityCode === '') {
            $this->error('Informe --establishment e --municipality-code.');

            return self::FAILURE;
        }

        $provider = strtolower((string) ($this->option('provider') ?: 'nfse_nacional'));
        $serviceCode = (string) ($this->option('service-code') ?: '');
        $taxRegime = (string) ($this->option('tax-regime') ?: '');

        $channels = $this->deliveryChannels();

        $configuration = FiscalConfiguration::updateOrCreate(
            [
                'establishment_external_id' => $establishment,
                'municipality_code' => $municipalityCode,
                'service_code' => $serviceCode,
            ],
            [
                'municipality_name' => (string) ($this->option('municipality-name') ?: $municipalityCode),
                'provider' => $provider,
                'environment' => (string) ($this->option('environment') ?: 'restricted'),
                'provider_registration' => $this->option('cnpj') ?: null,
                'municipal_inscription' => $this->option('inscricao-municipal') ?: null,
                'tax_regime' => $taxRegime,
                'cnae' => $this->option('cnae') ?: null,
                'operation_nature' => $this->option('operation-nature') ?: null,
                'iss_rate' => $this->option('iss-rate') ?: null,
                'issue_on_payment' => true,
                'active' => !$this->option('inactive'),
                'default_delivery_channels' => $channels === [] ? null : $channels,
            ]
        );

        $this->info("Configuração fiscal salva: {$configuration->id} ({$establishment} / {$municipalityCode} / {$configuration->service_code}).");

        if ($taxRegime !== '' && strtoupper($taxRegime) !== 'MEI' && $configuration->iss_rate === null) {
            $this->warn('Atenção: regimes diferentes de MEI exigem --iss-rate para cobertura completa.');
        }

        if ($channels !== []) {
            $this->line('Canais de entrega padrão: ' . implode(', ', array_keys(array_filter($channels))));
        }

        $token = (string) ($this->option('token') ?: '');
        if ($token !== '') {
            $account = FiscalAccount::updateOrCreate(
                ['establishment_external_id' => $establishment],
                [
                    'provider' => $provider,
                    'access_token' => $token,
                    'base_url' => (string) ($this->option('base-url') ?: config('services.asaas.base_url')),
                    'environment' => (string) ($this->option('environment') ?: 'sandbox'),
                    'active' => true,
                ]
            );

            $this->info("Credencial {$account->provider} salva para {$establishment} (token criptografado).");
        }

        $pending = NfseInvoice::where('establishment_external_id', $establishment)
            ->where('status', 'pending')
            ->count();
        if ($pending > 0) {
            $this->line("Existem {$pending} nota(s) pendentes deste estabelecimento — reexecute a emissão ou use: php artisan fiscal:reconcile");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, bool>
     */
    private function deliveryChannels(): array
    {
        $channels = ['whatsapp' => false, 'email' => false, 'download' => false];
        $input = strtolower(trim((string) ($this->option('delivery') ?: '')));

        if ($input === '') {
            return [];
        }

        if ($input === 'all' || $input === 'todos') {
            return array_map(static fn (): bool => true, $channels);
        }

        foreach (explode(',', $input) as $channel) {
            $channel = trim($channel);
            if (array_key_exists($channel, $channels)) {
                $channels[$channel] = true;
            }
        }

        return $channels;
    }
}