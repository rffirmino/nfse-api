<?php

namespace App\Contracts;

final class FiscalResult
{
    public function __construct(
        public string $status,
        public ?string $externalId = null,
        public ?string $protocol = null,
        public ?string $message = null,
        public array $payload = [],
    ) {
    }
}
