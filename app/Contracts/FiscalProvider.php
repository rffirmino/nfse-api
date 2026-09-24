<?php

namespace App\Contracts;

use App\Models\NfseInvoice;

interface FiscalProvider
{
    public function issueInvoice(NfseInvoice $invoice): FiscalResult;

    public function getInvoice(NfseInvoice $invoice): FiscalResult;

    public function cancelInvoice(NfseInvoice $invoice, string $reason): FiscalResult;

    public function getStatus(NfseInvoice $invoice): FiscalResult;
}
