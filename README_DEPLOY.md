# API WhatsApp/NFS-e — Subida reproduzível (E2)

Passos para subir o serviço do zero numa máquina limpa.

## Requisitos
- PHP 8.2+ com extensões: curl, mbstring, openssl, pdo_mysql, xml, zip.
- Composer 2.
- MariaDB/MySQL.
- (Produção) Apache/Nginx com HTTPS.

## Passos
```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# editar .env (ver abaixo)
php artisan migrate --force
php artisan l5-swagger:generate
php artisan config:clear && php artisan route:clear
```

## Variáveis de ambiente essenciais
```
APP_ENV=production
APP_KEY=<gerado>
APP_URL=https://SEU-DOMINIO

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=whatsapp_nfse
DB_USERNAME=whatsapp_api
DB_PASSWORD=<senha>

# Assinatura interna entre sistemas (HMAC)
HMAC_SECRET=<segredo-global>
# Segredos por client_id (JSON) — opcional; fallback é o HMAC_SECRET
HMAC_CLIENTS={"agendamentos":"<segredo-do-cliente>"}

# WhatsApp (Meta Cloud API)
WHATSAPP_PROVIDER=meta
META_GRAPH_VERSION=v25.0
META_ACCESS_TOKEN=<token>
META_PHONE_NUMBER_ID=<phone-number-id>
META_WABA_ID=<waba-id>
META_APP_ID=<app-id>
META_APP_SECRET=<app-secret>
META_VERIFY_TOKEN=<verify-token>

# Fiscal (provider: fake | manual | asaas)
NFSE_PROVIDER=manual
# Asaas (NFS-e). O token é por estabelecimento/subconta, cadastrado via
# POST /api/v1/fiscal/accounts (armazenado criptografado).
ASAAS_BASE_URL=https://api-sandbox.asaas.com
ASAAS_ACCESS_TOKEN=
ASAAS_TIMEOUT=20
```

## Worker e scheduler
- Fila (obrigatório para envio/emissão assíncrona):
  `php artisan queue:work database --tries=3 --backoff=60,300,900`
- Cron do scheduler (a cada minuto):
  `* * * * * cd /caminho && php artisan schedule:run >> /dev/null 2>&1`

## Endpoints públicos
- UI: `GET /docs`
- OpenAPI JSON: `GET /api-docs`
- Webhook Meta: `GET/POST /api/v1/whatsapp/webhook`

## Segurança
- Nunca versionar `.env`, segredos, tokens ou certificados.
- Webhook validado por `X-Hub-Signature-256` (App Secret).
- Requisições internas assinadas por HMAC (`X-Client-Id`, `X-Timestamp`, `X-Nonce`, `X-Signature`, `Idempotency-Key`).

## Operação e monitoração
- Health check público: `GET /health` retorna `status`, profundidade da fila (`queue_pending`) e horário. Use em monitor externo (UptimeRobot/Prometheus) com alerta se `queue_pending` crescer ou o endpoint cair.
- Fila: mantenha o `queue:work` supervisionado (systemd) com restart automático; alertar se `queue_pending` ficar acima do normal por mais de X minutos.
- Logs: `storage/logs/laravel.log` (rotacionar). Falhas de envio/emissão ficam registradas em `whatsapp_message_events`/`nfse_invoice_events` com o motivo original.
- **Rotação de segredos**: o `HMAC_CLIENTS` permite rotacionar por cliente sem afetar os demais. Procedimento: gerar novo segredo, atualizar `.env` (`HMAC_CLIENTS`), `php artisan config:clear`, avisar o cliente e remover o antigo após a confirmação.
- Teste de carga: disparar `POST /api/v1/messages`/`POST /api/v1/invoices` em lote (dados fictícios, número de teste) e observar fila, latência e erros; validar retry e idempotência sob concorrência.
- Webhook: monitorar entregas reais `delivered`/`read`; se pararem, verificar assinatura do app na WABA (`subscribed_apps`) e o App Secret.


## Entrega da NFS-e ao cliente final

A entrega acontece sozinha quando a nota é autorizada (emissão, registro manual
ou webhook do Asaas). Para retentar o que falhou, inclua no cron do servidor:

```
* * * * * cd /caminho/da/api && php artisan schedule:run >> /dev/null 2>&1
```

O `routes/console.php` já agenda `fiscal:reconcile` (a cada 5 min) e
`nfse:deliver --retry-failed` (a cada 10 min).

Variáveis relevantes:

```
NFSE_WHATSAPP_TEMPLATE=nome_do_template   # template aprovado na Meta (vazio = texto livre, só na janela de 24h)
NFSE_EMAIL_FROM=contato@seudominio.com
MAIL_MAILER=smtp                           # obrigatório para o canal de e-mail
MAIL_HOST=...
```

Cadastro fiscal do estabelecimento (roda uma vez, ou ao trocar município/serviço):

```
php artisan fiscal:establishment --establishment=qa-establishment-001 \
  --municipality-code=3509502 --municipality-name=Campinas --service-code=6.02 \
  --cnpj=68272117000168 --inscricao-municipal=161150100118 \
  --tax-regime="Simples Nacional" --iss-rate=2.00 --delivery=whatsapp,email,download
```
