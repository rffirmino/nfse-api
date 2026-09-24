<?php

namespace App;

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    info: new OA\Info(title: 'WhatsApp/NFS-e API', version: '1.0.0', description: 'API de integração entre agendamentos e providers WhatsApp/NFS-e.'),
    servers: [new OA\Server(url: '/', description: 'Servidor atual')],
    tags: [
        new OA\Tag(name: 'Messages', description: 'Notificações WhatsApp'),
        new OA\Tag(name: 'Inbound', description: 'Mensagens recebidas (cliente → empresa)'),
        new OA\Tag(name: 'Fiscal', description: 'Emissão e consulta de NFS-e')
    ],
    components: new OA\Components(securitySchemes: [
        new OA\SecurityScheme(securityScheme: 'hmac', type: 'apiKey', in: 'header', name: 'X-Signature')
    ])
)]
final class OpenApiSpec
{
}
