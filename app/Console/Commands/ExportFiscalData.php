<?php

namespace App\Console\Commands;

use App\Models\FiscalConfiguration;
use App\Models\NfseInvoice;
use Illuminate\Console\Command;

/**
 * Exporta os dados fiscais (notas + configurações) para guarda legal.
 *   php artisan fiscal:export --path=storage/app/nfse-export.json
 */
class ExportFiscalData extends Command
{
    protected $signature = 'fiscal:export {--path=} {--establishment=}';

    protected $description = 'Exporta notas fiscais e configurações para um arquivo JSON (guarda legal)';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: storage_path('app/nfse-export-' . now()->format('Ymd-His') . '.json'));

        $invoices = NfseInvoice::query()
            ->when($this->option('establishment'), fn ($q, $v) => $q->where('establishment_external_id', $v))
            ->orderBy('created_at')
            ->get();

        $configurations = FiscalConfiguration::query()
            ->when($this->option('establishment'), fn ($q, $v) => $q->where('establishment_external_id', $v))
            ->get();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'invoices' => $invoices->toArray(),
            'configurations' => $configurations->toArray(),
        ];

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info('Exportado: ' . $path);
        $this->line('notas: ' . $invoices->count() . ' | configurações: ' . $configurations->count());

        return self::SUCCESS;
    }
}
