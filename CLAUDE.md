# Beer Express — regras do projeto

- Chopp self-service: maquininha Android (pos.html) → api.php (PHP/MySQL, Hostinger) → ESP32 (válvula + fluxômetro). Telegram para alertas.
- **Deploy:** todo push na `main` que mexe em `server/` publica na Hostinger via GitHub Actions (FTP). Não edite arquivos direto no servidor — eles serão sobrescritos.
- `server/config.php` NUNCA vai para o Git (está no .gitignore e no exclude do deploy). Modelo: `server/config.example.php`.
- Mudança de banco: crie `server/migrations/AAAAMMDD_descricao.sql` (idempotente, `IF NOT EXISTS`) e avise que precisa rodar no phpMyAdmin; mantenha `install.sql` atualizado.
- Preferências: código completo e pronto para produção, dados dinâmicos (nada fixo no código — configurações ficam em `be_settings`), poucos arquivos consolidados (`api.php` único).
- Antes de commitar: `php -l server/*.php`.
- Commits em português, mensagem curta e descritiva.
