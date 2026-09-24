<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.verify_token' => 'verify-test-123']);
        config(['services.whatsapp.app_secret' => 'app-secret-test']);
    }

    public function test_verification_returns_challenge_when_token_matches(): void
    {
        $response = $this->getJson('/api/v1/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=verify-test-123&hub_challenge=CHALLENGE_ABC');

        $response->assertStatus(200);
        $this->assertSame('CHALLENGE_ABC', $response->getContent());
    }

    public function test_verification_is_rejected_when_token_does_not_match(): void
    {
        $response = $this->get('/api/v1/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=CHALLENGE_ABC');

        $response->assertStatus(403);
    }

    public function test_receive_rejects_invalid_signature(): void
    {
        $this->postJson('/api/v1/whatsapp/webhook', [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ])->assertStatus(403);
    }

    public function test_receive_updates_message_status_from_webhook(): void
    {
        $message = WhatsAppMessage::create([
            'idempotency_key' => 'wh:1',
            'event' => 'appointment.created',
            'recipient_role' => 'customer',
            'recipient_phone' => '+5586998016198',
            'template_name' => 'appointment_confirmation_v1',
            'template_language' => 'pt_BR',
            'status' => 'sent',
            'external_message_id' => 'wamid.TESTE123',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '28412810588358477',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '1288770814327395'],
                        'statuses' => [[
                            'id' => 'wamid.TESTE123',
                            'status' => 'delivered',
                            'recipient_id' => '558698016198',
                            'timestamp' => '1788359168',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'app-secret-test');

        $this->call('POST', '/api/v1/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertStatus(200);

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertDatabaseHas('whatsapp_message_events', [
            'whatsapp_message_id' => $message->id,
            'event' => 'webhook.status',
            'to_status' => 'delivered',
        ]);
    }

    public function test_receive_stores_failure_reason(): void
    {
        $message = WhatsAppMessage::create([
            'idempotency_key' => 'wh:2',
            'event' => 'appointment.created',
            'recipient_role' => 'customer',
            'recipient_phone' => '+5586988116431',
            'template_name' => 'appointment_confirmation_v1',
            'template_language' => 'pt_BR',
            'status' => 'sent',
            'external_message_id' => 'wamid.FALHOU',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '28412810588358477',
                'changes' => [[
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.FALHOU',
                            'status' => 'failed',
                            'errors' => [[
                                'code' => 130497,
                                'title' => 'Business account is restricted from messaging users in this country.',
                                'message' => 'Business account is restricted from messaging users in this country.',
                            ]],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'app-secret-test');

        $this->call('POST', '/api/v1/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertStatus(200);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('130497', (string) $message->error_message);
        $this->assertNotNull($message->failed_at);
        $this->assertSame(1, WhatsAppMessageEvent::where('whatsapp_message_id', $message->id)->where('event', 'webhook.status')->count());
    }

    public function test_receive_stores_inbound_message_idempotently(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '28412810588358477',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '1314669508391450'],
                        'contacts' => [[
                            'profile' => ['name' => 'Ricardo Teste'],
                            'wa_id' => '5519991170718',
                        ]],
                        'messages' => [[
                            'from' => '5519991170718',
                            'id' => 'wamid.INBOUND1',
                            'timestamp' => '1788359168',
                            'type' => 'text',
                            'text' => ['body' => 'Oi, teste de recebimento'],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'app-secret-test');
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ];

        $this->call('POST', '/api/v1/whatsapp/webhook', [], [], [], $headers, $body)->assertStatus(200);
        // Reenvio do mesmo evento (Meta pode repetir): não duplica.
        $this->call('POST', '/api/v1/whatsapp/webhook', [], [], [], $headers, $body)->assertStatus(200);

        $this->assertDatabaseHas('whatsapp_inbound_messages', [
            'external_message_id' => 'wamid.INBOUND1',
            'wa_id' => '5519991170718',
            'contact_name' => 'Ricardo Teste',
            'message_type' => 'text',
            'text_body' => 'Oi, teste de recebimento',
        ]);
        $this->assertSame(1, \App\Models\WhatsAppInboundMessage::where('external_message_id', 'wamid.INBOUND1')->count());
    }

    public function test_inbound_dispatches_callback_when_configured(): void
    {
        config(['services.inbound.callback_url' => 'https://callback.test/inbound']);
        config(['services.inbound.callback_secret' => 'cb-secret']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $body = $this->inboundBody('wamid.CB1');
        $this->call('POST', '/api/v1/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'app-secret-test'),
        ], $body)->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://callback.test/inbound'
                && str_starts_with((string) $request->header('X-Inbound-Signature')[0], 'sha256=')
                && $request['text_body'] === 'oi callback';
        });
    }

    public function test_inbound_does_not_call_callback_when_not_configured(): void
    {
        config(['services.inbound.callback_url' => null]);
        Http::fake();

        $body = $this->inboundBody('wamid.CB2');
        $this->call('POST', '/api/v1/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'app-secret-test'),
        ], $body)->assertStatus(200);

        Http::assertNothingSent();
    }

    private function inboundBody(string $wamid): string
    {
        return json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'contacts' => [['profile' => ['name' => 'Ricardo'], 'wa_id' => '5519991170718']],
                        'messages' => [[
                            'from' => '5519991170718',
                            'id' => $wamid,
                            'timestamp' => '1788359168',
                            'type' => 'text',
                            'text' => ['body' => 'oi callback'],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
