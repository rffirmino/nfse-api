<?php

namespace App\WhatsApp;

use App\Contracts\WhatsAppProvider;
use App\Contracts\WhatsAppSendResult;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class MetaWhatsAppProvider implements WhatsAppProvider
{
    private string $baseUrl;
    private string $version;
    private string $accessToken;
    private string $phoneNumberId;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.whatsapp.graph_base_url', 'https://graph.facebook.com');
        $this->version = (string) config('services.whatsapp.graph_version', 'v21.0');
        $this->accessToken = (string) config('services.whatsapp.access_token');
        $this->phoneNumberId = (string) config('services.whatsapp.phone_number_id');
    }

    public function send(WhatsAppMessage $message): WhatsAppSendResult
    {
        [$accessToken, $phoneNumberId] = $this->resolveCredentials($message);

        if ($accessToken === '' || $phoneNumberId === '') {
            throw new RuntimeException(
                'WhatsApp Meta provider não configurado: informe META_ACCESS_TOKEN/META_PHONE_NUMBER_ID '
                . 'ou cadastre um sender para o estabelecimento.'
            );
        }

        $payload = $this->buildPayload($message);
        $uri = rtrim($this->baseUrl, '/') . '/' . $this->version . '/' . $phoneNumberId . '/messages';

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($uri, $payload);
        } catch (ConnectionException|Throwable $exception) {
            throw new RuntimeException('Falha de conexão com a Graph API da Meta.', 0, $exception);
        }

        if ($response->failed()) {
            $error = $response->json('error.message', $response->body());

            if ($response->status() >= 500 || $response->status() === 429) {
                throw new RuntimeException('A Meta retornou HTTP ' . $response->status() . '.', $response->status());
            }

            return new WhatsAppSendResult(
                status: 'failed',
                message: 'HTTP ' . $response->status() . ': ' . $error,
                payload: $response->json() ?? [],
            );
        }

        $externalId = $response->json('messages.0.id');

        return new WhatsAppSendResult(
            status: 'sent',
            externalMessageId: $externalId,
            message: $externalId ? null : 'Enviado sem identificador retornado pela Meta.',
            payload: $response->json() ?? [],
        );
    }

    private function resolveCredentials(WhatsAppMessage $message): array
    {
        $accessToken = $this->accessToken;
        $phoneNumberId = $this->phoneNumberId;

        // Fase 2: se o estabelecimento tem sender próprio ativo, usa as
        // credenciais dele; senão mantém o número central do ambiente.
        if (!empty($message->establishment_external_id)) {
            $sender = WhatsAppSender::where('establishment_external_id', $message->establishment_external_id)
                ->where('active', true)
                ->first();

            if ($sender !== null) {
                $accessToken = (string) $sender->access_token;
                $phoneNumberId = (string) $sender->phone_number_id;
            }
        }

        return [$accessToken, $phoneNumberId];
    }

    private function buildPayload(WhatsAppMessage $message): array
    {
        if ($message->message_text !== null) {
            return [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $message->recipient_phone,
                'type' => 'text',
                'text' => ['body' => $message->message_text],
            ];
        }

        $components = [];
        $parameters = $message->template_parameters ?? [];

        if ($parameters !== []) {
            $values = array_map(static fn ($p) => $p['value'] ?? $p, array_values($parameters));
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(static fn ($v) => ['type' => 'text', 'text' => (string) $v], $values),
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message->recipient_phone,
            'type' => 'template',
            'template' => [
                'name' => $message->template_name,
                'language' => ['code' => $message->template_language],
                'components' => $components ?: new \stdClass(),
            ],
        ];
    }
}
