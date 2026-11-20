#!/usr/bin/env bash
# Diagnóstico (Actions → conferir). Não muda nada. Nunca imprime segredo.
set -uo pipefail
# Aceita o que vier do hPanel: "ssh -p 65002 u123@1.2.3.4", com espaços/quebras, etc. Fica só usuario@host.
HOSTINGER_SSH=$(printf '%s' "${HOSTINGER_SSH:-}" | tr -d '\r' | grep -oE '[A-Za-z0-9._-]+@[A-Za-z0-9.-]+' | tail -1 || true)
[ -z "${HOSTINGER_PORT:-}" ] || HOSTINGER_PORT=$(printf '%s' "$HOSTINGER_PORT" | tr -dc '0-9')
HOSTINGER_PATH=$(printf '%s' "${HOSTINGER_PATH:-}" | tr -d '\r' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//; s:/*$::')
BE_URL=$(printf '%s' "${BE_URL:-}" | tr -d '\r' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//; s:/*$::')
ok(){ echo "✅ $*"; }; ruim(){ echo "❌ $*"; FAL=$((FAL+1)); }; FAL=0
echo "── Hostinger"
if [ -z "${HOSTINGER_SSH_KEY:-}" ] || [ -z "${HOSTINGER_SSH:-}" ] || [ -z "${HOSTINGER_PATH:-}" ]; then
  ruim "faltam segredos HOSTINGER_SSH_KEY / HOSTINGER_SSH / HOSTINGER_PATH"
else
  install -m 700 -d ~/.ssh; printf '%s\n' "$HOSTINGER_SSH_KEY" | tr -d '\r' > ~/.ssh/h; chmod 600 ~/.ssh/h
  if PUB=$(ssh-keygen -y -f ~/.ssh/h 2>/dev/null); then
    ok "chave privada válida — pública correspondente (a que vai no hPanel → Chaves SSH):"; echo "   $PUB"
  else
    ruim "HOSTINGER_SSH_KEY não é uma chave PRIVADA completa (copie de -----BEGIN até -----END, inclusive)"
  fi
  R=$(ssh -i ~/.ssh/h -p "${HOSTINGER_PORT:-65002}" -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR -o ConnectTimeout=15 "$HOSTINGER_SSH" \
      "mkdir -p '$HOSTINGER_PATH' && cd '$HOSTINGER_PATH' && echo PASTA; [ -f api.php ] && echo API; [ -f .env ] && grep -q '^BE_DB_PASS=' .env && echo ENV; [ -f api.php ] && php api.php health >/dev/null 2>&1 && echo HEALTH; command -v rsync >/dev/null && echo RSYNC; command -v php >/dev/null && echo PHP; cat .be_versao 2>/dev/null" 2>&1)
  echo "$R" | grep -q PASTA && ok "SSH entra e a pasta existe" || ruim "SSH/pasta: $(echo "$R" | tail -1)"
  echo "$R" | grep -q RSYNC && ok "rsync disponível" || ruim "rsync não disponível no servidor"
  echo "$R" | grep -q PHP && ok "php de linha de comando disponível (cron)" || ruim "php CLI não encontrado"
  echo "$R" | grep -q API && ok "api.php no ar" || echo "➖ api.php ainda não publicado (rode o deploy)"
  echo "$R" | grep -q ENV && ok "server/.env com o banco (gravado pelo deploy)" || echo "➖ .env sem BE_DB_PASS (cadastre os segredos BE_DB_* e rode o deploy)"
  echo "$R" | grep -q HEALTH && ok "php api.php health: banco conectado e tabelas criadas" || echo "➖ health ainda falha no servidor (banco/tabelas)"
fi
if [ -n "${BE_URL:-}" ]; then
  echo "── Site"
  H=$(curl -s -m 10 -w '|%{http_code}' "$BE_URL/api.php?r=health"); HC=${H##*|}
  echo "${H%|*}" | grep -q '"OK"' && ok "health: OK" || ruim "health: HTTP $HC"
  for f in .env config.php install.sql cron_tick.php migrations/.gitkeep; do
    C=$(curl -s -o /dev/null -w '%{http_code}' -m 10 "$BE_URL/$f")
    [ "$C" = 403 ] || [ "$C" = 404 ] && ok "/$f bloqueado ($C)" || ruim "/$f ACESSÍVEL na web ($C)!"
  done
  W=$(curl -s -m 10 "$BE_URL/api.php?r=health&full"); echo "   $W"
else
  ruim "falta a variável BE_URL"
fi
echo; [ "$FAL" -eq 0 ] && echo "Tudo certo." || { echo "$FAL problema(s)."; exit 1; }
