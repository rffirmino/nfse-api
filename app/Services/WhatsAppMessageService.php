<?php

namespace App\Services;

use App\Contracts\WhatsAppProvider;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhatsAppMessageService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private WhatsAppProvider $provider)
    {
    }

    public function send(string $messageId, int $maxAttempts = self::MAX_ATTEMPTS): WhatsAppMessage
    {
        $message = DB::transaction(function () use ($messageId): WhatsAppMessage {
            $locked = WhatsAppMessage::whereKey($messageId)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, ['pending', 'queued'], true)) {
                return $locked;
            }

            $locked->increment('attempts');
            $locked->forceFill([
                'status' => 'queued',
                'last_attempt_at' => now(),
                'error_message' => null,
            ])->save();
            $this->record($locked, 'queued', 'message.sending');

            return $locked->fresh();
        });

        if ($message->status !== 'queued') {
            return $message;
        }

        try {
            $result = $this->provider->send($message);

            return DB::transaction(function () use ($message, $result): WhatsAppMessage {
                $current = WhatsAppMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();

                if ($result->status === 'failed') {
                    return $this->fail($current, $result->message ?? 'Falha informada pelo provider.', $result->payload);
                }

                // Corrida webhook x job: o status "delivered"/"read" pode chegar
                // ANTES de gravarmos o "sent" (a Meta entrega muito rápido). Nesse
                // caso preservamos o status mais avançado e apenas completamos os
                // dados de envio (external_message_id/sent_at).
                $alreadyAdvanced = in_array($current->status, ['delivered', 'read'], true);

                $update = [
                    'external_message_id' => $current->external_message_id ?: $result->externalMessageId,
                    'response_payload' => $result->payload,
                ];

                if (!$alreadyAdvanced) {
                    $update['status'] = $result->status;
                    $update['sent_at'] = $result->status === 'sent' ? ($current->sent_at ?: now()) : null;
                } elseif ($current->sent_at === null) {
                    $update['sent_at'] = now();
                }

                $current->forceFill($update)->save();
                if (!$alreadyAdvanced) {
                    $this->record($current, $result->status, 'message.sent', $result->message, $result->payload);
                }

                return $current;
            });
        } catch (Throwable $exception) {
            $current = WhatsAppMessage::findOrFail($message->id);

            if ($current->attempts >= $maxAttempts) {
                return DB::transaction(function () use ($current, $exception): WhatsAppMessage {
                    $locked = WhatsAppMessage::whereKey($current->id)->lockForUpdate()->firstOrFail();

                    return $this->fail($locked, $this->sanitize($exception->getMessage()));
                });
            }

            WhatsAppMessage::whereKey($current->id)->update(['error_message' => $this->sanitize($exception->getMessage())]);
            $this->record($current, 'queued', 'message.attempt_failed', $this->sanitize($exception->getMessage()));

            throw $exception;
        }
    }

    private function fail(WhatsAppMessage $message, string $error, array $payload = []): WhatsAppMessage
    {
        // Não regride um status já avançado pelo webhook (delivered/read).
        if (in_array($message->status, ['delivered', 'read'], true)) {
            return $message;
        }

        $message->forceFill([
            'status' => 'failed',
            'response_payload' => $payload ?: $message->response_payload,
            'error_message' => $error,
            'failed_at' => now(),
        ])->save();
        $this->record($message, 'failed', 'message.failed', $error, $payload);

        return $message;
    }

    private function record(WhatsAppMessage $message, string $to, string $event, ?string $messageText = null, array $metadata = []): void
    {
        WhatsAppMessageEvent::create([
            'whatsapp_message_id' => $message->id,
            'from_status' => $message->status,
            'to_status' => $to,
            'event' => $event,
            'message' => $messageText,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function sanitize(string $text): string
    {
        $text = str_replace(['Access Token', 'access_token'], '[token omitido]', $text);

        return mb_substr($text, 0, 500);
    }
}
