<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessageEvent extends Model
{
    protected $table = 'whatsapp_message_events';

    protected $fillable = ['whatsapp_message_id', 'from_status', 'to_status', 'event', 'message', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
