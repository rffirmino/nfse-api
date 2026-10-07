<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Mail\NfseInvoiceMail;
use App\Models\NfseInvoice;
use App\Models\NfseInvoiceEvent;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Entrega a NFS-e autorizada ao cliente final nos canais habilitados pelo
 * estabelecimento: WhatsApp, e-mail e disponibilidade de download no app.
 *
 * Regras:
 * - Nada é enviado sem o canal explicitamente TRUE (default-deny).
 * - Só entrega nota `authorized` (ou com documento vinculado).
 * - `download` não é envio: apenas registra que o app do sistema do cliente
 *   pode disponibilizar o documento.
 * - Cada canal é independente: um erro em um canal não impede os demais, e o
 *   resultado é registrado em `delivery_errors` para retry pelo comando
 *   `nfse:deliver`.
 */
class InvoiceDeliveryService
{
    public const MAX_ATTEMPTS = 3;

    public function deliver(string $invoiceId): NfseInvoice
    {
        $invoice = NfseInvoice::whereKey($invoiceId)->first();

        if ($invoice === null || !$invoice->hasRequestedDelivery()) {
            return $invoice;
        }

        if (!in_array($invoice->status, ['authorized'], true)) {
            // Requer autorização: a entrega fica pendente e o comando/webhook
            // dispara de novo quando a nota for autorizada.
            $invoice->forceFill(['delivery_status' => 'pending'])->save();

            return $invoice;
        }

        if ($invoice->delivery_status === 'sent' && $invoice->delivered_at !== null) {
            return $invoice; // já entregue em todos os canais
        }

        if ($invoice->delivery_attempts >= self::MAX_ATTEMPTS) {
            return $invoice;
        }

        $invoice->increment('delivery_attempts');
        $invoice->forceFill(['delivery_status' => 'sending', 'delivery_errors' => null])->save();

        $channels = $invoice->requestedDeliveryChannels();
        $errors = (is_array($invoice->delivery_errors) ? $invoice->delivery_errors : []);
        $dispatched = [];

        foreach ($channels as $channel => $enabled) {
            if (!$enabled) {
                continue;
            }

            if ($channel === 'download') {
                // Canal sem efeito colateral: habilita o download no app.
                $dispatched[] = $channel;
                continue;
            }

            try {
                $reference = $channel === 'whatsapp'
                    ? $this->deliverByWhatsApp($invoice)
                    : $this->deliverByEmail($invoice);
                $dispatched[] = $channel;
                Log::info('NFS-e entregue por canal', [
                    'invoice_id' => $invoice->id, 'channel' => $channel, 'reference' => $reference,
                ]);
            } catch (Throwable $exception) {
                $message = $this->sanitize($exception->getMessage());
                $errors[$channel] = $message;
                Log::warning('Falha na entrega da NFS-e', [
                    'invoice_id' => $invoice->id, 'channel' => $channel, 'error' => $message,
                ]);
            }
        }

        $requested = array_keys(array_filter($channels));
        $delivered = array_diff($requested, array_keys($errors));

        $status = 'sent';
        if ($errors !== [] && $delivered !== []) {
            $status = 'partial';
        } elseif ($errors !== []) {
            $status = 'failed';
        }

        return DB::transaction(function () use ($invoice, $status, $errors, $dispatched): NfseInvoice {
            $current = NfseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $current->forceFill([
                'delivery_status' => $status,
                'delivery_errors' => $errors ?: null,
                'delivered_at' => in_array($status, ['sent', 'partial'], true) ? ($current->delivered_at ?: now()) : null,
            ])->save();

            NfseInvoiceEvent::create([
                'invoice_id' => $current->id,
                'from_status' => $current->status,
                'to_status' => $current->status,
                'event' => 'delivery.' . $status,
                'message' => $dispatched === [] ? null : 'Canais: ' . implode(', ', $dispatched),
                'metadata' => ['channels' => $current->requestedDeliveryChannels(), 'errors' => $errors ?: null],
            ]);

            return $current->fresh();
        });
    }

    /**
     * WhatsApp: usa o mesmo pipeline de mensagens da API (fila + webhook da
     * Meta), então o status da entrega continua rastreável em whatsapp_messages.
     * Texto livre só é aceito dentro da janela de 24h da Meta; fora dela é
     * preciso template aprovado (NFSE_WHATSAPP_TEMPLATE).
     */
    private function deliverByWhatsApp(NfseInvoice $invoice): string
    {
        $phone = $this->normalizePhone($invoice->customer_phone);
        if ($phone === null) {
            throw new \RuntimeException('Cliente sem telefone válido para envio por WhatsApp.');
        }

        $template = trim((string) config('services.nfse_delivery.whatsapp_template'));
        $document = $invoice->document_url;

        $message = WhatsAppMessage::firstOrCreate(
            ['idempotency_key' => "nfse-delivery:{$invoice->id}:whatsapp"],
            [
                'event' => 'nfse.authorized',
                'establishment_external_id' => $invoice->establishment_external_id,
                'recipient_role' => 'customer',
                'recipient_phone' => $phone,
                'template_name' => $template !== '' ? $template : null,
                'template_language' => $template !== '' ? (string) config('services.nfse_delivery.whatsapp_template_language') : null,
                'template_parameters' => $template !== '' ? $this->templateParameters($invoice) : null,
                'message_text' => $template === '' ? $this->whatsappText($invoice, $document) : null,
                'appointment_external_id' => $invoice->appointment_external_id,
                'status' => 'pending',
                'provider' => (string) config('services.whatsapp.provider', 'fake'),
            ]
        );

        if ($message->status === 'pending') {
            SendWhatsAppMessage::dispatch($message->id);
        }

        return $message->id;
    }

    private function deliverByEmail(NfseInvoice $invoice): string
    {
        $email = trim((string) $invoice->customer_email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Cliente sem e-mail válido para envio.');
        }

        if ((string) config('mail.default') === 'log') {
            throw new \RuntimeException('Envio de e-mail desativado (MAIL_MAILER=log no ambiente).');
        }

        Mail::to($email)->send(new NfseInvoiceMail($invoice));

        return $email;
    }

    /**
     * Parâmetros do template (sempre 2, na ordem dos placeholders {{1}} {{2}}):
     * número da nota e valor. O download do documento fica no app/e-mail; o
     * WhatsApp é só o aviso, para não depender de link de provedor (que expira).
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function templateParameters(NfseInvoice $invoice): array
    {
        return [
            ['name' => 'numero_nota', 'value' => (string) ($invoice->invoice_number ?? '-')],
            ['name' => 'valor', 'value' => 'R$ ' . number_format((float) ($invoice->amount ?? 0), 2, ',', '.')],
        ];
    }

    private function whatsappText(NfseInvoice $invoice, ?string $document): string
    {
        $text = sprintf(
            "Olá, %s! Sua NFS-e nº %s (R$ %s) foi emitida.\n",
            trim((string) ($invoice->customer_name ?: 'cliente')),
            (string) ($invoice->invoice_number ?? '-'),
            number_format((float) ($invoice->amount ?? 0), 2, ',', '.')
        );

        return $document !== null && $document !== ''
            ? $text . "Acesse a nota: {$document}"
            : $text;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (!str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        return strlen($digits) >= 12 && strlen($digits) <= 13 ? '+' . $digits : null;
    }

    private function sanitize(string $text): string
    {
        $text = str_replace(['Access Token', 'access_token', 'password'], '[omitido]', $text);

        return mb_substr($text, 0, 500);
    }
}