<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NfseInvoiceEvent extends Model
{
    protected $fillable = ['invoice_id', 'from_status', 'to_status', 'event', 'message', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
