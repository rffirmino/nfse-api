<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Credencial do provedor fiscal por estabelecimento (ex.: Asaas, subconta).
 * `access_token` é armazenado criptografado e nunca retornado nas respostas.
 */
class FiscalAccount extends Model
{
    use HasUuids;

    protected $table = 'fiscal_accounts';

    protected $fillable = [
        'establishment_external_id', 'provider', 'access_token',
        'base_url', 'environment', 'active',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'active' => 'boolean',
        ];
    }
}
