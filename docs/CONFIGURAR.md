# Configurar GitHub + Hostinger (uma vez, ~10 min, tudo pelo navegador)

Depois disto o fluxo é igual ao do WashEasy e do Portaria Express: **alteração na `main` → GitHub testa → se passar, vai para o ar sozinho.**

Endereço: `https://lavafacilexpress.com/beerexpress` → pasta `public_html/beerexpress` (o deploy cria a pasta).

## 1. Banco de dados (hPanel, 2 min)
hPanel → **Bancos de dados → Bancos de dados MySQL** → crie:
- Banco: `u338897690_beer` · Usuário: `u338897690_beer` · uma senha forte (guarde para o passo 3).

Não precisa importar nada: o deploy cria as tabelas sozinho (`php api.php migrate`).

## 2. Chave SSH do deploy (3 min)
Use a chave do arquivo `chave-deploy-beer-express.txt` (ou a mesma chave do WashEasy/Portaria, se você a tiver guardada).
1. hPanel → **Avançado → Acesso SSH** → confira que o SSH está **ativo** → **Chaves SSH → Adicionar** → cole a linha **pública** (`ssh-ed25519 …`).
2. Guarde a parte **privada** só para o passo 3 e depois apague o arquivo.

## 3. Segredos no GitHub (3 min)
Repositório `beer-express` → **Settings → Secrets and variables → Actions** → aba **Secrets** → **New repository secret**:

| Nome | Valor |
|---|---|
| `HOSTINGER_SSH_KEY` | a chave **privada** inteira (de `-----BEGIN` até `-----END … KEY-----`) |
| `HOSTINGER_SSH` | `u338897690@IP` (o mesmo do WashEasy — hPanel → Acesso SSH; pode colar o comando inteiro `ssh -p 65002 u338897690@…`) |
| `HOSTINGER_PORT` | `65002` |
| `HOSTINGER_PATH` | `/home/u338897690/domains/lavafacilexpress.com/public_html/beerexpress` |
| `BE_URL` | `https://lavafacilexpress.com/beerexpress` |
| `BE_DB_NAME` | `u338897690_beer` |
| `BE_DB_USER` | `u338897690_beer` |
| `BE_DB_PASS` | a senha do banco do passo 1 |

O deploy grava `BE_DB_*` no `server/.env` do servidor (permissão 600, bloqueado na web, nunca no Git). As chaves internas `BE_JWT_SECRET` e `BE_CRON_KEY` são geradas sozinhas no servidor e preservadas a cada deploy.

Opcional (aviso do monitor no seu WhatsApp): `ALERT_EVO_URL`, `ALERT_EVO_TOKEN`, `ALERT_EVO_INSTANCE`, `ALERT_PHONE` — os mesmos do WashEasy.

## 4. Primeiro deploy e conferência
1. GitHub → **Actions → deploy → Run workflow** (pode marcar "Só simular" na primeira vez).
2. GitHub → **Actions → conferir → Run workflow** → tudo ✅ (`.env`, `config.php`, `install.sql` e `cron_tick.php` precisam aparecer como **bloqueados**).

## 5. Cron (hPanel, 1 min)
hPanel → **Avançado → Cron Jobs** → **a cada minuto** (`* * * * *`):
```
/usr/bin/php /home/u338897690/domains/lavafacilexpress.com/public_html/beerexpress/cron_tick.php
```
Faz: chopeira/maquininha offline, temperatura, copos travados, Pix expirado, resumo diário no Telegram e o "sinal de vida" que o monitor do GitHub confere.

## 6. Primeiro acesso (1 min)
Abra `https://lavafacilexpress.com/beerexpress/painel.html` → crie o **administrador** (nome, e-mail, senha). Essa tela só aparece uma vez — faça logo após o primeiro deploy.
Depois siga o `README.md` (Telegram, cadastro de ponto/controlador/torneiras/maquininha).

## Alterações depois
- **Pelo Claude**: peça; ele altera, roda `make gate` (segredos + sintaxe + testes), faz commit e push. O GitHub publica.
- **Banco**: mudança de tabela vira um arquivo `server/migrations/AAAAMMDD_descricao.sql` — o deploy aplica sozinho, uma única vez.
- **Voltar uma versão**: GitHub → commit → **Revert** (o deploy roda de novo). Cada deploy também guarda o código anterior em `~/beerexpress_deploys/` no servidor.
- **Monitor**: a cada 15 min o GitHub confere site, banco e cron; se cair, você recebe e-mail (e WhatsApp, com os segredos `ALERT_*`).
