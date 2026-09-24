<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSender;
use App\WhatsApp\MetaWhatsAppProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.hmac_secret' => 'test-secret']);
        config(['services.whatsapp.graph_base_url' => 'https://graph.test']);
        config(['services.whatsapp.graph_version' => 'v25.0']);
        config(['services.whatsapp.access_token' => 'central-token']);
        config(['services.whatsapp.phone_number_id' => 'CENTRAL123']);
    }

    public function test_sender_token_is_encrypted_at_rest(): void
    {
        $sender = WhatsAppSender::create([
            'establishment_external_id' => 'est-001',
            'phone_number_id' => 'PHONE999',
            'access_token' => 'token-secreto',
            'active' => true,
        ]);

        $raw = DB::table('whatsapp_senders')->where('id', $sender->id)->value('access_token');
        $this->assertNotSame('token-secreto', $raw);
        $this->assertSame('token-secreto', $sender->fresh()->access_token);
    }

    public function test_provider_uses_establishment_sender(): void
    {
        WhatsAppSender::create([
            'establishment_external_id' => 'est-001',
            'phone_number_id' => 'PHONE999',
            'access_token' => 'token-do-estabelecimento',
            'active' => true,
        ]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $message = WhatsAppMessage::create([
            'idempotency_key' => 'sender:1',
            'event' => 'appointment.reminder',
            'establishment_external_id' => 'est-001',
            'recipient_role' => 'customer',
            'recipient_phone' => '+5511999999999',
            'message_text' => 'oi',
            'status' => 'queued',
            'provider' => 'meta',
        ]);

        (new MetaWhatsAppProvider())->send($message);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/PHONE999/messages')
                && $request->hasHeader('Authorization', 'Bearer token-do-estabelecimento');
        });
    }

    public function test_provider_falls_back_to_central_number(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.Y']]], 200)]);

        $message = WhatsAppMessage::create([
            'idempotency_key' => 'sender:2',
            'event' => 'appointment.reminder',
            'establishment_external_id' => 'est-sem-sender',
            'recipient_role' => 'customer',
            'recipient_phone' => '+5511999999999',
            'message_text' => 'oi',
            'status' => 'queued',
            'provider' => 'meta',
        ]);

        (new MetaWhatsAppProvider())->send($message);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/CENTRAL123/messages')
            && $request->hasHeader('Authorization', 'Bearer central-token'));
    }

    public function test_senders_endpoint_upserts_and_lists(): void
    {
        $this->signedPost('/api/v1/whatsapp/senders', [
            'establishment_external_id' => 'est-001',
            'phone_number_id' => 'PHONE999',
            'access_token' => 'tok',
        ], 'sender:up1')->assertStatus(200)->assertJsonPath('success', true);

        $this->signedGet('/api/v1/whatsapp/senders')
            ->assertStatus(200)
            ->assertJsonPath('items.0.establishment_external_id', 'est-001')
            ->assertJsonMissingPath('items.0.access_token');
    }

    private function signedPost(string $uri, array $payload, string $idempotency): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $nonce = uniqid('nonce-', true);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, 'test-secret');

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => 'test-client',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => $idempotency,
        ], $body);
    }

    private function signedGet(string $uri): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $nonce = uniqid('nonce-', true);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n", 'test-secret');

        return $this->call('GET', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => 'test-client',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => 'get:' . $nonce,
        ]);
    }
}
