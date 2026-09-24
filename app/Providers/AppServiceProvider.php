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
            return match (config('services.fiscal.provider', 'fake')) {
                'manual' => new ManualFiscalProvider(),
                'asaas' => new AsaasFiscalProvider((string) config('services.asaas.access_token')),
                'fake' => new FakeFiscalProvider(),
                default => throw new \RuntimeException('Provider fiscal não configurado.'),
            };
        });

        $this->app->bind(WhatsAppProvider::class, function (): WhatsAppProvider {
            return match (config('services.whatsapp.provider', 'fake')) {
                'meta' => new MetaWhatsAppProvider(),
                default => new FakeWhatsAppProvider(),
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
