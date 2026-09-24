<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProvider;
use App\Contracts\WhatsAppSendResult;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageEvent;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.hmac_secret' => 'test-secret']);
        config(['services.whatsapp.provider' => 'fake']);
    }

    public function test_message_is_created_and_processed_to_sent(): void
    {
        $response = $this->signedPost('/api/v1/messages', $this->validPayload(), 'msg:apt-1');

        $response->assertStatus(202)->assertJsonPath('idempotent', false);

        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'sent']);
        $message = WhatsAppMessage::where('idempotency_key', 'msg:apt-1')->firstOrFail();
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->external_message_id);
        $this->assertSame(1, WhatsAppMessageEvent::where('whatsapp_message_id', $message->id)->where('event', 'message.sent')->count());
    }

    public function test_duplicate_idempotency_key_returns_existing(): void
    {
        $first = $this->signedPost('/api/v1/messages', $this->validPayload(), 'msg:dup-1');
        $first->assertStatus(202);

        $second = $this->signedPost('/api/v1/messages', $this->validPayload(), 'msg:dup-1');
        $second->assertStatus(200)->assertJsonPath('idempotent', true)
            ->assertJsonPath('message_id', $first->json('message_id'));
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $response = $this->signedPost('/api/v1/messages', $this->validPayload(), '');

        $response->assertStatus(422)->assertJsonPath('error.code', 'missing_idempotency_key');
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['recipient']['phone'] = '1199999999';

        $response = $this->signedPost('/api/v1/messages', $payload, 'msg:badphone-1');

        $response->assertStatus(422);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->postJson('/api/v1/messages', [])->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_status_can_be_queried(): void
    {
        $created = $this->signedPost('/api/v1/messages', $this->validPayload(), 'msg:show-1');
        $created->assertStatus(202);

        $id = $created->json('message_id');
        $response = $this->signedGet('/api/v1/messages/' . $id);

        $response->assertStatus(200)->assertJsonPath('message_id', $id)->assertJsonPath('status', 'sent');
    }

    public function test_text_message_is_created_and_processed_to_sent(): void
    {
        $response = $this->signedPost('/api/v1/messages', $this->textPayload(), 'msg:text-1');

        $response->assertStatus(202)->assertJsonPath('idempotent', false);

        $this->assertDatabaseHas('whatsapp_messages', ['idempotency_key' => 'msg:text-1', 'status' => 'sent']);
        $message = WhatsAppMessage::where('idempotency_key', 'msg:text-1')->firstOrFail();
        $this->assertSame('Bom dia! Confirmamos seu agendamento.', $message->message_text);
        $this->assertNull($message->template_name);
        $this->assertNotNull($message->external_message_id);
        $this->assertSame(1, WhatsAppMessageEvent::where('whatsapp_message_id', $message->id)->where('event', 'message.sent')->count());
    }

    public function test_template_and_text_together_is_rejected(): void
    {
        $payload = $this->textPayload();
        $payload['template'] = ['name' => 'appointment_confirmation_v1', 'language' => 'pt_BR'];

        $this->signedPost('/api/v1/messages', $payload, 'msg:both-1')->assertStatus(422);
    }

    public function test_missing_content_is_rejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['template']);

        $this->signedPost('/api/v1/messages', $payload, 'msg:nocontent-1')->assertStatus(422);
    }

    public function test_service_marks_as_failed_after_max_attempts(): void
    {
        $this->app->bind(WhatsAppProvider::class, fn (): WhatsAppProvider => new class implements WhatsAppProvider
        {
            public function send(WhatsAppMessage $message): WhatsAppSendResult
            {
                throw new \RuntimeException('erro simulado');
            }
        });

        $message = WhatsAppMessage::create([
            'idempotency_key' => 'msg:fail-1',
            'event' => 'appointment.created',
            'recipient_role' => 'customer',
            'recipient_phone' => '+5511999999999',
            'template_name' => 'appointment_confirmation_v1',
            'template_language' => 'pt_BR',
            'status' => 'pending',
        ]);

        $service = app(WhatsAppMessageService::class);

        for ($attempt = 1; $attempt < WhatsAppMessageService::MAX_ATTEMPTS; $attempt++) {
            try {
                $service->send($message->id);
            } catch (\RuntimeException) {
                // tentativas intermediárias devem disparar retry
            }
        }

        $result = $service->send($message->id);

        $this->assertSame('failed', $result->status);
        $this->assertSame(WhatsAppMessageService::MAX_ATTEMPTS, $result->attempts);
        $this->assertNotNull($result->failed_at);
        $this->assertDatabaseHas('whatsapp_message_events', [
            'whatsapp_message_id' => $message->id,
            'event' => 'message.failed',
        ]);
    }

    public function test_inbound_messages_can_be_listed(): void
    {
        \App\Models\WhatsAppInboundMessage::create([
            'external_message_id' => 'wamid.LIST1',
            'wa_id' => '5519991170718',
            'contact_name' => 'Ricardo',
            'message_type' => 'text',
            'text_body' => 'ola',
        ]);

        $response = $this->signedGet('/api/v1/inbound-messages');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('items.0.external_message_id', 'wamid.LIST1')
            ->assertJsonPath('items.0.text_body', 'ola');
    }

    public function test_webhook_delivered_before_job_does_not_regress_status(): void
    {
        $message = WhatsAppMessage::create([
            'idempotency_key' => 'race:1',
            'event' => 'appointment_reminder',
            'recipient_role' => 'customer',
            'recipient_phone' => '+5511999999999',
            'message_text' => 'oi',
            'status' => 'queued',
            'provider' => 'fake',
        ]);

        $provider = new class($message->id) implements WhatsAppProvider {
            public function __construct(private string $id)
            {
            }

            public function send(WhatsAppMessage $message): WhatsAppSendResult
            {
                // Simula o webhook "delivered" chegando DURANTE o envio.
                WhatsAppMessage::whereKey($this->id)->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'external_message_id' => 'wamid.RACE',
                ]);

                return new WhatsAppSendResult(
                    status: 'sent',
                    externalMessageId: 'wamid.RACE',
                    message: null,
                    payload: [],
                );
            }
        };

        (new WhatsAppMessageService($provider))->send($message->id);

        $fresh = $message->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame('wamid.RACE', $fresh->external_message_id);
        $this->assertNotNull($fresh->sent_at);
    }

    public function test_rotation_accepts_old_and_new_secrets(): void
    {
        config(['services.internal.hmac_clients' => ['agendamentos' => ['segredo-antigo', 'segredo-novo']]]);

        foreach ([['segredo-antigo', 'rot:old'], ['segredo-novo', 'rot:new']] as [$secret, $key]) {
            $timestamp = (string) time();
            $nonce = uniqid('nonce-', true);
            $body = json_encode($this->validPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);

            $this->call('POST', '/api/v1/messages', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CLIENT_ID' => 'agendamentos',
                'HTTP_X_TIMESTAMP' => $timestamp,
                'HTTP_X_NONCE' => $nonce,
                'HTTP_X_SIGNATURE' => $signature,
                'HTTP_IDEMPOTENCY_KEY' => $key,
            ], $body)->assertStatus(202);
        }
    }

    public function test_client_specific_hmac_secret_is_accepted(): void
    {
        config(['services.internal.hmac_clients' => ['agendamentos' => 'client-secret-1']]);

        $timestamp = (string) time();
        $nonce = uniqid('nonce-', true);
        $body = json_encode($this->validPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, 'client-secret-1');

        $this->call('POST', '/api/v1/messages', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => 'agendamentos',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => 'cli-secret:1',
        ], $body)->assertStatus(202);
    }

    private function validPayload(): array
    {
        return [
            'event' => 'appointment.created',
            'recipient' => ['role' => 'customer', 'phone' => '+5511999999999'],
            'template' => [
                'name' => 'appointment_confirmation_v1',
                'language' => 'pt_BR',
                'parameters' => [['name' => 'customer_name', 'value' => 'Maria']],
            ],
            'appointment' => ['external_id' => 'agendamento-1', 'scheduled_at' => '2026-09-10T14:00:00-03:00'],
        ];
    }

    private function textPayload(): array
    {
        return [
            'event' => 'appointment.reminder',
            'recipient' => ['role' => 'customer', 'phone' => '+5511999999999'],
            'text' => ['body' => 'Bom dia! Confirmamos seu agendamento.'],
            'appointment' => ['external_id' => 'agendamento-texto-1'],
        ];
    }

    private function signedPost(string $uri, array $payload, string $idempotency): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $nonce = uniqid('nonce-', true);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, 'test-secret');

        $server = $this->headers($timestamp, $nonce, $signature);
        if ($idempotency !== '') {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotency;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    private function signedGet(string $uri): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $nonce = uniqid('nonce-', true);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n", 'test-secret');

        return $this->call('GET', $uri, [], [], [], $this->headers($timestamp, $nonce, $signature));
    }

    private function headers(string $timestamp, string $nonce, string $signature): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => 'test-client',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
        ];
    }
}
