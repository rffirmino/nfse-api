<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.hmac_secret' => 'global-secret']);
        config(['services.internal.hmac_clients' => []]);
        config(['services.whatsapp.provider' => 'fake']);
    }

    private function payload(): array
    {
        return [
            'event' => 'appointment.created',
            'recipient' => ['role' => 'customer', 'phone' => '+5511999999999'],
            'text' => ['body' => 'oi'],
        ];
    }

    private function signedPost(string $secret, string $idempotency, string $clientId): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $nonce = uniqid('nonce-', true);
        $body = json_encode($this->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);

        return $this->call('POST', '/api/v1/messages', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => $clientId,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => $idempotency,
        ], $body);
    }

    public function test_command_creates_client_and_secret_authenticates(): void
    {
        $this->artisan('client:credentials', ['action' => 'create', 'client_id' => 'novo-sistema'])
            ->assertSuccessful();

        $client = ApiClient::where('client_id', 'novo-sistema')->firstOrFail();
        $secret = $client->secret_enc;

        $this->signedPost($secret, 'novo:1', 'novo-sistema')->assertStatus(202);
    }

    public function test_rotate_invalidates_old_secret(): void
    {
        ApiClient::create(['client_id' => 'cli-x', 'secret_enc' => 'segredo-1', 'active' => true]);

        $this->signedPost('segredo-1', 'x:1', 'cli-x')->assertStatus(202);

        $this->artisan('client:credentials', ['action' => 'rotate', 'client_id' => 'cli-x'])->assertSuccessful();
        $newSecret = ApiClient::where('client_id', 'cli-x')->firstOrFail()->secret_enc;
        $this->assertNotSame('segredo-1', $newSecret);

        $this->signedPost('segredo-1', 'x:2', 'cli-x')->assertStatus(401);
        $this->signedPost($newSecret, 'x:3', 'cli-x')->assertStatus(202);
    }

    public function test_list_shows_clients(): void
    {
        ApiClient::create(['client_id' => 'listado', 'secret_enc' => 's', 'active' => true]);

        $this->artisan('client:credentials', ['action' => 'list'])->assertSuccessful();
    }
}
