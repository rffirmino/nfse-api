<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageEvent;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.whatsapp.verify_token');

        if ($request->query('hub_mode') === 'subscribe'
            && $expected !== ''
            && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Verificação de webhook recusada.', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        if (!$this->signatureIsValid($request)) {
            return response()->json(['error' => ['code' => 'invalid_signature', 'message' => 'Assinatura inválida.']], 403);
        }

        $payload = $request->all();
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['error' => ['code' => 'unexpected_object', 'message' => 'Objeto inesperado.']], 400);
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->applyStatus($status);
                }

                // Mensagens RECEBIDAS (cliente -> empresa): persistir para consulta.
                $contacts = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    if (!empty($contact['wa_id'])) {
                        $contacts[(string) $contact['wa_id']] = $contact['profile']['name'] ?? null;
                    }
                }
                foreach ($value['messages'] ?? [] as $inbound) {
                    $this->storeInbound($inbound, $contacts);
                }
            }
        }

        return response()->json(['status' => 'received']);
    }

    private function storeInbound(array $message, array $contacts): void
    {
        $externalId = $message['id'] ?? null;
        if ($externalId === null) {
            return;
        }

        $type = isset($message['type']) ? (string) $message['type'] : null;
        $body = null;
        if ($type !== null && isset($message[$type]['body'])) {
            $body = $message[$type]['body'];
        } elseif (isset($message['text']['body'])) {
            $body = $message['text']['body'];
        }

        $waId = isset($message['from']) ? (string) $message['from'] : null;

        // Idempotência: a Meta pode reenviar o mesmo evento.
        $record = WhatsAppInboundMessage::firstOrCreate(
            ['external_message_id' => (string) $externalId],
            [
                'wa_id' => $waId,
                'contact_name' => $waId !== null ? ($contacts[$waId] ?? null) : null,
                'message_type' => $type,
                'text_body' => is_string($body) ? $body : null,
                'media_id' => ($type !== null && isset($message[$type]['id'])) ? (string) $message[$type]['id'] : null,
                'meta_timestamp' => isset($message['timestamp']) ? (int) $message['timestamp'] : null,
                'raw_payload' => $message,
            ]
        );

        // Avisa o sistema do cliente (callback) apenas na primeira vez.
        if ($record->wasRecentlyCreated && config('services.inbound.callback_url')) {
            \App\Jobs\SendInboundCallback::dispatchSync([
                'id' => $record->id,
                'wa_id' => $record->wa_id,
                'contact_name' => $record->contact_name,
                'message_type' => $record->message_type,
                'text_body' => $record->text_body,
                'external_message_id' => $record->external_message_id,
                'timestamp' => $record->meta_timestamp,
            ]);
        }
    }

    private function applyStatus(array $status): void
    {
        $externalId = $status['id'] ?? null;
        if ($externalId === null) {
            return;
        }

        $message = WhatsAppMessage::where('external_message_id', $externalId)->first();
        if ($message === null) {
            return;
        }

        $next = $status['status'] ?? null;
        $errors = $status['errors'] ?? [];
        $errorText = $errors !== []
            ? (($errors[0]['code'] ?? '') . ': ' . ($errors[0]['message'] ?? 'Erro da Meta.'))
            : null;

        DB::transaction(function () use ($message, $next, $errorText, $errors) {
            $locked = WhatsAppMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($next !== null && $next !== $locked->status) {
                $update = [
                    'status' => $next,
                    'response_payload' => $errors ?: $locked->response_payload,
                    'error_message' => $errorText ?? $locked->error_message,
                ];

                if ($next === 'sent') {
                    $update['sent_at'] = now();
                } elseif ($next === 'delivered') {
                    $update['delivered_at'] = now();
                } elseif ($next === 'read') {
                    $update['read_at'] = now();
                } elseif ($next === 'failed') {
                    $update['failed_at'] = now();
                }

                $locked->forceFill($update)->save();
                WhatsAppMessageEvent::create([
                    'whatsapp_message_id' => $locked->id,
                    'from_status' => $from,
                    'to_status' => $next,
                    'event' => 'webhook.status',
                    'message' => $errorText,
                    'metadata' => $errors ?: null,
                ]);
            }
        });
    }

    private function signatureIsValid(Request $request): bool
    {
        $appSecret = (string) config('services.whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($appSecret === '' || !str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
