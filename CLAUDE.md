# Beer Express — regras do projeto

- Chopp self-service: maquininha Android (pos.html) → api.php (PHP/MySQL, Hostinger) → ESP32 (válvula + fluxômetro). Telegram para alertas.
- **Deploy (igual WashEasy/Portaria):** push na `main` que mexe em `server/` → `gate` (ci.yml) → `deploy` por SSH + rsync (scripts/deploy-hostinger.sh) → `php api.php migrate` → health. Não edite arquivos direto no servidor — serão sobrescritos.
- **Segredos nunca no Git:** o servidor lê `server/.env` (gravado pelo deploy a partir dos secrets `BE_DB_*`; `BE_JWT_SECRET`/`BE_CRON_KEY` gerados lá). `config.php` é versionado e não tem segredo. Configurações de negócio ficam em `be_settings` (painel).
- **Banco:** mudança de tabela = novo `server/migrations/AAAAMMDD_descricao.sql` idempotente (`IF NOT EXISTS`, `INSERT IGNORE`) — o deploy aplica uma vez. Mantenha `install.sql` como o esquema completo atual.
- **Antes de todo commit:** `make gate` (segredos + lint PHP/JS + tests/run.sh). Comportamento novo → acrescente a verificação em `tests/fluxo.py`.
- Preferências: código completo e pronto para produção, dados dinâmicos (nada fixo no código), poucos arquivos consolidados (`api.php` único).
- Commits em português, mensagem curta e descritiva. Configuração única: `docs/CONFIGURAR.md`.
