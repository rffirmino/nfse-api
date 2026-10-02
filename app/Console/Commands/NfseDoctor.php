<?php

namespace App\Console\Commands;

use App\Models\FiscalAccount;
use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnóstico de prontidão da entrega da NFS-e (WhatsApp, e-mail e download).
 *
 * Existe para responder, antes do cliente homologar, "o que ainda falta":
 *   - credenciais da Meta e template aprovado (fora da janela de 24h);
 *   - SMTP de verdade (com MAIL_MAILER=log o e-mail sempre falha);
 *   - configuração fiscal e credencial do provedor por estabelecimento;
 *   - fila/cron nécessaires para entregar.
 *
 * Nunca imprime segredos: mostra apenas se estão preenchidos.
 */
class NfseDoctor extends Command
{
    protected $signature = 'nfse:doctor
        {--probe : Testa de verdade as conexões (Meta, SMTP) em vez de só ler o .env}';

    protected $description = 'Diagnostica a configuração de entrega da NFS-e (WhatsApp, e-mail, download) e o que falta para homologar';

    private int $blockers = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->components->info('Diagnóstico de entrega da NFS-e');
        $this->line('');

        $this->checkEmail();
        $this->checkWhatsApp();
        $this->checkFiscalConfigurations();
        $this->checkQueue();
        $this->checkIntegrations();

        $this->line('');
        if ($this->blockers > 0 || $this->warnings > 0) {
            $this->components->warn($this->blockers . ' bloqueio(s) e ' . $this->warnings . ' aviso(s).');
        } else {
            $this->components->info('Tudo pronto para a homologação.');
        }

        return $this->blockers > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkOk(string $label, string $detail = ''): void
    {
        $this->components->twoColumnDetail($label, $detail !== '' ? $detail : '<fg=green>ok</>');
    }

    private function checkWarn(string $label, string $advice): void
    {
        $this->warnings++;
        $this->components->twoColumnDetail($label, '<fg=yellow>' . $advice . '</>');
    }

    private function checkBlock(string $label, string $advice): void
    {
        $this->blockers++;
        $this->components->twoColumnDetail($label, '<fg=red>' . $advice . '</>');
    }

    /**
     * Canal de e-mail: sem SMTP real o canal falha de propósito.
     */
    private function checkEmail(): void
    {
        $this->components->twoColumnDetail('<fg=cyan>E-mail (canal nfse_entrega_email)</>', '');

        $mailer = (string) config('mail.default', 'log');

        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            $this->checkBlock('MAIL_MAILER', '"' . $mailer . '" não envia nada: configure smtp (ou ses/sendgrid).');
        } else {
            $this->checkOk('MAIL_MAILER', $mailer);
        }

        $from = (string) config('services.nfse_delivery.email_from', '');
        if ($from === '' || str_contains($from, 'example.com')) {
            $this->checkWarn('NFSE_EMAIL_FROM', 'vazio ou exemplo: o e-mail sai sem remetente real.');
        } else {
            $this->checkOk('NFSE_EMAIL_FROM', $from);
        }

        if (in_array(config('mail.mailers.' . $mailer . '.host'), [null, '', '127.0.0.1', 'localhost'], true)
            && ! $this->option('probe')) {
            $this->checkWarn('SMTP host', 'aponta para localhost; se o envio roda em outro host, ajuste MAIL_HOST.');
        }

        if ($this->option('probe') && ! in_array($mailer, ['log', 'array', 'null'], true)) {
            $this->probeSmtp($mailer);
        }
    }

    /**
     * Testa de verdade se o servidor SMTP responde (sem enviar e-mail).
     */
    private function probeSmtp(string $mailer): void
    {
        $host = (string) config('mail.mailers.' . $mailer . '.host', '');
        $port = (int) config('mail.mailers.' . $mailer . '.port', 587);

        if ($host === '') {
            $this->checkBlock('SMTP', 'MAIL_HOST vazio.');

            return;
        }

        $socket = @fsockopen($host, $port, $errno, $error, 5);

        if ($socket === false) {
            $this->checkBlock('SMTP ' . $host . ':' . $port, 'não conecta (' . ($error ?: 'erro ' . $errno) . ').');

            return;
        }

        $greeting = trim((string) fgets($socket, 512));
        fclose($socket);

        $this->checkOk('SMTP ' . $host . ':' . $port, $greeting !== '' ? $greeting : 'conectou');
    }

    /**
     * Canal de WhatsApp: token da Meta e template aprovado.
     */
    private function checkWhatsApp(): void
    {
        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan>WhatsApp (canal nfse_entrega_whatsapp)</>', '');

        $provider = (string) config('services.whatsapp.provider', 'fake');
        $token = (string) config('services.whatsapp.access_token', '');
        $phoneId = (string) config('services.whatsapp.phone_number_id', '');
        $template = (string) config('services.nfse_delivery.whatsapp_template', '');

        if ($provider === 'fake') {
            $this->checkBlock('WHATSAPP_PROVIDER', 'fake não envia nada: use meta (Cloud API) ou zapi.');
        } else {
            $this->checkOk('WHATSAPP_PROVIDER', $provider);
        }

        if ($token === '') {
            $this->checkBlock('META_ACCESS_TOKEN', 'vazio (token de acesso da Cloud API).');
        } else {
            $this->checkOk('META_ACCESS_TOKEN', 'preenchido');
        }

        if ($phoneId === '') {
            $this->checkBlock('META_PHONE_NUMBER_ID', 'vazio (id do número que envia).');
        } else {
            $this->checkOk('META_PHONE_NUMBER_ID', $phoneId);
        }

        if ($template === '') {
            $this->checkWarn('NFSE_WHATSAPP_TEMPLATE', 'vazio: a Meta só permite texto livre na janela de 24h.');
        } else {
            $this->checkOk('NFSE_WHATSAPP_TEMPLATE', $template . ' (' . config('services.nfse_delivery.whatsapp_template_language') . ')');
        }

        if ($this->option('probe') && $token !== '' && $phoneId !== '') {
            $base = rtrim((string) config('services.whatsapp.graph_base_url'), '/') . '/' . config('services.whatsapp.graph_version');

            try {
                $response = Http::withToken($token)->timeout(10)
                    ->get($base . '/' . $phoneId, ['fields' => 'display_phone_number,verified_name']);

                if ($response->successful()) {
                    $data = $response->json();
                    $this->checkOk('Meta Cloud API', ($data['verified_name'] ?? '') . ' ' . ($data['display_phone_number'] ?? ''));
                } else {
                    $this->checkBlock('Meta Cloud API', 'HTTP ' . $response->status() . ': ' . substr((string) $response->body(), 0, 180));
                }
            } catch (\Throwable $exception) {
                $this->checkBlock('Meta Cloud API', $exception->getMessage());
            }
        }
    }

    /**
     * Configuração fiscal e credencial do provedor por estabelecimento.
     */
    private function checkFiscalConfigurations(): void
    {
        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan>Estabelecimentos fiscais</>', '');

        $configurations = FiscalConfiguration::orderBy('establishment_external_id')->get();

        if ($configurations->isEmpty()) {
            $this->checkBlock('fiscal_configurations', 'nenhum cadastro: rode php artisan fiscal:establishment.');

            return;
        }

        foreach ($configurations as $configuration) {
            $channels = (array) ($configuration->default_delivery_channels ?: []);
            $enabled = array_keys(array_filter($channels));

            $this->line('  <fg=gray>' . $configuration->establishment_external_id . '</>');
            $this->components->twoColumnDetail(
                '  município/serviço',
                $configuration->municipality_code . ' / ' . $configuration->service_code
                    . ' (' . $configuration->provider_registration . ')'
                    . ($configuration->municipal_inscription ? ' IM ' . $configuration->municipal_inscription : ' sem IM')
            );
            $this->components->twoColumnDetail(
                '  regime/alíquota',
                trim($configuration->tax_regime . ' ' . ($configuration->iss_rate !== null ? $configuration->iss_rate . '%' : '')) ?: '—'
            );
            $this->components->twoColumnDetail(
                '  entrega padrão',
                $enabled === [] ? '<fg=yellow>nenhum (default-deny)</>' : implode(', ', $enabled)
            );

            $account = FiscalAccount::where('establishment_external_id', $configuration->establishment_external_id)
                ->where('active', true)
                ->first();

            if ($account === null) {
                $this->checkWarn('  credencial', 'sem conta fiscal ativa: a emissão cai para manual.');
            } else {
                $this->components->twoColumnDetail(
                    '  credencial',
                    $account->provider . ' @ ' . $account->base_url . ' (token ' . ($account->access_token ? 'preenchido' : '<fg=red>vazio</>') . ')'
                );
            }
        }
    }

    /**
     * Fila e cron: sem worker a entrega fica parada em "sending".
     */
    private function checkQueue(): void
    {
        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan>Fila e agendamento</>', '');

        $connection = (string) config('queue.default', 'sync');

        if ($connection === 'sync') {
            $this->checkWarn('QUEUE_CONNECTION', 'sync executa tudo no request: use redis ou database em produção.');
        } else {
            $this->checkOk('QUEUE_CONNECTION', $connection);
        }

        $this->checkOk('Rotinas em routes/console.php', 'fiscal:reconcile (5 min) e nfse:deliver --retry-failed (10 min)');
        $this->components->twoColumnDetail(
            'Cron',
            'confirme no servidor: * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1'
        );
    }

    /**
     * Retrabalho: o que ainda está preso na entrega.
     */
    private function checkIntegrations(): void
    {
        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan>Entregas pendentes</>', '');

        $pending = NfseInvoice::where('status', 'authorized')->where('delivery_status', '!=', 'not_requested')
            ->whereIn('delivery_status', ['pending', 'sending', 'failed', 'partial'])
            ->count();

        if ($pending === 0) {
            $this->checkOk('Notas autorizadas sem entrega concluída', '0');

            return;
        }

        $this->checkWarn('Notas autorizadas sem entrega concluída', (string) $pending);
        $this->line('  Reprocessar depois de configurar SMTP/Meta: php artisan nfse:deliver --retry-failed');
    }
}