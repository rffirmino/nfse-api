<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FiscalConfiguration extends Model
{
    use HasUuids;

    protected $fillable = [
        'establishment_external_id', 'municipality_code', 'municipality_name',
        'provider', 'environment', 'provider_registration', 'municipal_inscription', 'tax_regime',
        'service_code', 'cnae', 'nbs', 'iss_rate', 'retention_rules',
        'operation_nature', 'incidence_municipality_code', 'issue_on_payment', 'active',
        'default_delivery_channels',
    ];

    protected function casts(): array
    {
        return [
            'iss_rate' => 'decimal:4',
            'retention_rules' => 'array',
            'default_delivery_channels' => 'array',
            'issue_on_payment' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
