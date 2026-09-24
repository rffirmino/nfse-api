<?php

return [
    'internal' => [
        'hmac_secret' => env('HMAC_SECRET'),
        // Segredos por client_id (JSON): {"agendamentos":"<segredo>"}.
        // Mantém o HMAC_SECRET global como fallback para os demais clientes.
        'hmac_clients' => json_decode((string) env('HMAC_CLIENTS', '{}'), true) ?: [],
    ],
    'fiscal' => [
        'provider' => env('NFSE_PROVIDER', 'fake'),
    ],
    'asaas' => [
        'base_url' => env('ASAAS_BASE_URL', 'https://api-sandbox.asaas.com'),
        'access_token' => env('ASAAS_ACCESS_TOKEN'),
        'timeout' => env('ASAAS_TIMEOUT', 20),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
    ],
    'inbound' => [
        'callback_url' => env('INBOUND_CALLBACK_URL'),
        'callback_secret' => env('INBOUND_CALLBACK_SECRET'),
    ],
    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'fake'),
        'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'waba_id' => env('META_WABA_ID'),
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
    ],
];
