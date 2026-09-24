<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use OpenApi\Attributes as OA;

/**
 * Credenciais/número de WhatsApp por estabelecimento (fase 2).
 * O token é armazenado criptografado e nunca retornado nas respostas.
 */
class WhatsAppSenderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/whatsapp/senders',
        tags: ['Messages'],
        summary: 'Lista senders (número por estabelecimento)',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Lista de senders'), new OA\Response(response: 401, description: 'Assinatura inválida')],
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'items' => WhatsAppSender::orderByDesc('created_at')->get(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/whatsapp/senders',
        tags: ['Messages'],
        summary: 'Cria/atualiza o sender de um estabelecimento',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['establishment_external_id', 'phone_number_id', 'access_token'],
            properties: [
                new OA\Property(property: 'establishment_external_id', type: 'string', example: 'est-001'),
                new OA\Property(property: 'phone_number_id', type: 'string', example: '1314669508391450'),
                new OA\Property(property: 'waba_id', type: 'string', nullable: true),
                new OA\Property(property: 'display_name', type: 'string', nullable: true),
                new OA\Property(property: 'access_token', type: 'string', description: 'Token do estabelecimento (armazenado criptografado)'),
                new OA\Property(property: 'active', type: 'boolean', default: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Sender salvo'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ],
    )]
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_external_id' => ['required', 'string', 'max:150'],
            'phone_number_id' => ['required', 'string', 'max:60'],
            'waba_id' => ['nullable', 'string', 'max:60'],
            'display_name' => ['nullable', 'string', 'max:150'],
            'access_token' => ['required', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $sender = WhatsAppSender::updateOrCreate(
            ['establishment_external_id' => $data['establishment_external_id']],
            $data
        );

        return response()->json(['success' => true, 'sender' => $sender->makeHidden('access_token')]);
    }
}
