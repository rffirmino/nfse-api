<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'idempotency_key', 'event', 'establishment_external_id', 'recipient_role', 'recipient_phone',
        'template_name', 'template_language', 'template_parameters', 'message_text',
        'appointment_external_id', 'scheduled_at', 'status', 'provider',
        'external_message_id', 'attempts', 'last_attempt_at',
        'sent_at', 'delivered_at', 'read_at', 'failed_at',
        'request_payload', 'response_payload', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'template_parameters' => 'array',
            'scheduled_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WhatsAppMessageEvent::class);
    }
}
