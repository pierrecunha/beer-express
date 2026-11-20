<?php
/* =====================================================================
 *  BEER EXPRESS — configuração do servidor (versionado, SEM segredos)
 *
 *  Os valores vêm do arquivo server/.env NO SERVIDOR, que o deploy grava
 *  a partir dos segredos do GitHub (BE_DB_NAME, BE_DB_USER, BE_DB_PASS,
 *  BE_URL). BE_JWT_SECRET e BE_CRON_KEY são gerados sozinhos no 1º acesso.
 *  Variáveis de ambiente têm prioridade (usado nos testes do GitHub).
 *  Todo o resto (Telegram, Mercado Pago, CPF, limites) fica no painel.
 * ===================================================================== */

if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); exit; }

$__be_env_file = __DIR__ . '/.env';
$__be_env = is_file($__be_env_file) ? (parse_ini_file($__be_env_file, false, INI_SCANNER_RAW) ?: []) : [];

foreach (['BE_JWT_SECRET', 'BE_CRON_KEY'] as $__k) {
    if (!getenv($__k) && empty($__be_env[$__k])) {
        $__be_env[$__k] = bin2hex(random_bytes(32));
        if (@file_put_contents($__be_env_file, "$__k={$__be_env[$__k]}\n", FILE_APPEND | LOCK_EX) !== false) @chmod($__be_env_file, 0600);
    }
}

function be_env(string $k, string $default = ''): string {
    global $__be_env;
    $v = getenv($k);
    return $v !== false && $v !== '' ? $v : (string)($__be_env[$k] ?? $default);
}

define('BE_DB_HOST', be_env('BE_DB_HOST', 'localhost'));
define('BE_DB_NAME', be_env('BE_DB_NAME'));
define('BE_DB_USER', be_env('BE_DB_USER'));
define('BE_DB_PASS', be_env('BE_DB_PASS'));
define('BE_JWT_SECRET', be_env('BE_JWT_SECRET'));
define('BE_CRON_KEY', be_env('BE_CRON_KEY'));
define('BE_BASE_URL', rtrim(be_env('BE_BASE_URL', 'https://lavafacilexpress.com/beerexpress'), '/'));

date_default_timezone_set('America/Sao_Paulo');
