<?php

namespace App\Http\Controllers;

use App\Fiscal\AsaasFiscalProvider;
use App\Jobs\DeliverNfseInvoice;
use App\Models\FiscalEvent;
use App\Models\NfseInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe os webhooks de NFS-e do Asaas (INVOICE_AUTHORIZED, INVOICE_ERROR,
 * INVOICE_CANCELED, ...). Valida o token do webhook, garante idempotência e
 * atualiza a nota correspondente pelo `external_id`.
 */
class AsaasFiscalWebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $expected = (string) config('services.asaas.webhook_token');
        $token = (string) $request->header('asaas-access-token', '');

        if ($expected === '' || !hash_equals($expected, $token)) {
            return response()->json(['error' => ['code' => 'invalid_token', 'message' => 'Token do webhook inválido.']], 401);
        }

        $payload = $request->all();
        $eventType = (string) ($payload['event'] ?? '');
        $invoice = $payload['invoice'] ?? [];
        $externalId = isset($invoice['id']) ? (string) $invoice['id'] : null;
        $status = $invoice['status'] ?? null;

        // Asaas não envia id de evento; a chave combina evento + nota + status + id do corpo.
        $eventKey = sha1($eventType . '|' . ($externalId ?? '') . '|' . (string) $status . '|' . (string) ($payload['id'] ?? ''));

        $event = FiscalEvent::firstOrCreate(
            ['external_event_id' => $eventKey],
            [
                'provider' => 'asaas',
                'event_type' => $eventType !== '' ? $eventType : null,
                'invoice_external_id' => $externalId,
                'payload' => $payload,
            ]
        );

        if ($event->wasRecentlyCreated && $externalId !== null) {
            $this->applyToInvoice($externalId, $status, $payload);
        }

        return response()->json(['status' => 'received']);
    }

    private function applyToInvoice(string $externalId, ?string $asaasStatus, array $payload): void
    {
        $invoice = NfseInvoice::where('external_id', $externalId)->first();
        if ($invoice === null) {
            return;
        }

        $status = AsaasFiscalProvider::mapStatus($asaasStatus);
        $description = $payload['invoice']['statusDescription'] ?? null;

        $update = [
            'status' => $status,
            'response_payload' => $payload,
            'error_message' => $status === 'error' ? ($description ?: 'Erro informado pelo Asaas.') : null,
        ];

        if ($status === 'authorized') {
            $update['issued_at'] = $invoice->issued_at ?: now();
        } elseif ($status === 'cancelled') {
            $update['cancelled_at'] = $invoice->cancelled_at ?: now();
        }

        $invoice->forceFill($update)->save();

        // Autorização confirmada pelo provedor: entrega ao cliente final nos
        // canais habilitados (WhatsApp/e-mail/download).
        if ($status === 'authorized' && $invoice->hasRequestedDelivery() && $invoice->delivery_status !== 'sent') {
            DeliverNfseInvoice::dispatch($invoice->id);
        }
    }
}
