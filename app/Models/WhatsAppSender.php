<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Credencial/número de WhatsApp por estabelecimento (fase 2).
 * `access_token` é armazenado criptografado (cast 'encrypted') — nunca em texto puro.
 */
class WhatsAppSender extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_senders';

    protected $fillable = [
        'establishment_external_id', 'phone_number_id', 'waba_id',
        'display_name', 'access_token', 'active',
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
