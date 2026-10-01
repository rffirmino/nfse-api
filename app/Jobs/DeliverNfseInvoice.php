<?php

namespace App\Jobs;

use App\Services\InvoiceDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\SerializesModels;

/**
 * Entrega da NFS-e autorizada ao cliente final nos canais habilitados.
 */
class DeliverNfseInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 2;

    public function __construct(public string $invoiceId)
    {
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function middleware(): array
    {
        return [new ThrottlesExceptions(3, 10)];
    }

    public function handle(InvoiceDeliveryService $service): void
    {
        $service->deliver($this->invoiceId);
    }
}