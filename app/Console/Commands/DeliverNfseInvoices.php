<?php

namespace App\Console\Commands;

use App\Services\InvoiceDeliveryService;
use App\Models\NfseInvoice;
use Illuminate\Console\Command;

/**
 * Entrega (retry) das NFS-e autorizadas aos canais do cliente final.
 * Usado pelo cron como rede de segurança e para reprocessar notas com falha
 * de envio após configuração de SMTP/template.
 */
class DeliverNfseInvoices extends Command
{
    protected $signature = 'nfse:deliver
        {--invoice= : Processa apenas uma nota (id interno)}
        {--limit=50 : Limite de notas por execução}
        {--retry-failed : Reprocessa também as notas em failed/partial}';

    protected $description = 'Entrega as NFS-e autorizadas por WhatsApp/e-mail conforme os canais habilitados';

    public function handle(InvoiceDeliveryService $service): int
    {
        $statuses = $this->option('retry-failed')
            ? ['pending', 'sending', 'failed', 'partial']
            : ['pending', 'sending'];

        $query = NfseInvoice::where('status', 'authorized')
            ->where('delivery_status', '!=', 'not_requested')
            ->whereIn('delivery_status', $statuses);

        if ($this->option('invoice')) {
            $query->whereKey((string) $this->option('invoice'));
        }

        $invoices = $query->orderBy('updated_at')->limit(max((int) $this->option('limit'), 1))->get();

        $stats = ['processed' => 0, 'sent' => 0, 'partial' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($invoices as $invoice) {
            $stats['processed']++;
            $updated = $service->deliver($invoice->id);

            $key = match ($updated->delivery_status) {
                'sent' => 'sent',
                'partial' => 'partial',
                'failed' => 'failed',
                default => 'skipped',
            };
            $stats[$key]++;

            if ($key === 'failed' || $key === 'partial') {
                $this->warn($invoice->id . ' → ' . $updated->delivery_status . ' ' . json_encode($updated->delivery_errors, JSON_UNESCAPED_UNICODE));
            }
        }

        $this->info('nfse:deliver ' . json_encode($stats, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}