<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envia (callback) ao sistema do cliente uma mensagem recebida no WhatsApp.
 * Assina o corpo com HMAC-SHA256 no header X-Inbound-Signature.
 */
class SendInboundCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public array $payload)
    {
    }

    public function handle(): void
    {
        $url = (string) config('services.inbound.callback_url');
        if ($url === '') {
            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = (string) config('services.inbound.callback_secret');
        $headers = ['Content-Type' => 'application/json'];
        if ($secret !== '') {
            $headers['X-Inbound-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        try {
            Http::withHeaders($headers)->timeout(15)->withBody($body, 'application/json')->post($url);
        } catch (\Throwable $exception) {
            Log::warning('Falha ao enviar callback de inbound: ' . $exception->getMessage());
        }
    }
}
