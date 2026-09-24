<?php

namespace App\Fiscal;

use App\Contracts\FiscalProvider;
use App\Contracts\FiscalResult;
use App\Models\NfseInvoice;

class FakeFiscalProvider implements FiscalProvider
{
    public function issueInvoice(NfseInvoice $invoice): FiscalResult
    {
        return new FiscalResult('authorized', 'fake-' . $invoice->id, 'fake-protocol');
    }

    public function getInvoice(NfseInvoice $invoice): FiscalResult
    {
        return new FiscalResult($invoice->status, $invoice->external_id);
    }

    public function cancelInvoice(NfseInvoice $invoice, string $reason): FiscalResult
    {
        return new FiscalResult('cancelled', $invoice->external_id, null, $reason);
    }

    public function getStatus(NfseInvoice $invoice): FiscalResult
    {
        return new FiscalResult($invoice->status, $invoice->external_id);
    }
}
