# Procedimento de migração de dados (TeresinaSoft → infraestrutura do cliente)

A API fiscal guarda tudo no banco (MySQL/MariaDB). Não há arquivos no disco que
precisem migrar (XML e URL do documento ficam em colunas; os logs/cache do
framework são regenerados). A migração é, portanto, um dump + restore + reponto
de webhooks.

## 0. Pré-requisitos

- Acesso de leitura ao banco do servidor atual (`/var/www/nfse-api`).
- A nova VPS com o compose rodando (serviço `db` no ar) e as variáveis/segredos
  preenchidos no `.env` do compose.
- Acesso ao DNS de `nfse.gtragendamentos.com.br`.

## 1. Gerar o dump no servidor atual

```bash
ssh italo@teresinasoft.com.br
mysqldump --single-transaction --routines --triggers \
  -u <usuario> -p <nome_do_banco> > /tmp/nfse-api.sql
```

> Não interrompa o worker durante o dump; o `--single-transaction` garante um
> snapshot consistente em InnoDB.

Copie o dump para a VPS:

```bash
scp /tmp/nfse-api.sql <usuario>@<vps>:/opt/nfse-api/
```

## 2. Restaurar no novo banco

O serviço `db` do compose já cria o banco/usuario via `MARIADB_DATABASE`/
`MARIADB_USER`. Importe:

```bash
cd /opt/nfse-api
docker compose exec -T db mysql -uroot -p"$DB_ROOT_PASSWORD" nfse < nfse-api.sql
```

## 3. Subir a aplicação

```bash
docker compose up -d
docker compose logs -f app   # confirme "migrated" e o worker ativo
```

As migrations pendentes são aplicadas automaticamente pelo entrypoint
(`php artisan migrate --force`).

## 4. Repontar integrações externas

- **Meta WhatsApp**: a URL do webhook é `https://nfse.gtragendamentos.com.br/api/v1/whatsapp/webhook`.
  Revalide o webhook no painel da Meta (o `META_VERIFY_TOKEN` precisa ser o mesmo
  do `.env`). Confirme a assinatura do app na WABA.
- **Asaas**: aponte o webhook para `https://nfse.gtragendamentos.com.br/api/v1/fiscal/webhook/asaas`
  e confira o `ASAAS_WEBHOOK_TOKEN`.
- **DNS**: aponte `nfse.gtragendamentos.com.br` para o IP da VPS (o Traefik cuida
  do TLS).

## 5. Validar

```bash
docker compose exec app php artisan nfse:doctor --probe
```

Checar:
- `GET https://nfse.gtragendamentos.com.br/api/v1/invoices` responde 200 (HMAC).
- Um envio de template chega no celular e o status volta pelo webhook.
- Uma emissão de teste sai autorizada e a entrega (WhatsApp/e-mail/download)
  acontece nos canais habilitados.

## 6. Desligar o servidor antigo (janela de corte)

Depois de validar, mantenha o antigo em stand-by por alguns dias e só então
encerre. Não esqueça de remover o `META_VERIFY_TOKEN`/segredos do antigo quando
desativá-lo.
