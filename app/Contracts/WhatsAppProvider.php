<?php

namespace App\Contracts;

use App\Models\WhatsAppMessage;

interface WhatsAppProvider
{
    public function send(WhatsAppMessage $message): WhatsAppSendResult;
}
