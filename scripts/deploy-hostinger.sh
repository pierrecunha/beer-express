#!/usr/bin/env bash
# Publica server/ na Hostinger por SSH + rsync. Usado pelo GitHub Actions (deploy.yml) e à mão.
#
#   HOSTINGER_SSH   usuario@ip               (hPanel → Avançado → Acesso SSH)
#   HOSTINGER_PORT  65002
#   HOSTINGER_PATH  /home/u338897690/domains/lavafacilexpress.com/public_html/beerexpress
#   BE_URL          https://lavafacilexpress.com/beerexpress   (healthcheck depois do deploy)
#   BE_DB_NAME / BE_DB_USER / BE_DB_PASS   banco MySQL (vão para o server/.env do servidor, nunca para o Git)
#   HOSTINGER_HOST_FP  impressão digital ED25519 do servidor (opcional, recomendado)
#
#   scripts/deploy-hostinger.sh --simular   -> mostra o que mudaria, não envia nada
#
# Nunca envia nem apaga server/.env (senhas + segredos gerados) nem logs. rsync SEM --delete.
set -euo pipefail
cd "$(dirname "$0")/.."
# Aceita o que vier do hPanel: "ssh -p 65002 u123@1.2.3.4", com espaços/quebras, etc. Fica só usuario@host.
HOSTINGER_SSH=$(printf '%s' "${HOSTINGER_SSH:-}" | tr -d '\r' | grep -oE '[A-Za-z0-9._-]+@[A-Za-z0-9.-]+' | tail -1 || true)
[ -z "${HOSTINGER_PORT:-}" ] || HOSTINGER_PORT=$(printf '%s' "$HOSTINGER_PORT" | tr -dc '0-9')
HOSTINGER_PATH=$(printf '%s' "${HOSTINGER_PATH:-}" | tr -d '\r' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//; s:/*$::')
BE_URL=$(printf '%s' "${BE_URL:-}" | tr -d '\r' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//; s:/*$::')
: "${HOSTINGER_SSH:?HOSTINGER_SSH vazio ou sem o formato usuario@ip}"; : "${HOSTINGER_PATH:?defina HOSTINGER_PATH}"
PORT="${HOSTINGER_PORT:-65002}"
if [ -n "${HOSTINGER_HOST_FP:-}" ]; then
  HOST="${HOSTINGER_SSH#*@}"; mkdir -p ~/.ssh
  : > /tmp/be_hostkey
  for t in 1 2 3; do
    ssh-keyscan -T 15 -p "$PORT" -t ed25519 "$HOST" 2>/dev/null > /tmp/be_hostkey || true
    [ -s /tmp/be_hostkey ] && break; sleep 5
  done
  if [ ! -s /tmp/be_hostkey ]; then
    echo "::error::Não consegui falar com o SSH em $HOST porta $PORT (3 tentativas). Confira HOSTINGER_SSH (usuario@IP do hPanel → Acesso SSH, que pode ser diferente do IP do FTP), HOSTINGER_PORT (65002) e se o Acesso SSH está ATIVO no hPanel."
    exit 1
  fi
  FP=$(ssh-keygen -lf /tmp/be_hostkey | awk '{print $2}')
  [ "$FP" = "$HOSTINGER_HOST_FP" ] || { echo "CHAVE DO SERVIDOR DIFERENTE ($FP) — deploy cancelado"; exit 1; }
  cat /tmp/be_hostkey >> ~/.ssh/known_hosts
  SSH="ssh -p $PORT -o StrictHostKeyChecking=yes"
else
  SSH="ssh -p $PORT -o StrictHostKeyChecking=accept-new"
fi
SIM=""; [ "${1:-}" = "--simular" ] && SIM="--dry-run"
echo "→ destino: ${HOSTINGER_SSH%%@*}@${HOSTINGER_SSH#*@} porta $PORT"

python3 scripts/verificar-segredos.py
scripts/lint.sh

EXCL=(--exclude '.env' --exclude '.env.*' --exclude '*.log' --exclude 'error_log' --exclude '.be_versao' --exclude '.gitkeep')
if [ -z "$SIM" ]; then
  STAMP=$(date +%Y%m%d_%H%M%S)
  echo "→ cópia de segurança do código que está no ar"
  $SSH "$HOSTINGER_SSH" "mkdir -p '$HOSTINGER_PATH' ~/beerexpress_deploys && tar -czf ~/beerexpress_deploys/antes_$STAMP.tar.gz -C '$HOSTINGER_PATH' --exclude='*.log' . && ls -1t ~/beerexpress_deploys/*.tar.gz | tail -n +16 | xargs -r rm -f"
fi
echo "→ enviando server/ ${SIM:+(simulação)}"
rsync -rlptzv --checksum $SIM "${EXCL[@]}" -e "$SSH" server/ "$HOSTINGER_SSH:$HOSTINGER_PATH/"
[ -n "$SIM" ] && exit 0

# Banco de dados: grava BE_DB_* e BE_BASE_URL no server/.env do servidor (nunca no Git).
# Os valores vão pela entrada padrão do ssh — não aparecem no log nem na linha de comando do servidor.
# BE_JWT_SECRET e BE_CRON_KEY são gerados pelo próprio config.php e preservados aqui.
if [ -n "${BE_DB_NAME:-}" ] || [ -n "${BE_DB_PASS:-}" ] || [ -n "${BE_URL:-}" ]; then
  echo "→ configuração no server/.env do servidor"
  {
    [ -n "${BE_DB_NAME:-}" ] && printf 'BE_DB_NAME=%s\n' "$(printf '%s' "$BE_DB_NAME" | tr -d '\r\n ')"
    [ -n "${BE_DB_USER:-}" ] && printf 'BE_DB_USER=%s\n' "$(printf '%s' "$BE_DB_USER" | tr -d '\r\n ')"
    [ -n "${BE_DB_PASS:-}" ] && printf 'BE_DB_PASS="%s"\n' "$(printf '%s' "$BE_DB_PASS" | tr -d '\r\n')"
    [ -n "${BE_URL:-}" ] && printf 'BE_BASE_URL=%s\n' "$BE_URL"
    true
  } | $SSH "$HOSTINGER_SSH" "cd '$HOSTINGER_PATH' && umask 077 && novo=\$(cat) && touch .env && cp .env .env.tmp &&
      for k in \$(printf '%s\n' \"\$novo\" | cut -d= -f1); do grep -v \"^\$k=\" .env.tmp > .env.tmp2 || true; mv .env.tmp2 .env.tmp; done &&
      printf '%s\n' \"\$novo\" >> .env.tmp && mv .env.tmp .env && chmod 600 .env && echo \"  chaves: \$(cut -d= -f1 .env | tr '\n' ' ')\""
fi
echo "→ banco: cria tabelas na 1ª vez e aplica migrations/ pendentes"
if ! $SSH "$HOSTINGER_SSH" "cd '$HOSTINGER_PATH' && php api.php migrate"; then
  echo "::error::Banco não preparado — veja a linha 'ERRO no banco' acima. Os arquivos já foram enviados; ao corrigir, rode o deploy de novo."
  exit 1
fi
$SSH "$HOSTINGER_SSH" "echo '$(git rev-parse --short HEAD 2>/dev/null || echo manual) $(date -Iseconds)' > '$HOSTINGER_PATH/.be_versao'"
echo "→ conferindo no servidor (php api.php health)"
if ! $SSH "$HOSTINGER_SSH" "cd '$HOSTINGER_PATH' && php api.php health"; then
  echo "SISTEMA COM ERRO NO SERVIDOR — volte com: ssh ... 'tar -xzf ~/beerexpress_deploys/antes_$STAMP.tar.gz -C $HOSTINGER_PATH'"
  exit 1
fi
if [ -n "${BE_URL:-}" ]; then
  echo "→ healthcheck pela internet ($BE_URL)"
  for i in 1 2 3 4 5; do
    R=$(curl -sS -m 10 "$BE_URL/api.php?r=health" 2>&1) && echo "$R" | grep -q OK && { echo "no ar: OK"; exit 0; }
    if echo "$R" | grep -q "Could not resolve host"; then
      echo "::warning::$BE_URL ainda não existe no DNS (subdomínio recém-criado leva alguns minutos). O sistema está publicado e funcionando no servidor."
      exit 0
    fi
    sleep 5
  done
  echo "HEALTHCHECK PELA INTERNET FALHOU: $R"
  echo "Volte com: ssh ... 'tar -xzf ~/beerexpress_deploys/antes_$STAMP.tar.gz -C $HOSTINGER_PATH'"
  exit 1
fi
