<?php

namespace App\Jobs;

use App\Services\InvoiceIssuanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\SerializesModels;

class IssueNfseInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $invoiceId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function middleware(): array
    {
        return [new ThrottlesExceptions(3, 10)];
    }

    public function handle(InvoiceIssuanceService $service): void
    {
        $service->issue($this->invoiceId);
    }
}
