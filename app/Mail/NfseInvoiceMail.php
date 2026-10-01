<?php

namespace App\Mail;

use App\Models\NfseInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NfseInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public NfseInvoice $invoice)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Sua NFS-e nº ' . (string) ($this->invoice->invoice_number ?? '-') . ' está disponível',
            from: (string) config('services.nfse_delivery.email_from', config('mail.from.address')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.nfse-invoice',
            with: [
                'invoice' => $this->invoice,
                'establishment' => $this->invoice->establishment_external_id,
                'documentUrl' => $this->invoice->document_url,
                'xml' => $this->invoice->xml,
            ],
        );
    }
}