<?php

namespace App\WhatsApp;

use App\Contracts\WhatsAppProvider;
use App\Contracts\WhatsAppSendResult;
use App\Models\WhatsAppMessage;

class FakeWhatsAppProvider implements WhatsAppProvider
{
    public function send(WhatsAppMessage $message): WhatsAppSendResult
    {
        return new WhatsAppSendResult(
            status: 'sent',
            externalMessageId: 'fake-' . $message->id,
            message: 'Enviado pelo provider fake.',
        );
    }
}
