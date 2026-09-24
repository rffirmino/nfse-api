<?php

namespace App\Contracts;

final class WhatsAppSendResult
{
    public function __construct(
        public string $status,
        public ?string $externalMessageId = null,
        public ?string $message = null,
        public array $payload = [],
    ) {
    }
}
