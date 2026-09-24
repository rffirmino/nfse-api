<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NfseInvoice extends Model
{
    use HasUuids;

    protected $table = 'nfse_invoices';

    protected $fillable = [
        'fiscal_configuration_id', 'establishment_external_id', 'appointment_external_id',
        'customer_external_id', 'provider', 'external_id', 'status', 'emission_requested',
        'idempotency_key',
        'invoice_number', 'series', 'access_key', 'protocol', 'amount',
        'tax_amount', 'request_payload', 'response_payload', 'xml', 'document_url',
        'error_message', 'attempts', 'issued_at', 'cancelled_at', 'last_attempt_at',
        'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'emission_requested' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }
}
