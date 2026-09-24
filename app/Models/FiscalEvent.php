<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FiscalEvent extends Model
{
    use HasUuids;

    protected $table = 'fiscal_events';

    protected $fillable = [
        'provider', 'external_event_id', 'event_type',
        'invoice_external_id', 'payload',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
