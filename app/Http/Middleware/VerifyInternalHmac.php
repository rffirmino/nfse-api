<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = (string) $request->header('X-Client-Id', '');
        $globalSecret = (string) config('services.internal.hmac_secret');
        $clients = config('services.internal.hmac_clients', []);

        $timestamp = (string) $request->header('X-Timestamp', '');
        $nonce = (string) $request->header('X-Nonce', '');
        $signature = (string) $request->header('X-Signature', '');

        $shapeOk = $request->header('X-Client-Id') !== null
            && ctype_digit($timestamp)
            && $nonce !== ''
            && abs(time() - (int) $timestamp) <= 300
            && preg_match('/^sha256=[a-f0-9]{64}$/', $signature) === 1;

        if (!$shapeOk) {
            return $this->unauthorized($request);
        }

        // Segredos de ambiente: específicos do client_id (string ou array para
        // rotação sem downtime) + segredo global de fallback.
        $candidates = [];
        if ($clientId !== '' && is_array($clients) && !empty($clients[$clientId])) {
            foreach ((array) $clients[$clientId] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }
        if ($globalSecret !== '') {
            $candidates[] = $globalSecret;
        }

        if ($this->matches($candidates, $timestamp, $nonce, $signature, $request->getContent())) {
            return $next($request);
        }

        // Credenciais cadastradas em banco (client:credentials).
        if ($clientId !== '') {
            $client = ApiClient::where('client_id', $clientId)->where('active', true)->first();
            if ($client !== null && $this->matches([(string) $client->secret_enc], $timestamp, $nonce, $signature, $request->getContent())) {
                return $next($request);
            }
        }

        return $this->unauthorized($request);
    }

    private function matches(array $secrets, string $timestamp, string $nonce, string $signature, string $body): bool
    {
        foreach ($secrets as $secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function unauthorized(Request $request): Response
    {
        return response()->json(['error' => [
            'code' => 'unauthorized',
            'message' => 'Assinatura inválida.',
            'request_id' => $request->attributes->get('request_id'),
        ]], 401);
    }
}
