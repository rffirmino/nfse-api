<?php

namespace App\Services;

use App\Contracts\FiscalProvider;
use App\Fiscal\AsaasFiscalProvider;
use App\Fiscal\ManualFiscalProvider;
use App\Models\FiscalAccount;
use App\Models\NfseInvoice;
use App\Models\NfseInvoiceEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class InvoiceIssuanceService
{
    public function __construct(private FiscalProvider $provider)
    {
    }

    /**
     * Resolve o provedor por nota: 'asaas' usa a credencial (token) do
     * estabelecimento; os demais usam o provider configurado no ambiente.
     */
    private function providerFor(NfseInvoice $invoice): FiscalProvider
    {
        if ($invoice->provider === 'asaas') {
            $account = FiscalAccount::where('establishment_external_id', $invoice->establishment_external_id)
                ->where('active', true)
                ->first();

            if ($account === null) {
                // Fallback: sem credencial do Asaas, cai no modo manual (a nota
                // é emitida por fora e registrada depois) — nenhum município
                // fica sem registro fiscal.
                return new ManualFiscalProvider();
            }

            return new AsaasFiscalProvider((string) $account->access_token, $account->base_url);
        }

        return $this->provider;
    }

    public function issue(string $invoiceId): NfseInvoice
    {
        $invoice = DB::transaction(function () use ($invoiceId): NfseInvoice {
            $locked = NfseInvoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return $locked;
            }
            $this->transition($locked, 'processing', 'issue.started');
            $locked->increment('attempts');
            $locked->forceFill(['last_attempt_at' => now(), 'error_message' => null])->save();
            return $locked->fresh();
        });

        if ($invoice->status !== 'processing') {
            return $invoice;
        }

        try {
            $result = $this->providerFor($invoice)->issueInvoice($invoice);
            return DB::transaction(function () use ($invoice, $result): NfseInvoice {
                $current = NfseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $current->forceFill([
                    'status' => $result->status,
                    'external_id' => $result->externalId,
                    'protocol' => $result->protocol,
                    'response_payload' => $result->payload,
                    'error_message' => $result->message,
                    'issued_at' => $result->status === 'authorized' ? now() : null,
                ])->save();
                NfseInvoiceEvent::create(['invoice_id' => $current->id, 'from_status' => 'processing', 'to_status' => $result->status, 'event' => 'issue.finished', 'message' => $result->message, 'metadata' => $result->payload]);
                return $current;
            });
        } catch (Throwable $exception) {
            $current = NfseInvoice::findOrFail($invoice->id);
            $this->transition($current, 'error', 'issue.failed', $exception->getMessage());
            throw $exception;
        }
    }

    private function transition(NfseInvoice $invoice, string $to, string $event, ?string $message = null): void
    {
        $from = $invoice->status;
        $invoice->forceFill(['status' => $to, 'error_message' => $message])->save();
        NfseInvoiceEvent::create(['invoice_id' => $invoice->id, 'from_status' => $from, 'to_status' => $to, 'event' => $event, 'message' => $message]);
    }
}
