<?php

namespace App\Console\Commands;

use App\Fiscal\AsaasFiscalProvider;
use App\Models\FiscalAccount;
use App\Models\NfseInvoice;
use Illuminate\Console\Command;

/**
 * Reconciliação fiscal: consulta no Asaas as notas em pending/processing/error
 * e atualiza o status local. Rede de segurança para quando o webhook falha.
 */
class ReconcileFiscalInvoices extends Command
{
    protected $signature = 'fiscal:reconcile {--limit=50}';

    protected $description = 'Reconcilia notas fiscais Asaas pendentes/processing com a API do provedor';

    public function handle(): int
    {
        $limit = max((int) $this->option('limit'), 1);

        $invoices = NfseInvoice::where('provider', 'asaas')
            ->whereNotNull('external_id')
            ->whereIn('status', ['pending', 'processing', 'error'])
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $stats = ['checked' => 0, 'updated' => 0, 'errors' => 0];

        foreach ($invoices as $invoice) {
            $account = FiscalAccount::where('establishment_external_id', $invoice->establishment_external_id)
                ->where('active', true)
                ->first();

            if ($account === null) {
                continue;
            }

            try {
                $result = (new AsaasFiscalProvider((string) $account->access_token, $account->base_url))
                    ->getStatus($invoice);

                $stats['checked']++;

                $update = [
                    'status' => $result->status,
                    'response_payload' => $result->payload ?: $invoice->response_payload,
                ];
                if (empty($invoice->external_id) && $result->externalId) {
                    $update['external_id'] = $result->externalId;
                }
                if ($result->status === 'authorized') {
                    $update['issued_at'] = $invoice->issued_at ?: now();
                    $update['error_message'] = null;
                } elseif ($result->status === 'cancelled') {
                    $update['cancelled_at'] = $invoice->cancelled_at ?: now();
                } elseif ($result->status === 'error') {
                    $update['error_message'] = $result->message ?: 'Erro informado pelo Asaas.';
                }

                $invoice->forceFill($update)->save();
                $stats['updated']++;
            } catch (\Throwable $exception) {
                $stats['errors']++;
                $this->warn('Falha ao reconciliar ' . $invoice->id . ': ' . $exception->getMessage());
            }
        }

        $this->info('fiscal:reconcile ' . json_encode($stats, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
