<?php

namespace App\Fiscal;

use App\Contracts\FiscalProvider;
use App\Contracts\FiscalResult;
use App\Models\NfseInvoice;

/**
 * Provedor manual: o estabelecimento emite por fora (prefeitura/sistema próprio)
 * e o GTR apenas registra número, protocolo e documentos.
 *
 * O caminho principal do MVP é o endpoint POST /api/v1/invoices/{invoice}/manual;
 * `issueInvoice` nunca deve emitir sozinho.
 */
class ManualFiscalProvider implements FiscalProvider
{
    public function issueInvoice(NfseInvoice $invoice): FiscalResult
    {
        return new FiscalResult(
            status: 'error',
            externalId: null,
            protocol: null,
            message: 'Provider manual não emite automaticamente. Registre a nota emitida por fora em /api/v1/invoices/{invoice}/manual.'
        );
    }

    public function getInvoice(NfseInvoice $invoice): FiscalResult
    {
        return new FiscalResult($invoice->status, $invoice->external_id, $invoice->protocol);
    }

    public function cancelInvoice(NfseInvoice $invoice, string $reason): FiscalResult
    {
        // Cancelamento também ocorre por fora; a API só registra o status.
        return new FiscalResult('cancelled', $invoice->external_id, $invoice->protocol, $reason);
    }

    public function getStatus(NfseInvoice $invoice): FiscalResult
    {
        return new FiscalResult($invoice->status, $invoice->external_id, $invoice->protocol);
    }
}
