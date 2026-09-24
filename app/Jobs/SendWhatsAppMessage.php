<?php

namespace App\Jobs;

use App\Services\WhatsAppMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $messageId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(WhatsAppMessageService $service): void
    {
        $service->send($this->messageId, self::attemptsToMax());
    }

    private static function attemptsToMax(): int
    {
        return 3;
    }
}
