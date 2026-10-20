<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\FiscalProvider;
use App\Contracts\WhatsAppProvider;
use App\Fiscal\AsaasFiscalProvider;
use App\Fiscal\FakeFiscalProvider;
use App\Fiscal\ManualFiscalProvider;
use App\WhatsApp\FakeWhatsAppProvider;
use App\WhatsApp\MetaWhatsAppProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FiscalProvider::class, function (): FiscalProvider {
            $provider = (string) config('services.fiscal.provider', 'fake');

            // O FakeFiscalProvider devolve 'authorized' com external_id 'fake-...'.
            // Em producao isso e uma nota fiscal inexistente marcada como emitida:
            // falhar aqui e preferivel a entregar documento falso ao cliente.
            if ($provider === 'fake' && app()->environment('production')) {
                throw new \RuntimeException(
                    "NFSE_PROVIDER='fake' em producao: o provider simulado marca notas como "
                    . "autorizadas sem emitir nada. Use 'asaas' ou 'manual'."
                );
            }

            return match ($provider) {
                'manual' => new ManualFiscalProvider(),
                'asaas' => new AsaasFiscalProvider((string) config('services.asaas.access_token')),
                'fake' => new FakeFiscalProvider(),
                default => throw new \RuntimeException(
                    "NFSE_PROVIDER='{$provider}' nao reconhecido. Use 'asaas', 'manual' ou 'fake'."
                ),
            };
        });

        $this->app->bind(WhatsAppProvider::class, function (): WhatsAppProvider {
            $provider = (string) config('services.whatsapp.provider', 'fake');

            // Sem o match explicito, um valor errado (typo, ou 'zapi' que nao existe
            // aqui) caia no provider falso: as mensagens sumiam sem erro nenhum.
            if ($provider !== 'meta' && app()->environment('production')) {
                throw new \RuntimeException(
                    "WHATSAPP_PROVIDER='{$provider}' em producao: so 'meta' envia de verdade."
                );
            }

            return match ($provider) {
                'meta' => new MetaWhatsAppProvider(),
                'fake' => new FakeWhatsAppProvider(),
                default => throw new \RuntimeException(
                    "WHATSAPP_PROVIDER='{$provider}' nao reconhecido. Use 'meta' ou 'fake'."
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
