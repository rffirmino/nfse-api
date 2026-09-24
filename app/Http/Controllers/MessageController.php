<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendWhatsAppMessage;

use OpenApi\Attributes as OA;

/**
 * @OA\Info(title="WhatsApp/NFS-e API", version="1.0.0", description="API de integração entre agendamentos e providers WhatsApp/NFS-e.")
 * @OA\Server(url="/", description="Servidor atual")
 * @OA\SecurityScheme(securityScheme="hmac", type="apiKey", in="header", name="X-Signature")
 * @OA\Tag(name="Messages", description="Notificações WhatsApp")
 */
class MessageController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/messages",
     *     tags={"Messages"},
     *     security={{"hmac":{}}},
     *     summary="Enfileira uma notificação",
     *     @OA\HeaderParameter(name="X-Client-Id", required=true, @OA\Schema(type="string")),
     *     @OA\HeaderParameter(name="X-Timestamp", required=true, @OA\Schema(type="integer")),
     *     @OA\HeaderParameter(name="X-Nonce", required=true, @OA\Schema(type="string")),
     *     @OA\HeaderParameter(name="X-Signature", required=true, @OA\Schema(type="string")),
     *     @OA\HeaderParameter(name="Idempotency-Key", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"event", "recipient"},
     *         oneOf={
     *             @OA\Schema(ref="#/components/schemas/WhatsAppTemplateMessage"),
     *             @OA\Schema(ref="#/components/schemas/WhatsAppTextMessage")
     *         },
     *         @OA\Property(property="event", type="string", example="appointment.created"),
     *         @OA\Property(property="recipient", type="object",
     *             @OA\Property(property="role", type="string", example="customer"),
     *             @OA\Property(property="phone", type="string", example="+5511999999999")
     *         ),
     *         @OA\Property(property="template", type="object", nullable=true,
     *             description="Template aprovado (mensagem iniciada pela empresa)",
     *             @OA\Property(property="name", type="string", example="appointment_confirmation_v1"),
     *             @OA\Property(property="language", type="string", example="pt_BR")
     *         ),
     *         @OA\Property(property="text", type="object", nullable=true,
     *             description="Texto livre (janela de atendimento de 24h)",
     *             @OA\Property(property="body", type="string", example="Bom dia! Confirmamos seu agendamento.")
     *         )
     *     )),
     *     @OA\Response(response=202, description="Aceito para processamento"),
     *     @OA\Response(response=200, description="Requisicao duplicada idempotente"),
     *     @OA\Response(response=401, description="Assinatura inválida"),
     *     @OA\Response(response=422, description="Dados inválidos")
     * )
     */
    #[OA\Post(
        path: '/api/v1/messages',
        tags: ['Messages'],
        summary: 'Enfileira uma notificação',
        description: 'Autenticação HMAC entre sistemas. `establishment_external_id` é opcional hoje '
            . '(número central) e passa a identificar o estabelecimento na fase de número próprio por tenant.',
        security: [['hmac' => []]],
        parameters: [
            new OA\Parameter(name: 'X-Client-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'agendamentos')),
            new OA\Parameter(name: 'X-Timestamp', in: 'header', required: true, schema: new OA\Schema(type: 'integer', example: 1788000000)),
            new OA\Parameter(name: 'X-Nonce', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'a1b2c3d4e5f6')),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'sha256=...')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['event', 'recipient'],
                properties: [
                    new OA\Property(property: 'event', type: 'string', example: 'appointment.created'),
                    new OA\Property(property: 'establishment_external_id', type: 'string', nullable: true, example: 'est-001', description: 'Opcional; identifica o estabelecimento de origem'),
                    new OA\Property(property: 'recipient', type: 'object', properties: [
                        new OA\Property(property: 'role', type: 'string', example: 'customer'),
                        new OA\Property(property: 'phone', type: 'string', example: '+5511999999999'),
                    ]),
                    new OA\Property(property: 'template', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'lembrete_agendamento_v1'),
                        new OA\Property(property: 'language', type: 'string', example: 'pt_BR'),
                        new OA\Property(property: 'parameters', type: 'array', items: new OA\Items(type: 'object', properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'value', type: 'string'),
                        ])),
                    ]),
                    new OA\Property(property: 'text', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'body', type: 'string', example: 'Bom dia! Confirmamos seu agendamento.'),
                    ]),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Requisicao duplicada idempotente'),
            new OA\Response(response: 202, description: 'Aceito para processamento'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:100'],
            'establishment_external_id' => ['nullable', 'string', 'max:150'],
            'recipient.role' => ['required', 'string', 'max:30'],
            'recipient.phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'template.name' => ['required_without:text.body', 'prohibits:text.body', 'string', 'max:150'],
            'template.language' => ['required_without:text.body', 'prohibits:text.body', 'string', 'max:20'],
            'template.parameters' => ['nullable', 'array', 'max:20'],
            'template.parameters.*.name' => ['sometimes', 'string', 'max:60'],
            'template.parameters.*.value' => ['sometimes', 'string', 'max:255'],
            'text.body' => ['required_without:template.name', 'string', 'max:4096'],
            'appointment.external_id' => ['nullable', 'string', 'max:150'],
            'appointment.scheduled_at' => ['nullable', 'date'],
        ]);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        if ($idempotencyKey === '') {
            return response()->json(['error' => [
                'code' => 'missing_idempotency_key',
                'message' => 'Idempotency-Key é obrigatório.',
                'request_id' => $request->attributes->get('request_id'),
            ]], 422);
        }

        $existing = WhatsAppMessage::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return response()->json([
                'message_id' => $existing->id,
                'status' => $existing->status,
                'idempotent' => true,
                'request_id' => $request->attributes->get('request_id'),
            ], 200);
        }

        $usesTemplate = $request->filled('template.name');

        $message = DB::transaction(function () use ($data, $idempotencyKey, $request, $usesTemplate): WhatsAppMessage {
            return WhatsAppMessage::create([
                'idempotency_key' => $idempotencyKey,
                'event' => $data['event'],
                'establishment_external_id' => $data['establishment_external_id'] ?? null,
                'recipient_role' => $data['recipient']['role'],
                'recipient_phone' => $data['recipient']['phone'],
                'template_name' => $usesTemplate ? $data['template']['name'] : null,
                'template_language' => $usesTemplate ? $data['template']['language'] : null,
                'template_parameters' => $usesTemplate ? ($data['template']['parameters'] ?? null) : null,
                'message_text' => $usesTemplate ? null : $data['text']['body'],
                'appointment_external_id' => $data['appointment']['external_id'] ?? null,
                'scheduled_at' => $data['appointment']['scheduled_at'] ?? null,
                'status' => 'pending',
                'provider' => (string) config('services.whatsapp.provider', 'fake'),
                'request_payload' => $request->all(),
            ]);
        });

        SendWhatsAppMessage::dispatch($message->id);

        return response()->json([
            'message_id' => $message->id,
            'status' => $message->status,
            'idempotent' => false,
            'message' => 'Notificação registrada para processamento.',
            'request_id' => $request->attributes->get('request_id'),
        ], 202);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/messages/{message}",
     *     tags={"Messages"},
     *     security={{"hmac":{}}},
     *     summary="Consulta o status de uma notificação",
     *     @OA\Parameter(name="message", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Status da mensagem"),
     *     @OA\Response(response=401, description="Assinatura inválida"),
     *     @OA\Response(response=404, description="Não encontrada")
     * )
     */
    #[OA\Get(path: '/api/v1/messages/{message}', tags: ['Messages'], summary: 'Consulta o status de uma notificação', security: [['hmac' => []]], responses: [
        new OA\Response(response: 200, description: 'Status da mensagem'),
        new OA\Response(response: 401, description: 'Assinatura inválida'),
        new OA\Response(response: 404, description: 'Não encontrada'),
    ])]
    public function show(Request $request, WhatsAppMessage $message): JsonResponse
    {
        return response()->json([
            'message_id' => $message->id,
            'event' => $message->event,
            'establishment_external_id' => $message->establishment_external_id,
            'status' => $message->status,
            'provider' => $message->provider,
            'external_message_id' => $message->external_message_id,
            'attempts' => $message->attempts,
            'sent_at' => $message->sent_at,
            'delivered_at' => $message->delivered_at,
            'read_at' => $message->read_at,
            'failed_at' => $message->failed_at,
            'error_message' => $message->error_message,
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }
}
