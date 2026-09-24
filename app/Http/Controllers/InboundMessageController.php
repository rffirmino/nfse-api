<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppInboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use OpenApi\Attributes as OA;

class InboundMessageController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inbound-messages',
        tags: ['Inbound'],
        summary: 'Lista mensagens recebidas (cliente → empresa)',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'wa_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '5519991170718')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 20)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 0)),
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de mensagens recebidas'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $offset = max((int) $request->query('offset', 0), 0);
        $waId = $request->query('wa_id');

        $query = WhatsAppInboundMessage::query();
        if ($waId !== null && $waId !== '') {
            $query->where('wa_id', (string) $waId);
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'items' => $items,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/inbound-messages/{inbound}',
        tags: ['Inbound'],
        summary: 'Consulta uma mensagem recebida',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'inbound', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mensagem recebida'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
            new OA\Response(response: 404, description: 'Não encontrada'),
        ],
    )]
    public function show(Request $request, WhatsAppInboundMessage $inbound): JsonResponse
    {
        return response()->json([
            'success' => true,
            'inbound' => $inbound,
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }
}
