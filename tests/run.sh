#!/usr/bin/env bash
# Testes de ponta a ponta: cria um banco limpo, aplica install.sql/migrações, sobe o PHP embutido
# e roda o fluxo inteiro (maquininha simulada + ESP32 simulado). Usado pelo GitHub (ci.yml) e no `make gate`.
#
# Banco de teste: MYSQL_ADMIN (padrão no GitHub: "mysql -uroot -proot -h127.0.0.1")
set -euo pipefail
cd "$(dirname "$0")/.."
export BE_DB_HOST=${BE_DB_HOST:-127.0.0.1} BE_DB_NAME=${BE_DB_NAME:-be_teste} BE_DB_USER=${BE_DB_USER:-be_teste} BE_DB_PASS=${BE_DB_PASS:-be_teste_123}
export BE_JWT_SECRET=teste_jwt_local BE_CRON_KEY=teste_cron_local BE_BASE_URL=http://127.0.0.1:8765 TZ=America/Sao_Paulo
ADMIN=${MYSQL_ADMIN:-mysql -uroot -proot -h127.0.0.1}
$ADMIN -e "DROP DATABASE IF EXISTS \`$BE_DB_NAME\`; CREATE DATABASE \`$BE_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS '$BE_DB_USER'@'%' IDENTIFIED BY '$BE_DB_PASS'; CREATE USER IF NOT EXISTS '$BE_DB_USER'@'localhost' IDENTIFIED BY '$BE_DB_PASS';
  GRANT ALL ON \`$BE_DB_NAME\`.* TO '$BE_DB_USER'@'%'; GRANT ALL ON \`$BE_DB_NAME\`.* TO '$BE_DB_USER'@'localhost'; FLUSH PRIVILEGES;"

echo "→ migrate (1ª vez e de novo, tem que ser idempotente)"
php server/api.php migrate && php server/api.php migrate
php server/api.php health

mkdir -p tests/.run
php -S 127.0.0.1:8765 -t server > tests/.run/php.log 2>&1 &
PID=$!; trap 'kill $PID 2>/dev/null || true' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8765/api.php?r=health && break; sleep 0.2; done

python3 tests/fluxo.py http://127.0.0.1:8765/api.php
echo "→ cron"
php server/cron_tick.php
php server/api.php health | grep -q '"cron_min":0' && echo "cron: ok"
if grep -E "PHP (Fatal|Warning|Deprecated)" tests/.run/php.log; then echo "avisos do PHP acima"; exit 1; fi
echo "testes: ok"
