<?php

namespace App\Http\Controllers;

use App\Models\FiscalAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use OpenApi\Attributes as OA;

/**
 * Credencial do provedor fiscal por estabelecimento (ex.: Asaas/subconta).
 * O token é armazenado criptografado e nunca retornado nas respostas.
 */
class FiscalAccountController extends Controller
{
    #[OA\Get(
        path: '/api/v1/fiscal/accounts',
        tags: ['Fiscal'],
        summary: 'Lista credenciais fiscais por estabelecimento',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Lista de credenciais'), new OA\Response(response: 401, description: 'Assinatura inválida')],
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'items' => FiscalAccount::orderByDesc('created_at')->get(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/fiscal/accounts',
        tags: ['Fiscal'],
        summary: 'Cria/atualiza a credencial fiscal de um estabelecimento',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['establishment_external_id', 'access_token'],
            properties: [
                new OA\Property(property: 'establishment_external_id', type: 'string', example: 'est-001'),
                new OA\Property(property: 'provider', type: 'string', example: 'asaas'),
                new OA\Property(property: 'access_token', type: 'string', description: 'Token da subconta (armazenado criptografado)'),
                new OA\Property(property: 'base_url', type: 'string', nullable: true, example: 'https://api-sandbox.asaas.com'),
                new OA\Property(property: 'environment', type: 'string', example: 'sandbox'),
                new OA\Property(property: 'active', type: 'boolean', default: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Credencial salva'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ],
    )]
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_external_id' => ['required', 'string', 'max:150'],
            'provider' => ['sometimes', 'string', 'max:30'],
            'access_token' => ['required', 'string'],
            'base_url' => ['nullable', 'string', 'max:150'],
            'environment' => ['sometimes', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $account = FiscalAccount::updateOrCreate(
            ['establishment_external_id' => $data['establishment_external_id']],
            $data
        );

        return response()->json(['success' => true, 'account' => $account->makeHidden('access_token')]);
    }
}
