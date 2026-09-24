<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WhatsAppInboundMessage extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_inbound_messages';

    protected $fillable = [
        'external_message_id', 'wa_id', 'contact_name', 'message_type',
        'text_body', 'media_id', 'meta_timestamp', 'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'meta_timestamp' => 'integer',
            'raw_payload' => 'array',
        ];
    }
}
