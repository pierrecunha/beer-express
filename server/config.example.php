<?php
/* =====================================================================
 *  BEER EXPRESS — configuração do servidor
 *  Só ficam aqui as credenciais de infraestrutura. Todo o resto
 *  (Telegram, Mercado Pago, provedor de CPF, limites, textos) é
 *  configurado pelo painel → Configurações e fica no banco.
 * ===================================================================== */

define('BE_DB_HOST', getenv('BE_DB_HOST') ?: 'localhost');
define('BE_DB_NAME', getenv('BE_DB_NAME') ?: 'u338897690_beerexpress');
define('BE_DB_USER', getenv('BE_DB_USER') ?: 'u338897690_beerexpress');
define('BE_DB_PASS', getenv('BE_DB_PASS') ?: 'TROQUE_ESTA_SENHA');

// Segredo para assinar os logins do painel (JWT). Gere um aleatório:
//   php -r "echo bin2hex(random_bytes(32));"
define('BE_JWT_SECRET', getenv('BE_JWT_SECRET') ?: 'TROQUE_POR_64_CARACTERES_ALEATORIOS');

// Chave para chamar o cron por URL (caso não use cron CLI) e para o webhook do Telegram
define('BE_CRON_KEY', getenv('BE_CRON_KEY') ?: 'TROQUE_ESTA_CHAVE_DO_CRON');

// URL pública desta pasta (sem barra no final) — usada no webhook do Mercado Pago
define('BE_BASE_URL', getenv('BE_BASE_URL') ?: 'https://lavafacilexpress.com/beerexpress');

date_default_timezone_set('America/Sao_Paulo');
