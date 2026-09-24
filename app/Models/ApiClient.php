<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Credencial de um consumidor da API (client_id + segredo).
 * O segredo é armazenado criptografado e nunca retornado após a criação.
 */
class ApiClient extends Model
{
    use HasUuids;

    protected $table = 'api_clients';

    protected $fillable = ['client_id', 'secret_enc', 'label', 'active'];

    protected $hidden = ['secret_enc'];

    protected function casts(): array
    {
        return [
            'secret_enc' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    /**
     * Acesso conveniente ao segredo (decifrado).
     */
    public function getSecretAttribute(): ?string
    {
        return $this->secret_enc;
    }

    public function setSecretAttribute(string $value): void
    {
        $this->attributes['secret_enc'] = $value;
    }
}
