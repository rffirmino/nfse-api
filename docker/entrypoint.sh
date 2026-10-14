#!/bin/sh
set -e

# Publica os assets da UI do Swagger (l5-swagger) e gera o link de storage.
php artisan l5-swagger:publish --force >/dev/null 2>&1 || true
php artisan vendor:publish --tag=swagger-ui --force >/dev/null 2>&1 || true
php artisan storage:link >/dev/null 2>&1 || true

# As variáveis de ambiente (META_*, HMAC_*, DB_*, MAIL_*, ASAAS_*) são injetadas
# pelo docker-compose; não há .env versionado.
php artisan config:cache || true

# Aplica as migrations a cada subida (idempotente).
php artisan migrate --force

exec "$@"
