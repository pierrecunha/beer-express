<?php
/* =====================================================================
 *  BEER EXPRESS — API única (maquininha, ESP32, painel, webhooks)
 *  PHP 8.0+ / PDO MySQL  •  Hostinger
 *
 *  Rotas (?r=...):
 *   POS (header X-Terminal-Token)
 *     pos/pair  pos/config  pos/menu  pos/heartbeat  pos/identify
 *     pos/order  pos/paid  pos/payment_failed  pos/order_status
 *     pos/serve  pos/cancel
 *   ESP32 (header X-Device-Key)
 *     dev/poll  dev/report  dev/stray
 *   Webhooks
 *     mp/webhook  tg/webhook&key=BE_CRON_KEY
 *   Painel (Authorization: Bearer <jwt>)
 *     admin/needs_setup  admin/setup  admin/login  admin/me  admin/dashboard
 *     admin/orders  admin/order  admin/order_refund  admin/dispense_retry
 *     admin/manual_release  admin/customers  admin/customer_block
 *     admin/catalog  admin/save  admin/keg_change  admin/calibrate
 *     admin/events  admin/settings  admin/settings_save  admin/telegram_test
 *     admin/telemetry  admin/password
 * ===================================================================== */

require_once __DIR__ . '/config.php';

const BE_VERSION = '1.0.0';

/* ============================== NÚCLEO ============================== */

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host=' . BE_DB_HOST . ';dbname=' . BE_DB_NAME . ';charset=utf8mb4', BE_DB_USER, BE_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
function now(): string { return date('Y-m-d H:i:s'); }

function setting(string $k, $default = '') {
    static $cache = null;
    if ($cache === null) { $cache = []; foreach (rows('SELECT k, v FROM be_settings') as $r) $cache[$r['k']] = $r['v']; }
    return array_key_exists($k, $cache) && $cache[$k] !== null && $cache[$k] !== '' ? $cache[$k] : $default;
}
function setting_set(string $k, string $v): void {
    q('INSERT INTO be_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}

class ApiError extends Exception {
    public $http; public $code_str; public $extra;
    function __construct(string $code, string $msg, int $http = 400, array $extra = []) { parent::__construct($msg); $this->code_str = $code; $this->http = $http; $this->extra = $extra; }
}
function fail(string $code, string $msg, int $http = 400, array $extra = []) { throw new ApiError($code, $msg, $http, $extra); }

function out($data, int $http = 200): void {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function input(): array {
    static $in = null;
    if ($in !== null) return $in;
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : null;
    $in = is_array($j) ? $j : $_POST;
    return $in;
}
function arg(string $k, $default = null) { $i = input(); return $i[$k] ?? ($_GET[$k] ?? $default); }
function header_val(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim($_SERVER[$key] ?? '');
}
function client_ip(): string { return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''; }
function rand_token(int $bytes = 24): string { return bin2hex(random_bytes($bytes)); }
function money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function ml_fmt(int $ml): string { return $ml >= 1000 ? number_format($ml / 1000, 1, ',', '.') . ' L' : $ml . ' ml'; }

function log_event(string $nivel, string $tipo, string $msg, array $ctx = [], bool $telegram = false): void {
    q('INSERT INTO be_events (nivel, tipo, location_id, device_id, terminal_id, order_id, msg, dados) VALUES (?,?,?,?,?,?,?,?)', [
        $nivel, $tipo, $ctx['location_id'] ?? null, $ctx['device_id'] ?? null, $ctx['terminal_id'] ?? null, $ctx['order_id'] ?? null,
        mb_substr($msg, 0, 500), isset($ctx['dados']) ? json_encode($ctx['dados'], JSON_UNESCAPED_UNICODE) : null,
    ]);
    if ($telegram) {
        $ic = ['info' => 'ℹ️', 'aviso' => '⚠️', 'critico' => '🚨'][$nivel] ?? '';
        tg_send($ic . ' ' . $msg);
    }
}

/* ---------- HTTP externo ---------- */
function http_json(string $method, string $url, array $headers = [], $body = null, int $timeout = 12): array {
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    if ($body !== null && !is_string($body)) { $body = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $h,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp === false ? null : json_decode($resp, true), 'raw' => $resp, 'error' => $err];
}

/* ---------- Telegram ---------- */
function tg_chat_ids(): array {
    return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)setting('telegram_chat_ids')))));
}
function tg_send(string $text, ?string $chat = null): bool {
    $token = setting('telegram_token');
    if (!$token) return false;
    $ids = $chat ? [$chat] : tg_chat_ids();
    $ok = true;
    foreach ($ids as $id) {
        $r = http_json('POST', "https://api.telegram.org/bot{$token}/sendMessage", [], [
            'chat_id' => $id, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
        ], 8);
        $ok = $ok && $r['code'] === 200;
    }
    return $ok;
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ---------- JWT (painel) ---------- */
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/')); }
function jwt_make(array $payload, int $ttl = 43200): string {
    $payload['exp'] = time() + $ttl;
    $hd = b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $pl = b64u(json_encode($payload));
    return "$hd.$pl." . b64u(hash_hmac('sha256', "$hd.$pl", BE_JWT_SECRET, true));
}
function jwt_check(string $jwt): ?array {
    $p = explode('.', $jwt);
    if (count($p) !== 3) return null;
    if (!hash_equals(b64u(hash_hmac('sha256', "$p[0].$p[1]", BE_JWT_SECRET, true)), $p[2])) return null;
    $pl = json_decode(b64u_dec($p[1]), true);
    return ($pl && ($pl['exp'] ?? 0) > time()) ? $pl : null;
}
function require_admin(): array {
    $auth = header_val('Authorization');
    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) fail('auth', 'Faça login', 401);
    $pl = jwt_check(trim($m[1]));
    if (!$pl) fail('auth', 'Sessão expirada', 401);
    $u = row('SELECT id, nome, email, role FROM be_users WHERE id = ? AND ativo = 1', [$pl['uid']]);
    if (!$u) fail('auth', 'Usuário inativo', 401);
    return $u;
}

/* ============================ CPF / IDADE ============================ */

function cpf_digits(string $c): string { return preg_replace('/\D/', '', $c); }
function cpf_valido(string $cpf): bool {
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $s = 0;
        for ($i = 0; $i < $t; $i++) $s += (int)$cpf[$i] * (($t + 1) - $i);
        $d = ((10 * $s) % 11) % 10;
        if ((int)$cpf[$t] !== $d) return false;
    }
    return true;
}
function cpf_mask(string $cpf): string { return strlen($cpf) === 11 ? substr($cpf, 0, 3) . '.***.***-' . substr($cpf, 9, 2) : $cpf; }

/** Aceita "dd/mm/aaaa", "ddmmaaaa" ou "aaaa-mm-dd". Retorna Y-m-d ou null. */
function parse_nasc(string $s): ?string {
    $s = trim($s);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    else {
        $dg = preg_replace('/\D/', '', $s);
        if (strlen($dg) !== 8) return null;
        [$d, $mo, $y] = [(int)substr($dg, 0, 2), (int)substr($dg, 2, 2), (int)substr($dg, 4, 4)];
    }
    if (!checkdate($mo, $d, $y)) return null;
    $iso = sprintf('%04d-%02d-%02d', $y, $mo, $d);
    if ($iso > date('Y-m-d') || $y < (int)date('Y') - 120) return null;
    return $iso;
}
function idade(string $iso): int { return (new DateTime($iso))->diff(new DateTime('today'))->y; }

function nome_norm(string $s): array {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N']);
    $s = preg_replace('/[^A-Z ]/', ' ', $s);
    $tok = array_values(array_filter(explode(' ', $s), fn($t) => $t !== '' && !in_array($t, ['DA','DE','DO','DAS','DOS','E','DI','DU'], true)));
    return $tok;
}
/** Confere o nome digitado com o nome oficial da Receita. */
function nome_confere(string $digitado, string $oficial): bool {
    $a = nome_norm($digitado); $b = nome_norm($oficial);
    if (!$a || !$b || $a[0] !== $b[0]) return false;
    if (setting('nome_regra', 'primeiro_e_sobrenome') === 'primeiro') return true;
    if (count($a) < 2) return false;
    foreach (array_slice($a, 1) as $t) if (in_array($t, array_slice($b, 1), true)) return true;
    return false;
}
function nome_exibicao(string $nome): string {
    $t = preg_split('/\s+/', trim($nome));
    $first = mb_convert_case(mb_strtolower($t[0] ?? '', 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $last = count($t) > 1 ? mb_strtoupper(mb_substr(end($t), 0, 1, 'UTF-8'), 'UTF-8') . '.' : '';
    return trim("$first $last");
}

/**
 * Consulta o CPF + data de nascimento na base da Receita.
 * Retorna ['ok'=>true,'nome'=>..,'situacao'=>..] | ['ok'=>false,'motivo'=>'dados_nao_conferem'|'cpf_irregular'|'servico_indisponivel']
 */
function cpf_consulta(string $cpf, string $nasc): array {
    $prov = setting('cpf_provider', 'local');
    if ($prov === 'nfeio') {
        $key = setting('nfeio_api_key');
        if (!$key) return ['ok' => false, 'motivo' => 'servico_indisponivel', 'detalhe' => 'nfeio_api_key vazio'];
        $r = http_json('GET', "https://naturalperson.api.nfe.io/v1/naturalperson/serpro/status/$cpf/$nasc", ["X-NFEIO-APIKEY: $key"]);
        if ($r['code'] === 404) return ['ok' => false, 'motivo' => 'dados_nao_conferem'];
        if ($r['code'] !== 200 || empty($r['body']['name'])) return ['ok' => false, 'motivo' => 'servico_indisponivel', 'detalhe' => "HTTP {$r['code']} {$r['error']}"];
        $sit = $r['body']['status'] ?? 'Unknown';
        if (in_array($sit, ['OwnerDeceased', 'Canceled', 'Null'], true)) return ['ok' => false, 'motivo' => 'cpf_irregular', 'situacao' => $sit];
        return ['ok' => true, 'nome' => $r['body']['name'], 'situacao' => $sit];
    }
    if ($prov === 'serpro') {
        $ck = setting('serpro_consumer_key'); $cs = setting('serpro_consumer_secret');
        if (!$ck || !$cs) return ['ok' => false, 'motivo' => 'servico_indisponivel', 'detalhe' => 'credenciais Serpro vazias'];
        $tok = serpro_token($ck, $cs);
        if (!$tok) return ['ok' => false, 'motivo' => 'servico_indisponivel', 'detalhe' => 'token Serpro'];
        $dmy = date('dmY', strtotime($nasc));
        $url = strtr(setting('serpro_url'), ['{cpf}' => $cpf, '{nasc}' => $nasc, '{nasc_dmy}' => $dmy]);
        $r = http_json('GET', $url, ["Authorization: Bearer $tok"]);
        if (in_array($r['code'], [404, 422], true)) return ['ok' => false, 'motivo' => 'dados_nao_conferem'];
        if ($r['code'] !== 200 || !is_array($r['body'])) return ['ok' => false, 'motivo' => 'servico_indisponivel', 'detalhe' => "HTTP {$r['code']}"];
        $b = $r['body'];
        $sitCod = (string)($b['situacao']['codigo'] ?? $b['situacao'] ?? '0');
        if ($sitCod !== '0' && stripos($sitCod, 'regular') === false) return ['ok' => false, 'motivo' => 'cpf_irregular', 'situacao' => $sitCod];
        // Versões antigas retornam 'nascimento' (ddmmaaaa) — confere se veio
        if (!empty($b['nascimento']) && preg_replace('/\D/', '', $b['nascimento']) !== $dmy) return ['ok' => false, 'motivo' => 'dados_nao_conferem'];
        if (empty($b['nome'])) return ['ok' => false, 'motivo' => 'servico_indisponivel', 'detalhe' => 'sem nome'];
        return ['ok' => true, 'nome' => $b['nome'], 'situacao' => $sitCod];
    }
    return ['ok' => true, 'nome' => null, 'situacao' => 'local'];  // 'local' = só dígito verificador + idade
}
function serpro_token(string $ck, string $cs): ?string {
    $cached = setting('serpro_token_cache');
    if ($cached) { [$t, $exp] = explode('|', $cached) + [null, 0]; if ($t && (int)$exp > time() + 60) return $t; }
    $r = http_json('POST', 'https://gateway.apiserpro.serpro.gov.br/token',
        ['Authorization: Basic ' . base64_encode("$ck:$cs"), 'Content-Type: application/x-www-form-urlencoded'], 'grant_type=client_credentials');
    $t = $r['body']['access_token'] ?? null;
    if ($t) setting_set('serpro_token_cache', $t . '|' . (time() + (int)($r['body']['expires_in'] ?? 3000)));
    return $t;
}

/* ============================ MERCADO PAGO ============================ */

function mp_token(): string { return (string)setting('mp_access_token'); }
function mp_api(string $method, string $path, $body = null, array $extraHeaders = []): array {
    return http_json($method, 'https://api.mercadopago.com' . $path, array_merge(['Authorization: Bearer ' . mp_token()], $extraHeaders), $body, 15);
}
function mp_criar_pix(array $order, array $cust): array {
    if (!mp_token()) fail('pix_off', 'Pix não configurado (token Mercado Pago)', 500);
    $nomes = preg_split('/\s+/', trim($cust['nome']));
    $email = setting('pix_email_padrao', 'cliente@' . (parse_url(BE_BASE_URL, PHP_URL_HOST) ?: 'beerexpress.com.br'));
    $exp = (new DateTime('+' . (int)setting('pix_expira_min', 5) . ' minutes'))->format('Y-m-d\TH:i:s.000P');
    $r = mp_api('POST', '/v1/payments', [
        'transaction_amount' => (float)$order['total'],
        'description' => setting('marca_nome', 'Beer Express') . ' ' . $order['ref'],
        'payment_method_id' => 'pix',
        'external_reference' => $order['ref'],
        'notification_url' => BE_BASE_URL . '/api.php?r=mp/webhook',
        'date_of_expiration' => $exp,
        'payer' => ['email' => $email, 'first_name' => $nomes[0] ?? 'Cliente', 'last_name' => count($nomes) > 1 ? end($nomes) : '']
            + (!empty($cust['cpf']) ? ['identification' => ['type' => 'CPF', 'number' => $cust['cpf']]] : []),
    ], ['X-Idempotency-Key: ' . $order['ref']]);
    $td = $r['body']['point_of_interaction']['transaction_data'] ?? null;
    if (!in_array($r['code'], [200, 201], true) || !$td) {
        log_event('aviso', 'pix_erro', "Falha ao gerar Pix do pedido {$order['ref']}: HTTP {$r['code']}", ['order_id' => $order['id'], 'dados' => $r['body']]);
        fail('pix_erro', 'Não foi possível gerar o Pix agora. Tente cartão.', 502);
    }
    return ['id' => (string)$r['body']['id'], 'qr_base64' => $td['qr_code_base64'] ?? '', 'copia_cola' => $td['qr_code'] ?? ''];
}
/** Confirma no Mercado Pago que existe pagamento aprovado com esse external_reference. */
function mp_pagamento_aprovado_ref(string $ref, float $total): ?array {
    if (!mp_token()) return null;
    $r = mp_api('GET', '/v1/payments/search?sort=date_created&criteria=desc&external_reference=' . urlencode($ref));
    foreach (($r['body']['results'] ?? []) as $p) {
        if (($p['status'] ?? '') === 'approved' && abs((float)$p['transaction_amount'] - $total) < 0.01) return $p;
    }
    return null;
}

/* ============================ REGRAS DE NEGÓCIO ============================ */

function device_online(array $d): bool {
    return $d['last_seen'] && strtotime($d['last_seen']) >= time() - 60 * (int)setting('offline_minutos', 3);
}
function ml_hoje_cliente(int $cid): int {
    return (int)val("SELECT COALESCE(SUM(total_ml),0) FROM be_orders WHERE customer_id = ? AND tipo = 'copo' AND status IN ('pago','servindo','concluido','parcial') AND created_at >= CURDATE()", [$cid])
         + (int)val("SELECT COALESCE(SUM(CASE WHEN status = 'aberta' THEN limite_ml ELSE servido_ml END),0) FROM be_pours WHERE customer_id = ? AND inicio_em >= CURDATE()", [$cid]);
}

/** Pedido pago → cria a fila de copos (dispenses), atualiza cliente, notifica. */
function pedido_marcar_pago(array $o, array $pg): void {
    $pdo = db();
    $pdo->beginTransaction();
    $cur = row('SELECT * FROM be_orders WHERE id = ? FOR UPDATE', [$o['id']]);
    if ($cur['status'] !== 'aguardando_pagamento') { $pdo->commit(); return; } // idempotente
    q('UPDATE be_orders SET status = "pago", pago_em = NOW(), metodo = COALESCE(?, metodo), pagamento_id = ?, nsu = ?, bandeira = ?, final_cartao = ?, verificado_servidor = ? WHERE id = ?', [
        $pg['metodo'] ?? null, $pg['pagamento_id'] ?? null, $pg['nsu'] ?? null, $pg['bandeira'] ?? null, $pg['final_cartao'] ?? null, (int)($pg['verificado'] ?? 0), $o['id'],
    ]);
    if (($cur['tipo'] ?? 'copo') === 'recarga') {           // Modo Pass: credita a carteira
        $saldo = carteira_mov((int)$cur['customer_id'], 'recarga', (float)$cur['total'], ['order_id' => $cur['id'], 'obs' => 'Recarga ' . $cur['ref']]);
        if ((float)$cur['bonus'] > 0) $saldo = carteira_mov((int)$cur['customer_id'], 'bonus', (float)$cur['bonus'], ['order_id' => $cur['id'], 'obs' => 'Bônus da recarga ' . $cur['ref']]);
        q('UPDATE be_orders SET status = "concluido", concluido_em = NOW() WHERE id = ?', [$cur['id']]);
        $pdo->commit();
        if (setting('tg_notificar_vendas', '1') === '1') {
            $cli = row('SELECT nome, cpf FROM be_customers WHERE id = ?', [$cur['customer_id']]);
            $loc = val('SELECT nome FROM be_locations WHERE id = ?', [$cur['location_id']]);
            $met = ['credito' => '💳 Crédito', 'debito' => '💳 Débito', 'pix' => '⚡ Pix'][$pg['metodo'] ?? $cur['metodo']] ?? '-';
            tg_send("💰 <b>Recarga " . money($cur['total']) . "</b>" . ((float)$cur['bonus'] > 0 ? " + bônus " . money($cur['bonus']) : '') . " — " . h((string)$loc) . "\n$met"
                . ($cli ? "\n👤 " . h(nome_exibicao($cli['nome'])) . " (" . cpf_mask($cli['cpf']) . ") — saldo " . money($saldo) : ''));
        }
        return;
    }
    if (!empty($cur['cupom'])) q('UPDATE be_coupons SET usos = usos + 1 WHERE codigo = ?', [$cur['cupom']]);
    $seq = 0;
    foreach (rows('SELECT i.*, t.device_id FROM be_order_items i JOIN be_taps t ON t.id = i.tap_id WHERE i.order_id = ? ORDER BY i.id', [$o['id']]) as $it) {
        for ($n = 0; $n < (int)$it['qtd']; $n++) {
            q('INSERT INTO be_dispenses (order_id, item_id, tap_id, device_id, seq, volume_ml) VALUES (?,?,?,?,?,?)',
                [$o['id'], $it['id'], $it['tap_id'], $it['device_id'], ++$seq, $it['volume_ml']]);
        }
    }
    if ($o['customer_id']) q('UPDATE be_customers SET compras = compras + 1, total_ml = total_ml + ?, total_gasto = total_gasto + ?, ultima_compra = NOW() WHERE id = ?',
        [$o['total_ml'], $o['total'], $o['customer_id']]);
    $pdo->commit();

    if (setting('tg_notificar_vendas', '1') === '1' && ((float)$o['total'] > 0 || !empty($o['cupom']))) {
        $loc = val('SELECT nome FROM be_locations WHERE id = ?', [$o['location_id']]);
        $cli = $o['customer_id'] ? row('SELECT nome, cpf, compras FROM be_customers WHERE id = ?', [$o['customer_id']]) : null;
        $itens = rows('SELECT produto, volume_ml, qtd FROM be_order_items WHERE order_id = ?', [$o['id']]);
        $lin = implode("\n", array_map(fn($i) => "• {$i['qtd']}× " . h($i['produto']) . " {$i['volume_ml']} ml", $itens));
        $met = ['credito' => '💳 Crédito', 'debito' => '💳 Débito', 'pix' => '⚡ Pix'][$pg['metodo'] ?? $o['metodo']] ?? '🎟️ Cupom';
        if (!empty($o['cupom'])) $met .= " • cupom " . h($o['cupom']) . " (−" . money($o['desconto']) . ")";
        $hoje = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM be_orders WHERE status IN ('pago','servindo','concluido','parcial') AND created_at >= CURDATE() AND location_id = ?", [$o['location_id']]);
        tg_send("🍺 <b>Venda " . money($o['total']) . "</b> — " . h((string)$loc) . "\n$lin\n$met" . (!empty($pg['bandeira']) ? ' ' . h($pg['bandeira']) . ' •••' . h((string)$pg['final_cartao']) : '') .
            ($cli ? "\n👤 " . h(nome_exibicao($cli['nome'])) . " (" . cpf_mask($cli['cpf']) . ") — {$cli['compras']}ª compra" : '') .
            (empty($pg['verificado']) && ($pg['metodo'] ?? '') !== 'pix' && mp_token() ? "\n⚠️ Pagamento não confirmado na API do MP" : '') .
            "\n📊 Hoje: {$hoje['n']} vendas • " . money($hoje['s']));
    }
}

/** Recalcula o status do pedido a partir dos copos. */
function pedido_recalcular(int $orderId): void {
    $o = row('SELECT * FROM be_orders WHERE id = ?', [$orderId]);
    if (!$o || in_array($o['status'], ['aguardando_pagamento', 'cancelado', 'estornado'], true)) return;
    $todos = rows('SELECT id, seq, status, servido_ml, volume_ml FROM be_dispenses WHERE order_id = ? ORDER BY id', [$orderId]);
    if (!$todos) return;
    // Um copo pode ter sido reliberado (nova linha com o volume que faltou): soma tudo do mesmo copo (seq)
    $porSeq = [];
    foreach ($todos as $d) {
        $s = &$porSeq[$d['seq']];
        $s['volume_ml'] = $s['volume_ml'] ?? (int)$d['volume_ml'];          // volume original = 1ª linha
        $s['servido_ml'] = ($s['servido_ml'] ?? 0) + (int)$d['servido_ml'];
        if ($d['status'] !== 'cancelado') $s['status'] = $d['status'];        // status da tentativa ativa
        else $s['status'] = $s['status'] ?? 'cancelado';
        unset($s);
    }
    $ds = array_values($porSeq);
    $abertos = array_filter($ds, fn($d) => in_array($d['status'], ['pendente', 'armado', 'liberado', 'servindo'], true));
    if ($abertos) {
        $novo = array_filter($ds, fn($d) => $d['status'] !== 'pendente') ? 'servindo' : 'pago';
        if ($o['status'] !== $novo) q('UPDATE be_orders SET status = ? WHERE id = ?', [$novo, $orderId]);
        return;
    }
    $ok = count(array_filter($ds, fn($d) => $d['status'] === 'concluido' && $d['servido_ml'] >= $d['volume_ml'] * 0.9));
    $novo = $ok === count($ds) ? 'concluido' : ($ok > 0 || array_sum(array_column($ds, 'servido_ml')) > 0 ? 'parcial' : 'falhou');
    q('UPDATE be_orders SET status = ?, concluido_em = NOW() WHERE id = ?', [$novo, $orderId]);
    if ($novo !== 'concluido') {
        $loc = val('SELECT nome FROM be_locations WHERE id = ?', [$o['location_id']]);
        log_event('critico', 'pedido_' . $novo, "Pedido {$o['ref']} (" . money($o['total']) . ") terminou como " . strtoupper($novo) . " em $loc — servido "
            . ml_fmt((int)array_sum(array_column($ds, 'servido_ml'))) . " de " . ml_fmt((int)$o['total_ml']) . ". Avalie estorno ou reliberação no painel.",
            ['order_id' => $orderId, 'location_id' => $o['location_id']], true);
    }
}

function tap_baixar_estoque(int $tapId, int $ml): void {
    if ($ml <= 0) return;
    q('UPDATE be_taps SET restante_ml = GREATEST(0, restante_ml - ?) WHERE id = ?', [$ml, $tapId]);
    $t = row('SELECT t.*, p.nome produto, d.location_id FROM be_taps t LEFT JOIN be_products p ON p.id = t.product_id JOIN be_devices d ON d.id = t.device_id WHERE t.id = ?', [$tapId]);
    $pct = $t['barril_ml'] > 0 ? 100 * $t['restante_ml'] / $t['barril_ml'] : 0;
    if (!$t['alerta_enviado'] && $pct <= (int)$t['alerta_pct']) {
        q('UPDATE be_taps SET alerta_enviado = 1 WHERE id = ?', [$tapId]);
        log_event('aviso', 'barril_baixo', "🛢️ Barril acabando: {$t['nome']} (" . ($t['produto'] ?? '-') . ") com " . ml_fmt((int)$t['restante_ml']) . " (" . round($pct) . "%)",
            ['device_id' => $t['device_id'], 'location_id' => $t['location_id']], true);
    }
}

/* ============================ CARTEIRA (MODO PASS) ============================ */

/** Movimenta a carteira com extrato. Valor positivo = crédito, negativo = débito. Retorna o novo saldo. */
function carteira_mov(int $cid, string $tipo, float $valor, array $ref = []): float {
    $propria = !db()->inTransaction();
    if ($propria) db()->beginTransaction();
    $saldo = (float)val('SELECT saldo FROM be_customers WHERE id = ? FOR UPDATE', [$cid]);
    $novo = round($saldo + $valor, 2);
    q('UPDATE be_customers SET saldo = ? WHERE id = ?', [$novo, $cid]);
    q('INSERT INTO be_ledger (customer_id, tipo, valor, saldo_apos, order_id, pour_id, obs) VALUES (?,?,?,?,?,?,?)',
        [$cid, $tipo, round($valor, 2), $novo, $ref['order_id'] ?? null, $ref['pour_id'] ?? null, isset($ref['obs']) ? mb_substr($ref['obs'], 0, 255) : null]);
    if ($propria) db()->commit();
    return $novo;
}
/** "100:5,150:10" → recarga de R$150 ganha R$10 (maior faixa atingida). */
function recarga_bonus(float $valor): float {
    $b = 0.0;
    foreach (array_filter(array_map('trim', explode(',', (string)setting('recarga_bonus')))) as $faixa) {
        [$min, $bon] = array_map('floatval', explode(':', $faixa) + [0, 0]);
        if ($valor >= $min && $bon > $b) $b = $bon;
    }
    return round($b, 2);
}
function recarga_valores(): array {
    return array_values(array_filter(array_map(fn($v) => round((float)str_replace(',', '.', trim($v)), 2), explode(',', (string)setting('recarga_valores', '20,50,100'))), fn($v) => $v > 0));
}
/** Valor comprometido em servidas abertas (o cliente não gasta o mesmo saldo em 2 torneiras ao mesmo tempo). */
function saldo_reservado(int $cid, int $excetoPour = 0): float {
    return (float)val("SELECT COALESCE(SUM(limite_ml * preco_litro / 1000), 0) FROM be_pours WHERE customer_id = ? AND status = 'aberta' AND id <> ?", [$cid, $excetoPour]);
}
/** Fecha uma servida: cobra só o que saiu, baixa o barril, atualiza o cliente. Idempotente. */
function pour_fechar(int $pourId, int $ml, int $pulsos, string $motivo, string $status = 'fechada'): array {
    db()->beginTransaction();
    $p = row('SELECT * FROM be_pours WHERE id = ? FOR UPDATE', [$pourId]);
    if (!$p || $p['status'] !== 'aberta') { db()->commit(); return $p ?: []; }
    $ml = max(0, min($ml, (int)$p['limite_ml'] + 50));                 // tolerância de escorrimento após fechar
    $valor = round(min($ml, (int)$p['limite_ml']) * (float)$p['preco_litro'] / 1000, 2);   // nunca cobra além do limite reservado
    q('UPDATE be_pours SET status = ?, servido_ml = ?, pulsos = ?, valor = ?, motivo_fim = ?, fim_em = NOW(), atualizado_em = NOW() WHERE id = ?',
        [$status, $ml, $pulsos, $valor, mb_substr($motivo, 0, 60), $pourId]);
    $saldo = $valor > 0 ? carteira_mov((int)$p['customer_id'], 'consumo', -$valor, ['pour_id' => $pourId, 'obs' => "{$p['produto']} " . ml_fmt($ml)])
                        : (float)val('SELECT saldo FROM be_customers WHERE id = ?', [$p['customer_id']]);
    if ($ml > 0) q('UPDATE be_customers SET compras = compras + 1, total_ml = total_ml + ?, total_gasto = total_gasto + ?, ultima_compra = NOW() WHERE id = ?', [$ml, $valor, $p['customer_id']]);
    db()->commit();
    tap_baixar_estoque((int)$p['tap_id'], $ml);
    $p = row('SELECT * FROM be_pours WHERE id = ?', [$pourId]);
    $p['saldo'] = $saldo;
    return $p;
}

/* ============================== ROTAS POS ============================== */

function auth_terminal(): array {
    $tok = header_val('X-Terminal-Token') ?: (string)arg('terminal_token', '');
    if (!$tok) fail('terminal', 'Maquininha não pareada', 401);
    $t = row('SELECT t.*, l.nome location_nome FROM be_terminals t JOIN be_locations l ON l.id = t.location_id WHERE t.token = ? AND t.ativo = 1', [$tok]);
    if (!$t) fail('terminal', 'Maquininha não autorizada', 401);
    if (!$t['online']) log_event('info', 'terminal_online', "📱 Maquininha {$t['nome']} ({$t['location_nome']}) voltou a ficar online", ['terminal_id' => $t['id'], 'location_id' => $t['location_id']]);
    q('UPDATE be_terminals SET last_seen = NOW(), online = 1 WHERE id = ?', [$t['id']]);
    return $t;
}

function r_pos_pair(): array {
    $codigo = strtoupper(trim((string)arg('codigo')));
    $t = row('SELECT * FROM be_terminals WHERE codigo = ? AND ativo = 1', [$codigo]);
    if (!$t) fail('codigo', 'Código de pareamento inválido');
    $tok = rand_token();
    q('UPDATE be_terminals SET token = ?, serial = ?, app_version = ?, last_seen = NOW(), online = 1 WHERE id = ?',
        [$tok, substr((string)arg('serial', ''), 0, 64), substr((string)arg('app_version', ''), 0, 20), $t['id']]);
    log_event('info', 'terminal_pareado', "Maquininha {$t['nome']} pareada (serial " . arg('serial', '-') . ")", ['terminal_id' => $t['id'], 'location_id' => $t['location_id']], true);
    return ['ok' => true, 'token' => $tok, 'provider' => $t['provider'], 'nome' => $t['nome']];
}

function r_pos_config(array $t): array {
    $metodos = [];
    if ($t['aceita_credito']) $metodos[] = 'credito';
    if ($t['aceita_debito']) $metodos[] = 'debito';
    if ($t['aceita_pix'] && (pix_nativo($t) || ($t['provider'] !== 'simulado' ? mp_token() : true))) $metodos[] = 'pix';
    return [
        'ok' => true,
        'marca' => ['nome' => setting('marca_nome', 'Beer Express'), 'slogan' => setting('marca_slogan'), 'cor' => setting('marca_cor', '#F5B301')],
        'terminal' => ['id' => (int)$t['id'], 'nome' => $t['nome'], 'provider' => $t['provider'], 'local' => $t['location_nome']],
        'metodos' => $metodos,
        'idade_minima' => (int)setting('idade_minima', 18),
        'nome_regra' => setting('nome_regra', 'primeiro_e_sobrenome'),
        'limite_copos_pedido' => (int)setting('limite_copos_pedido', 4),
        'msg_final' => setting('msg_final'),
        'verificacao' => setting('verificacao_idade', 'cpf') === 'declaracao' ? 'declaracao' : 'cpf',
        'termos' => setting('termos_texto', 'Para prosseguir com a compra de chope você deve concordar com os termos de uso e que tem mais de 18 anos.'),
        'ajuda' => setting('ajuda_texto'),
        'cupons' => setting('cupons_ativo', '1') === '1' && (int)val('SELECT COUNT(*) FROM be_coupons WHERE ativo = 1') > 0,
        'pix_nativo' => pix_nativo($t),
        'tap_id' => $t['tap_id'] !== null ? (int)$t['tap_id'] : null,
        'server_time' => now(),
    ];
}
/** Pix pago na própria maquininha (PagBank) em vez do QR gerado pelo servidor. */
function pix_nativo(array $t): bool { return $t['provider'] === 'pagbank' && setting('pix_modo', 'api_mp') === 'maquininha'; }
/** Preço de referência por 100 ml (como o BeerPass mostra na tela da torneira): menor preço proporcional entre os tamanhos. */
function preco_100ml(array $ofertas): ?float {
    $v = null;
    foreach ($ofertas as $o) if ($o['volume_ml'] > 0) { $x = $o['preco'] * 100 / $o['volume_ml']; $v = $v === null ? $x : min($v, $x); }
    return $v === null ? null : round($v, 2);
}

function r_pos_menu(array $t): array {
    $taps = rows('SELECT t.*, p.nome produto, p.cervejaria, p.estilo, p.descricao, p.abv, p.ibu, p.imagem_url, p.cor, d.last_seen, d.start_mode, d.ativo dev_ativo
        FROM be_taps t JOIN be_devices d ON d.id = t.device_id LEFT JOIN be_products p ON p.id = t.product_id
        WHERE d.location_id = ? AND t.ativo = 1 AND p.ativo = 1 AND (? IS NULL OR t.id = ?) ORDER BY d.id, t.canal', [$t['location_id'], $t['tap_id'], $t['tap_id']]);
    $out = [];
    foreach ($taps as $tp) {
        $on = $tp['dev_ativo'] && device_online($tp);
        $offers = rows('SELECT id, volume_ml, preco FROM be_offers WHERE tap_id = ? AND ativo = 1 ORDER BY ordem, volume_ml', [$tp['id']]);
        $offers = array_map(fn($o) => ['id' => (int)$o['id'], 'volume_ml' => (int)$o['volume_ml'], 'preco' => (float)$o['preco'],
            'disponivel' => $on && (int)$tp['restante_ml'] >= (int)$o['volume_ml']], $offers);
        $out[] = [
            'tap_id' => (int)$tp['id'], 'torneira' => $tp['nome'], 'canal' => (int)$tp['canal'], 'produto' => $tp['produto'], 'estilo' => $tp['estilo'],
            'descricao' => $tp['descricao'], 'abv' => $tp['abv'] !== null ? (float)$tp['abv'] : null, 'imagem_url' => $tp['imagem_url'], 'cor' => $tp['cor'] ?: '#F5B301',
            'cervejaria' => $tp['cervejaria'], 'ibu' => $tp['ibu'] !== null ? (int)$tp['ibu'] : null, 'preco_100ml' => preco_100ml($offers),
            'online' => $on, 'start_mode' => $tp['start_mode'], 'ofertas' => $offers,
            'disponivel' => $on && count(array_filter($offers, fn($o) => $o['disponivel'])) > 0,
        ];
    }
    return ['ok' => true, 'torneiras' => $out];
}

function r_pos_identify(array $t): array {
    if ($t['bloqueado_ate'] && strtotime($t['bloqueado_ate']) > time())
        fail('terminal_bloqueado', 'Muitas tentativas inválidas. Aguarde alguns minutos.', 429, ['ate' => $t['bloqueado_ate']]);

    $cpf = cpf_digits((string)arg('cpf', ''));
    $nascRaw = (string)arg('nascimento', '');
    $nome = trim((string)arg('nome', ''));
    $ctx = ['terminal_id' => $t['id'], 'location_id' => $t['location_id']];

    $falha = function (string $motivo, string $msg, array $extra = []) use ($t, $cpf, $ctx) {
        q('INSERT INTO be_verifications (terminal_id, cpf, ok, motivo) VALUES (?,?,0,?)', [$t['id'], $cpf ?: null, $motivo]);
        if (in_array($motivo, ['cpf_invalido', 'dados_nao_conferem', 'nome_nao_confere', 'nascimento_invalido'], true)) {
            $n = (int)val("SELECT COUNT(*) FROM be_verifications WHERE terminal_id = ? AND ok = 0 AND motivo IN ('cpf_invalido','dados_nao_conferem','nome_nao_confere','nascimento_invalido') AND created_at >= NOW() - INTERVAL 10 MINUTE", [$t['id']]);
            if ($n >= (int)setting('max_falhas_verificacao', 5)) {
                $min = (int)setting('bloqueio_minutos', 10);
                q('UPDATE be_terminals SET bloqueado_ate = NOW() + INTERVAL ' . $min . ' MINUTE WHERE id = ?', [$t['id']]);
                log_event('critico', 'terminal_bloqueado', "🔒 {$t['nome']} ({$t['location_nome']}) bloqueada por $min min após $n tentativas de identificação inválidas (possível tentativa de burlar a verificação de idade).", $ctx, true);
            }
        }
        fail($motivo, $msg, 422, $extra);
    };

    if (!filter_var(arg('declara_maior', false), FILTER_VALIDATE_BOOLEAN)) $falha('sem_declaracao', 'É preciso declarar ter 18 anos ou mais.');
    if (setting('verificacao_idade', 'cpf') === 'declaracao' && $cpf === '') {   // modo BeerPass: só o "Sim, concordo"
        $token = rand_token(16);
        q('INSERT INTO be_verifications (terminal_id, ok, motivo, token, expira_em) VALUES (?,1,"declaracao",?, NOW() + INTERVAL 10 MINUTE)', [$t['id'], $token]);
        return ['ok' => true, 'token' => $token, 'nome' => null, 'novo' => false, 'compras' => 0, 'restante_ml_dia' => 999999, 'declaracao' => true];
    }
    if (!cpf_valido($cpf)) $falha('cpf_invalido', 'CPF inválido. Confira os números.');
    $nasc = parse_nasc($nascRaw);
    if (!$nasc) $falha('nascimento_invalido', 'Data de nascimento inválida.');
    $minima = (int)setting('idade_minima', 18);
    if (idade($nasc) < $minima) {
        if (setting('tg_notificar_tentativa_menor', '1') === '1')
            log_event('aviso', 'tentativa_menor', "🔞 Tentativa de compra por menor de idade em {$t['location_nome']} ({$t['nome']}) — CPF " . cpf_mask($cpf) . ", " . idade($nasc) . " anos. Venda bloqueada.", $ctx, true);
        $falha('menor_idade', "Venda proibida para menores de $minima anos.");
    }

    $cust = row('SELECT * FROM be_customers WHERE cpf = ?', [$cpf]);
    if ($cust && $cust['bloqueado']) $falha('bloqueado', 'Cadastro bloqueado. Procure o responsável pelo ponto.');
    if ($cust && $cust['nascimento'] !== $nasc) $falha('dados_nao_conferem', 'CPF e data de nascimento não conferem.');

    $validoAte = $cust ? strtotime($cust['verificado_em'] . ' +' . (int)setting('reverificar_dias', 365) . ' days') : 0;
    $precisaVerificar = !$cust || $validoAte < time() || (setting('cpf_provider', 'local') !== 'local' && $cust['verificado_por'] === 'local');

    if ($precisaVerificar) {
        if ($nome === '') {
            q('INSERT INTO be_verifications (terminal_id, cpf, ok, motivo) VALUES (?,?,0,?)', [$t['id'], $cpf, 'precisa_nome']);
            return ['ok' => false, 'need_name' => true, 'motivo' => 'precisa_nome', 'mensagem' => 'Primeiro acesso: digite seu nome completo.'];
        }
        if (count(nome_norm($nome)) < (setting('nome_regra') === 'primeiro' ? 1 : 2)) $falha('nome_incompleto', 'Digite nome e sobrenome.');
        $res = cpf_consulta($cpf, $nasc);
        if (!$res['ok'] && $res['motivo'] === 'servico_indisponivel') {
            log_event('aviso', 'cpf_api_erro', 'Consulta de CPF indisponível: ' . ($res['detalhe'] ?? ''), $ctx, true);
            if (setting('falha_api_permite_local', '0') !== '1') $falha('servico_indisponivel', 'Validação de CPF indisponível no momento. Tente em instantes.');
            $res = ['ok' => true, 'nome' => null, 'situacao' => 'local'];
        }
        if (!$res['ok']) $falha($res['motivo'], $res['motivo'] === 'cpf_irregular' ? 'CPF com situação irregular na Receita Federal.' : 'CPF e data de nascimento não conferem com a Receita Federal.');
        if ($res['nome'] !== null && !nome_confere($nome, $res['nome'])) $falha('nome_nao_confere', 'O nome não confere com o CPF informado.');
        $nomeFinal = $res['nome'] ?? mb_strtoupper($nome, 'UTF-8');
        $por = $res['situacao'] === 'local' ? 'local' : setting('cpf_provider');
        q('INSERT INTO be_customers (cpf, nome, nascimento, verificado_por, verificado_em) VALUES (?,?,?,?,NOW())
           ON DUPLICATE KEY UPDATE nome = VALUES(nome), nascimento = VALUES(nascimento), verificado_por = VALUES(verificado_por), verificado_em = NOW()',
            [$cpf, $nomeFinal, $nasc, $por]);
        $cust = row('SELECT * FROM be_customers WHERE cpf = ?', [$cpf]);
    }

    $limite = (int)setting('limite_ml_dia_cpf', 3000);
    $usado = ml_hoje_cliente((int)$cust['id']);
    $restante = $limite > 0 ? max(0, $limite - $usado) : 999999;
    if ($limite > 0 && $restante < 300) $falha('limite_diario', 'Limite diário de consumo atingido para este CPF. Volte amanhã!');

    $token = rand_token(16);
    q('INSERT INTO be_verifications (terminal_id, cpf, ok, motivo, token, customer_id, expira_em) VALUES (?,?,1,?,?,?, NOW() + INTERVAL 10 MINUTE)',
        [$t['id'], $cpf, $precisaVerificar ? 'verificado' : 'cliente_conhecido', $token, $cust['id']]);
    return ['ok' => true, 'token' => $token, 'nome' => nome_exibicao($cust['nome']), 'novo' => $precisaVerificar && (int)$cust['compras'] === 0,
        'compras' => (int)$cust['compras'], 'restante_ml_dia' => $restante];
}

/** Sessão do cliente na maquininha (após identificação 18+). Vale 10 min e se renova a cada uso. */
function pos_sessao(array $t): array {
    $ver = row('SELECT * FROM be_verifications WHERE token = ? AND terminal_id = ? AND ok = 1', [(string)arg('token'), $t['id']]);
    if (!$ver || $ver['usado'] || strtotime($ver['expira_em']) < time()) fail('sessao', 'Sessão expirada. Identifique-se novamente.', 401);
    $cust = $ver['customer_id'] ? row('SELECT * FROM be_customers WHERE id = ?', [$ver['customer_id']])
        : ['id' => 0, 'cpf' => '', 'nome' => 'Cliente', 'saldo' => 0, 'bloqueado' => 0, 'compras' => 0];   // modo declaração (sem CPF)
    if (!$cust || $cust['bloqueado']) fail('bloqueado', 'Cadastro bloqueado. Procure o responsável pelo ponto.', 403);
    q('UPDATE be_verifications SET expira_em = NOW() + INTERVAL 10 MINUTE WHERE id = ?', [$ver['id']]);
    return [$ver, $cust];
}

/** Cupom de desconto: valida e devolve [desconto, cupom]. */
function cupom_aplicar(string $codigo, float $subtotal): array {
    $codigo = strtoupper(trim($codigo));
    if ($codigo === '') return [0.0, null];
    if (setting('cupons_ativo', '1') !== '1') fail('cupom', 'Cupons desativados');
    $c = row('SELECT * FROM be_coupons WHERE codigo = ? AND ativo = 1', [$codigo]);
    if (!$c) fail('cupom', 'Cupom inválido');
    if ($c['validade'] && $c['validade'] < date('Y-m-d')) fail('cupom', 'Cupom vencido');
    if ($c['usos_max'] !== null && (int)$c['usos'] >= (int)$c['usos_max']) fail('cupom', 'Cupom esgotado');
    if ($subtotal < (float)$c['minimo']) fail('cupom', 'Cupom válido para compras a partir de ' . money($c['minimo']));
    $d = $c['tipo'] === 'percentual' ? $subtotal * min(100, (float)$c['valor']) / 100 : (float)$c['valor'];
    return [round(min($d, $subtotal), 2), $c];
}

/** Valida os itens do carrinho (estoque, chopeira online, limites) e calcula os valores. */
function pedido_calcular(array $t, array $cust, $itens): array {
    if (!is_array($itens) || !$itens) fail('itens', 'Carrinho vazio');
    $copos = 0; $total = 0; $totalMl = 0; $linhas = []; $porTap = [];
    foreach ($itens as $i) {
        $qtd = max(1, (int)($i['qtd'] ?? 1));
        $of = row('SELECT o.*, t.device_id, t.restante_ml, t.ativo tap_ativo, p.nome produto, d.last_seen, d.location_id, d.ativo dev_ativo
            FROM be_offers o JOIN be_taps t ON t.id = o.tap_id JOIN be_devices d ON d.id = t.device_id LEFT JOIN be_products p ON p.id = t.product_id
            WHERE o.id = ? AND o.ativo = 1', [(int)($i['offer_id'] ?? 0)]);
        if (!$of || !$of['tap_ativo'] || !$of['dev_ativo'] || (int)$of['location_id'] !== (int)$t['location_id']
            || ($t['tap_id'] !== null && (int)$of['tap_id'] !== (int)$t['tap_id'])) fail('oferta', 'Produto indisponível');
        if (!device_online($of)) fail('offline', 'Chopeira momentaneamente offline. Tente em instantes.');
        $porTap[$of['tap_id']] = ($porTap[$of['tap_id']] ?? 0) + $of['volume_ml'] * $qtd;
        if ($porTap[$of['tap_id']] > (int)$of['restante_ml']) fail('estoque', "Não há {$of['produto']} suficiente no barril.");
        $copos += $qtd; $total += $of['preco'] * $qtd; $totalMl += $of['volume_ml'] * $qtd;
        $linhas[] = [$of, $qtd];
    }
    if ($copos > (int)setting('limite_copos_pedido', 4)) fail('limite', 'Máximo de ' . setting('limite_copos_pedido', 4) . ' copos por pedido.');
    $limite = (int)setting('limite_ml_dia_cpf', 3000);
    if ($limite > 0 && $cust['id'] && ml_hoje_cliente((int)$cust['id']) + $totalMl > $limite) fail('limite_diario', 'Esse pedido ultrapassa o limite diário de ' . ml_fmt($limite) . ' por CPF.');
    return [$linhas, round($total, 2), $totalMl, $copos];
}

/** Prévia do cupom na tela de resumo. */
function r_pos_coupon(array $t): array {
    [, $cust] = pos_sessao($t);
    [, $sub] = pedido_calcular($t, $cust, arg('itens', []));
    [$desc, $c] = cupom_aplicar((string)arg('codigo'), $sub);
    return ['ok' => true, 'codigo' => $c['codigo'] ?? null, 'descricao' => $c['descricao'] ?? null, 'subtotal' => $sub, 'desconto' => $desc, 'total' => round($sub - $desc, 2)];
}

function r_pos_order(array $t): array {
    [$ver, $cust] = pos_sessao($t);
    $metodo = (string)arg('metodo', '');
    [$linhas, $sub, $totalMl] = pedido_calcular($t, $cust, arg('itens', []));
    [$desc, $cup] = cupom_aplicar((string)arg('cupom', ''), $sub);
    $total = round($sub - $desc, 2);
    if ($total > 0 && !in_array($metodo, r_pos_config($t)['metodos'], true)) fail('metodo', 'Forma de pagamento indisponível');
    if ($total <= 0) $metodo = null;   // cupom de 100%: não passa pela maquininha

    $ref = 'BE' . date('ymdHis') . strtoupper(substr(rand_token(3), 0, 4));
    $provider = $total <= 0 ? 'cupom' : ($metodo === 'pix' && !pix_nativo($t) ? 'mercadopago_pix' : $t['provider']);
    db()->beginTransaction();
    q('INSERT INTO be_orders (ref, terminal_id, location_id, customer_id, total, subtotal, desconto, cupom, total_ml, metodo, provider) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$ref, $t['id'], $t['location_id'], $cust['id'], $total, $sub, $desc, $cup['codigo'] ?? null, $totalMl, $metodo, $provider]);
    $oid = (int)db()->lastInsertId();
    foreach ($linhas as [$of, $qtd])
        q('INSERT INTO be_order_items (order_id, tap_id, offer_id, produto, volume_ml, qtd, preco_unit) VALUES (?,?,?,?,?,?,?)',
            [$oid, $of['tap_id'], $of['id'], $of['produto'], $of['volume_ml'], $qtd, $of['preco']]);
    db()->commit();
    $order = row('SELECT * FROM be_orders WHERE id = ?', [$oid]);

    $resp = ['ok' => true, 'ref' => $ref, 'total' => (float)$order['total'], 'subtotal' => $sub, 'desconto' => $desc, 'total_centavos' => (int)round($order['total'] * 100),
        'descricao' => setting('marca_nome', 'Beer Express') . ' ' . $ref, 'metodo' => $metodo, 'pix_nativo' => $metodo === 'pix' && pix_nativo($t)];
    if ($total <= 0) {
        pedido_marcar_pago($order, ['metodo' => null, 'verificado' => 1]);
        $resp['pago'] = true;
    } elseif ($metodo === 'pix' && !pix_nativo($t)) {
        if ($t['provider'] === 'simulado' && !mp_token()) {
            $resp['pix'] = ['simulado' => true, 'copia_cola' => '00020126SIMULADO' . $ref, 'qr_base64' => ''];
        } else {
            $pix = mp_criar_pix($order, $cust);
            q('UPDATE be_orders SET pagamento_id = ?, pix_qr_base64 = ?, pix_copia_cola = ? WHERE id = ?', [$pix['id'], $pix['qr_base64'], $pix['copia_cola'], $oid]);
            $resp['pix'] = ['copia_cola' => $pix['copia_cola'], 'qr_base64' => $pix['qr_base64'], 'expira_min' => (int)setting('pix_expira_min', 5)];
        }
    }
    return $resp;
}

function pos_order_by_ref(array $t, string $ref): array {
    $o = row('SELECT * FROM be_orders WHERE ref = ? AND terminal_id = ?', [$ref, $t['id']]);
    if (!$o) fail('pedido', 'Pedido não encontrado', 404);
    return $o;
}

/** Cartão aprovado na maquininha → confirma pedido. */
function r_pos_paid(array $t): array {
    $o = pos_order_by_ref($t, (string)arg('ref'));
    if ($o['status'] !== 'aguardando_pagamento') return ['ok' => true, 'status' => $o['status']];
    $metodo = (string)arg('metodo', $o['metodo']);
    $verificado = 0; $pid = (string)arg('pagamento_id', '');
    if ($metodo === 'pix' && $t['provider'] === 'simulado' && !mp_token()) $verificado = 0;
    elseif ($metodo === 'pix' && !pix_nativo($t)) fail('pix', 'Pix é confirmado pelo servidor, aguarde.', 409);
    elseif ($t['provider'] === 'mercadopago' && setting('mp_verificar_cartao', '1') === '1' && mp_token()) {
        for ($i = 0; $i < 3 && !$verificado; $i++) {
            $p = mp_pagamento_aprovado_ref($o['ref'], (float)$o['total']);
            if ($p) { $verificado = 1; $pid = (string)$p['id']; } else usleep(1200000);
        }
        if (!$verificado) log_event('aviso', 'pagamento_nao_verificado', "Pedido {$o['ref']}: maquininha informou aprovação mas o Mercado Pago ainda não mostra o pagamento. Liberado mesmo assim — confira.", ['order_id' => $o['id'], 'terminal_id' => $t['id']]);
    }
    pedido_marcar_pago($o, ['metodo' => $metodo, 'pagamento_id' => $pid ?: null, 'nsu' => arg('nsu'), 'bandeira' => arg('bandeira'),
        'final_cartao' => substr(preg_replace('/\D/', '', (string)arg('final_cartao', '')), -4) ?: null, 'verificado' => $verificado]);
    return ['ok' => true, 'status' => 'pago'];
}

function r_pos_payment_failed(array $t): array {
    $o = pos_order_by_ref($t, (string)arg('ref'));
    if ($o['status'] === 'aguardando_pagamento') {
        q('UPDATE be_orders SET status = "cancelado", obs = ? WHERE id = ?', [mb_substr('Pagamento recusado/cancelado: ' . arg('motivo', ''), 0, 255), $o['id']]);
        log_event('info', 'pagamento_recusado', "Pedido {$o['ref']} não pago: " . arg('motivo', '-'), ['order_id' => $o['id'], 'terminal_id' => $t['id']]);
    }
    return ['ok' => true];
}

function r_pos_cancel(array $t): array { return r_pos_payment_failed($t); }

function r_pos_order_status(array $t): array {
    $o = pos_order_by_ref($t, (string)arg('ref'));
    // Pix: se o webhook atrasar, consulta direto
    if ($o['status'] === 'aguardando_pagamento' && $o['metodo'] === 'pix' && $o['pagamento_id'] && mp_token()) {
        $r = mp_api('GET', '/v1/payments/' . urlencode($o['pagamento_id']));
        if (($r['body']['status'] ?? '') === 'approved') {
            pedido_marcar_pago($o, ['metodo' => 'pix', 'pagamento_id' => $o['pagamento_id'], 'verificado' => 1]);
            $o = row('SELECT * FROM be_orders WHERE id = ?', [$o['id']]);
        } elseif (in_array($r['body']['status'] ?? '', ['cancelled', 'rejected', 'expired'], true)) {
            q('UPDATE be_orders SET status = "cancelado", obs = "Pix expirado" WHERE id = ?', [$o['id']]);
            $o['status'] = 'cancelado';
        }
    }
    $ds = rows('SELECT d.id, d.seq, d.status, d.volume_ml, d.servido_ml, d.erro, t.nome torneira, t.canal, i.produto, dv.start_mode
        FROM be_dispenses d JOIN be_taps t ON t.id = d.tap_id JOIN be_order_items i ON i.id = d.item_id JOIN be_devices dv ON dv.id = d.device_id
        WHERE d.order_id = ? ORDER BY d.seq', [$o['id']]);
    return ['ok' => true, 'ref' => $o['ref'], 'tipo' => $o['tipo'], 'status' => $o['status'], 'total' => (float)$o['total'], 'bonus' => (float)$o['bonus'],
        'saldo' => $o['tipo'] === 'recarga' ? (float)val('SELECT saldo FROM be_customers WHERE id = ?', [$o['customer_id']]) : null, 'copos' => array_map(fn($d) => [
        'id' => (int)$d['id'], 'seq' => (int)$d['seq'], 'status' => $d['status'], 'volume_ml' => (int)$d['volume_ml'], 'servido_ml' => (int)$d['servido_ml'],
        'torneira' => $d['torneira'], 'canal' => (int)$d['canal'], 'produto' => $d['produto'], 'start_mode' => $d['start_mode'], 'erro' => $d['erro'],
    ], $ds)];
}

/** Cliente tocou "SERVIR" na tela (modo start_mode = pos). */
function r_pos_serve(array $t): array {
    $d = row('SELECT d.* FROM be_dispenses d JOIN be_orders o ON o.id = d.order_id WHERE d.id = ? AND o.terminal_id = ?', [(int)arg('dispense_id'), $t['id']]);
    if (!$d) fail('copo', 'Copo não encontrado', 404);
    if (in_array($d['status'], ['armado', 'pendente'], true)) q('UPDATE be_dispenses SET status = "liberado" WHERE id = ?', [$d['id']]);
    return ['ok' => true];
}

/* ---------- Modo Pass na maquininha (totem de recarga) ---------- */

function r_pos_wallet(array $t): array {
    [, $c] = pos_sessao($t);
    $cards = rows('SELECT id, CONCAT("•••", RIGHT(uid, 4)) final, apelido, ativo, ultimo_uso FROM be_cards WHERE customer_id = ? ORDER BY id', [$c['id']]);
    $ext = rows('SELECT tipo, valor, saldo_apos, obs, created_at FROM be_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 8', [$c['id']]);
    $taps = rows('SELECT t.nome, t.canal, t.preco_litro, p.nome produto FROM be_taps t JOIN be_devices d ON d.id = t.device_id LEFT JOIN be_products p ON p.id = t.product_id
        WHERE d.location_id = ? AND t.ativo = 1 AND t.preco_litro IS NOT NULL ORDER BY t.canal', [$t['location_id']]);
    $bonus = [];
    foreach (recarga_valores() as $v) $bonus[] = ['valor' => $v, 'bonus' => recarga_bonus($v)];
    return ['ok' => true, 'pass' => setting('pass_ativo', '0') === '1' && count($taps) > 0, 'saldo' => (float)$c['saldo'], 'nome' => nome_exibicao($c['nome']),
        'cartoes' => $cards, 'extrato' => $ext, 'recargas' => $bonus, 'torneiras_pass' => $taps];
}

function r_pos_recharge(array $t): array {
    [, $cust] = pos_sessao($t);
    if (setting('pass_ativo', '0') !== '1') fail('pass_off', 'Recarga desativada neste ponto');
    $valor = round((float)arg('valor'), 2);
    if (!in_array($valor, recarga_valores(), false)) fail('valor', 'Valor de recarga inválido');
    $metodo = (string)arg('metodo', '');
    if (!in_array($metodo, r_pos_config($t)['metodos'], true)) fail('metodo', 'Forma de pagamento indisponível');
    $ref = 'RC' . date('ymdHis') . strtoupper(substr(rand_token(3), 0, 4));
    $bonus = recarga_bonus($valor);
    q('INSERT INTO be_orders (ref, tipo, terminal_id, location_id, customer_id, total, bonus, total_ml, metodo, provider) VALUES (?,"recarga",?,?,?,?,?,0,?,?)',
        [$ref, $t['id'], $t['location_id'], $cust['id'], $valor, $bonus, $metodo, $metodo === 'pix' ? 'mercadopago_pix' : $t['provider']]);
    $order = row('SELECT * FROM be_orders WHERE ref = ?', [$ref]);
    $resp = ['ok' => true, 'ref' => $ref, 'total' => $valor, 'bonus' => $bonus, 'total_centavos' => (int)round($valor * 100),
        'descricao' => setting('marca_nome', 'Beer Express') . ' recarga ' . $ref, 'metodo' => $metodo];
    if ($metodo === 'pix') {
        if ($t['provider'] === 'simulado' && !mp_token()) $resp['pix'] = ['simulado' => true, 'copia_cola' => '00020126SIMULADO' . $ref, 'qr_base64' => ''];
        else {
            $pix = mp_criar_pix($order, $cust);
            q('UPDATE be_orders SET pagamento_id = ?, pix_qr_base64 = ?, pix_copia_cola = ? WHERE id = ?', [$pix['id'], $pix['qr_base64'], $pix['copia_cola'], $order['id']]);
            $resp['pix'] = ['copia_cola' => $pix['copia_cola'], 'qr_base64' => $pix['qr_base64'], 'expira_min' => (int)setting('pix_expira_min', 5)];
        }
    }
    return $resp;
}

/** Cliente aproximou um cartão novo na torneira → a tela mostrou um código → digita aqui. */
function r_pos_link_card(array $t): array {
    [, $cust] = pos_sessao($t);
    $cod = preg_replace('/\D/', '', (string)arg('codigo'));
    $card = strlen($cod) === 4 ? row('SELECT * FROM be_cards WHERE codigo_vinculo = ? AND codigo_expira > NOW() AND customer_id IS NULL', [$cod]) : null;
    if (!$card) fail('codigo', 'Código inválido ou expirado. Aproxime o cartão na torneira de novo.');
    $n = (int)val('SELECT COUNT(*) FROM be_cards WHERE customer_id = ? AND ativo = 1', [$cust['id']]);
    q('UPDATE be_cards SET customer_id = ?, codigo_vinculo = NULL, codigo_expira = NULL, apelido = ? WHERE id = ?', [$cust['id'], 'Cartão ' . ($n + 1), $card['id']]);
    log_event('info', 'cartao_vinculado', "Cartão •••" . substr($card['uid'], -4) . " vinculado a " . nome_exibicao($cust['nome']), ['terminal_id' => $t['id'], 'location_id' => $t['location_id']]);
    return ['ok' => true, 'final' => '•••' . substr($card['uid'], -4)];
}

function r_pos_card_block(array $t): array {
    [, $cust] = pos_sessao($t);
    q('UPDATE be_cards SET ativo = 0 WHERE id = ? AND customer_id = ?', [(int)arg('card_id'), $cust['id']]);
    log_event('aviso', 'cartao_bloqueado', 'Cliente bloqueou um cartão pela maquininha (perda/roubo)', ['terminal_id' => $t['id']]);
    return ['ok' => true];
}

function r_pos_logout(array $t): array {
    q('UPDATE be_verifications SET usado = 1 WHERE token = ? AND terminal_id = ?', [(string)arg('token'), $t['id']]);
    return ['ok' => true];
}

/* ============================== ROTAS ESP32 ============================== */

function auth_device(): array {
    $key = header_val('X-Device-Key') ?: (string)($_GET['key'] ?? '');
    $d = $key ? row('SELECT d.*, l.nome location_nome FROM be_devices d JOIN be_locations l ON l.id = d.location_id WHERE d.device_key = ? AND d.ativo = 1', [$key]) : null;
    if (!$d) fail('device', 'Dispositivo não autorizado', 401);
    return $d;
}

function r_dev_poll(array $d): array {
    $temp = isset($_GET['temp']) && $_GET['temp'] !== '' && (float)$_GET['temp'] > -100 ? round((float)$_GET['temp'], 2) : null;
    $door = isset($_GET['door']) && $_GET['door'] !== '' ? (int)$_GET['door'] : null;
    $ctx = ['device_id' => $d['id'], 'location_id' => $d['location_id']];
    if (!$d['online']) log_event('info', 'device_online', "✅ Chopeira {$d['nome']} ({$d['location_nome']}) online", $ctx, (bool)$d['last_seen']);
    q('UPDATE be_devices SET last_seen = NOW(), online = 1, ip = ?, fw_version = COALESCE(NULLIF(?, ""), fw_version), rssi = COALESCE(?, rssi), temperatura = COALESCE(?, temperatura), porta_aberta = COALESCE(?, porta_aberta) WHERE id = ?',
        [client_ip(), substr((string)($_GET['fw'] ?? ''), 0, 20), isset($_GET['rssi']) ? (int)$_GET['rssi'] : null, $temp, $door, $d['id']]);

    // Telemetria a cada 5 min
    $ult = val('SELECT MAX(created_at) FROM be_telemetry WHERE device_id = ?', [$d['id']]);
    if (!$ult || strtotime($ult) < time() - 290)
        q('INSERT INTO be_telemetry (device_id, temperatura, rssi, heap, uptime_s) VALUES (?,?,?,?,?)',
            [$d['id'], $temp, $_GET['rssi'] ?? null, $_GET['heap'] ?? null, $_GET['up'] ?? null]);

    // Temperatura fora da faixa (alerta após 10 min contínuos — feito no cron)
    if ($temp !== null) {
        $fora = $temp > (float)$d['temp_max'] || $temp < (float)$d['temp_min'];
        if ($fora && !$d['temp_alert_since']) q('UPDATE be_devices SET temp_alert_since = NOW() WHERE id = ?', [$d['id']]);
        if (!$fora && $d['temp_alert_since']) q('UPDATE be_devices SET temp_alert_since = NULL WHERE id = ?', [$d['id']]);
    }

    $taps = array_map(fn($t) => ['canal' => (int)$t['canal'], 'modo' => $t['modo'], 'ppl' => (float)$t['pulsos_por_litro'], 'ml_pulso' => (float)$t['ml_por_pulso'], 'ativo' => (int)$t['ativo'],
            'produto' => (string)$t['produto'], 'estilo' => (string)$t['estilo'], 'abv' => $t['abv'] !== null ? (float)$t['abv'] : null,
            'preco_litro' => $t['preco_litro'] !== null ? (float)$t['preco_litro'] : null, 'restante_ml' => (int)$t['restante_ml'], 'cor' => (string)($t['cor'] ?: '#F5B301')],
        rows('SELECT t.canal, t.modo, t.pulsos_por_litro, t.ml_por_pulso, t.ativo, t.preco_litro, t.restante_ml, p.nome produto, p.estilo, p.abv, p.cor
              FROM be_taps t LEFT JOIN be_products p ON p.id = t.product_id WHERE t.device_id = ? ORDER BY t.canal', [$d['id']]));

    // Copo em andamento? senão pega o próximo da fila (pedidos pagos)
    $disp = row("SELECT * FROM be_dispenses WHERE device_id = ? AND status IN ('armado','liberado','servindo') ORDER BY id LIMIT 1", [$d['id']]);
    if (!$disp) {
        $disp = row("SELECT ds.*, o.provider FROM be_dispenses ds JOIN be_orders o ON o.id = ds.order_id
            WHERE ds.device_id = ? AND ds.status = 'pendente' AND o.status IN ('pago','servindo') ORDER BY ds.id LIMIT 1", [$d['id']]);
        if ($disp) {
            // Liberação manual do painel não tem tela de cliente: no modo "pos" já sai liberada
            $auto = $d['start_mode'] === 'auto' || ($d['start_mode'] === 'pos' && $disp['provider'] === 'manual');
            q('UPDATE be_dispenses SET status = ?, armado_em = NOW() WHERE id = ?', [$auto ? 'liberado' : 'armado', $disp['id']]);
            $disp = row('SELECT * FROM be_dispenses WHERE id = ?', [$disp['id']]);
            pedido_recalcular((int)$disp['order_id']);
        }
    }
    $resp = ['ok' => true, 'poll_ms' => 2000, 'taps' => $taps, 'server_time' => time(), 'cmd' => null,
        'marca' => setting('marca_nome', 'Beer Express'), 'pass' => setting('pass_ativo', '0') === '1'];
    if ($disp) {
        $tap = row('SELECT * FROM be_taps WHERE id = ?', [$disp['tap_id']]);
        $resp['poll_ms'] = 700;
        $resp['cmd'] = [
            'tipo' => 'servir', 'id' => (int)$disp['id'], 'canal' => (int)$tap['canal'], 'modo' => $tap['modo'], 'volume_ml' => (int)$disp['volume_ml'],
            'pulsos_alvo' => $tap['modo'] === 'flow' ? (int)round($disp['volume_ml'] * $tap['pulsos_por_litro'] / 1000) : (int)max(1, round($disp['volume_ml'] / max(1, $tap['ml_por_pulso']))),
            'start_mode' => $d['start_mode'], 'go' => in_array($disp['status'], ['liberado', 'servindo'], true), 'timeout_s' => (int)$d['arm_timeout_s'],
            // Alavanca (auto, igual BeerPass): válvula libera e espera o cliente puxar; pausas para a espuma são permitidas
            'espera_inicio_s' => $d['start_mode'] === 'auto' ? (int)$d['arm_timeout_s'] : 6, 'pausa_max_s' => $d['start_mode'] === 'auto' ? 25 : 5,
        ];
    }
    return $resp;
}

function r_dev_report(array $d): array {
    $id = (int)arg('id');
    $disp = row('SELECT * FROM be_dispenses WHERE id = ? AND device_id = ?', [$id, $d['id']]);
    if (!$disp) fail('copo', 'Copo não encontrado', 404);
    if (in_array($disp['status'], ['concluido', 'erro', 'timeout', 'cancelado'], true)) return ['ok' => true, 'ignorado' => true];
    $st = (string)arg('status');
    $pulsos = (int)arg('pulsos', 0);
    $ml = (int)arg('servido_ml', 0);
    $ctx = ['device_id' => $d['id'], 'location_id' => $d['location_id'], 'order_id' => $disp['order_id']];
    if ($st === 'servindo') {
        q('UPDATE be_dispenses SET status = "servindo", inicio_em = COALESCE(inicio_em, NOW()), pulsos = ?, servido_ml = ? WHERE id = ?', [$pulsos, $ml, $id]);
    } elseif (in_array($st, ['concluido', 'erro', 'timeout'], true)) {
        q('UPDATE be_dispenses SET status = ?, pulsos = ?, servido_ml = ?, erro = ?, fim_em = NOW() WHERE id = ?', [$st, $pulsos, $ml, $st === 'concluido' ? null : mb_substr((string)arg('erro', $st), 0, 120), $id]);
        tap_baixar_estoque((int)$disp['tap_id'], $ml);
        if ($st !== 'concluido') {
            $tap = row('SELECT t.nome, p.nome produto FROM be_taps t LEFT JOIN be_products p ON p.id = t.product_id WHERE t.id = ?', [$disp['tap_id']]);
            $msg = $st === 'timeout' ? "⏱️ Cliente não retirou o copo {$disp['seq']} ({$tap['produto']} {$disp['volume_ml']} ml) em {$d['location_nome']} — tempo esgotado."
                : "❌ Erro ao servir em {$d['location_nome']} / {$tap['nome']}: " . arg('erro', '?') . " (servido " . ml_fmt($ml) . " de {$disp['volume_ml']} ml)";
            log_event($st === 'timeout' ? 'aviso' : 'critico', 'servir_' . $st, $msg, $ctx, true);
        }
        pedido_recalcular((int)$disp['order_id']);
    } else fail('status', 'Status inválido');
    return ['ok' => true];
}

/* ---------- Modo Pass na torneira (ESP32 com tela + leitor RFID) ---------- */

/** Cliente aproximou o cartão/pulseira na torneira. */
function r_dev_card(array $d): array {
    $uid = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)arg('uid')));
    if (strlen($uid) < 8 || strlen($uid) > 32) fail('uid', 'Cartão ilegível');
    $tap = row('SELECT t.*, p.nome produto FROM be_taps t LEFT JOIN be_products p ON p.id = t.product_id WHERE t.device_id = ? AND t.canal = ? AND t.ativo = 1', [$d['id'], (int)arg('canal', 1)]);
    if (!$tap || $tap['preco_litro'] === null || setting('pass_ativo', '0') !== '1') return ['ok' => false, 'motivo' => 'pass_off', 'msg' => 'Torneira sem venda por ml'];
    $res = fn(string $motivo, string $msg, array $x = []) => ['ok' => false, 'motivo' => $motivo, 'msg' => $msg] + $x;

    $card = row('SELECT * FROM be_cards WHERE uid = ?', [$uid]);
    if (!$card) { q('INSERT INTO be_cards (uid) VALUES (?)', [$uid]); $card = row('SELECT * FROM be_cards WHERE uid = ?', [$uid]); }
    if (!$card['ativo']) return $res('cartao_bloqueado', 'Cartão bloqueado');
    if (!$card['customer_id']) {
        $cod = ($card['codigo_vinculo'] && strtotime((string)$card['codigo_expira']) > time()) ? $card['codigo_vinculo'] : null;
        for ($i = 0; !$cod && $i < 20; $i++) {
            $try = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (!val('SELECT id FROM be_cards WHERE codigo_vinculo = ? AND codigo_expira > NOW()', [$try])) $cod = $try;
        }
        q('UPDATE be_cards SET codigo_vinculo = ?, codigo_expira = NOW() + INTERVAL 10 MINUTE WHERE id = ?', [$cod, $card['id']]);
        return $res('cartao_novo', 'Cartão novo: na maquininha, toque em "Vincular cartão" e digite o código', ['codigo' => $cod]);
    }
    $c = row('SELECT * FROM be_customers WHERE id = ?', [$card['customer_id']]);
    if (!$c || $c['bloqueado']) return $res('bloqueado', 'Cadastro bloqueado');
    if (idade($c['nascimento']) < (int)setting('idade_minima', 18)) return $res('menor_idade', 'Venda proibida para menores');

    // Já existe servida aberta nesta torneira?
    $aberta = row("SELECT * FROM be_pours WHERE device_id = ? AND tap_id = ? AND status = 'aberta' ORDER BY id DESC LIMIT 1", [$d['id'], $tap['id']]);
    if ($aberta && (int)$aberta['customer_id'] !== (int)$c['id']) return $res('ocupada', 'Torneira em uso, aguarde');
    if ($aberta) $pour = $aberta;
    else {
        $precoMl = (float)$tap['preco_litro'] / 1000;
        $disp = (float)$c['saldo'] - saldo_reservado((int)$c['id']);
        $limiteDia = (int)setting('limite_ml_dia_cpf', 3000);
        $restDia = $limiteDia > 0 ? $limiteDia - ml_hoje_cliente((int)$c['id']) : PHP_INT_MAX;
        $limite = (int)min((int)$tap['max_ml_servida'], (int)$tap['restante_ml'], $restDia, floor($disp / max(0.0001, $precoMl)));
        $min = (int)setting('pass_ml_minimo', 100);
        if ($restDia < $min) return $res('limite_diario', 'Limite diário atingido', ['saldo' => (float)$c['saldo']]);
        if ((int)$tap['restante_ml'] < $min) return $res('barril_vazio', 'Barril vazio, use outra torneira');
        if ($limite < $min) return $res('saldo_insuficiente', 'Saldo insuficiente — recarregue na maquininha', ['saldo' => (float)$c['saldo'], 'nome' => nome_exibicao($c['nome'])]);
        q('INSERT INTO be_pours (customer_id, card_id, tap_id, device_id, location_id, produto, preco_litro, limite_ml) VALUES (?,?,?,?,?,?,?,?)',
            [$c['id'], $card['id'], $tap['id'], $d['id'], $d['location_id'], $tap['produto'], $tap['preco_litro'], $limite]);
        $pour = row('SELECT * FROM be_pours WHERE id = ?', [(int)db()->lastInsertId()]);
    }
    q('UPDATE be_cards SET ultimo_uso = NOW() WHERE id = ?', [$card['id']]);
    return ['ok' => true, 'pour_id' => (int)$pour['id'], 'nome' => nome_exibicao($c['nome']), 'saldo' => (float)$c['saldo'], 'preco_litro' => (float)$pour['preco_litro'],
        'limite_ml' => (int)$pour['limite_ml'], 'pulsos_limite' => (int)round($pour['limite_ml'] * (float)$tap['pulsos_por_litro'] / 1000),
        'ppl' => (float)$tap['pulsos_por_litro'], 'inatividade_s' => (int)setting('pass_fim_inatividade_s', 8)];
}

/** Progresso (fim = 0) ou fechamento (fim = 1) de uma servida por ml. */
function r_dev_pour(array $d): array {
    $p = row('SELECT * FROM be_pours WHERE id = ? AND device_id = ?', [(int)arg('pour_id'), $d['id']]);
    if (!$p) fail('pour', 'Servida não encontrada', 404);
    $ml = (int)arg('ml', 0); $pulsos = (int)arg('pulsos', 0);
    if ($p['status'] !== 'aberta') return ['ok' => true, 'fechada' => true, 'valor' => (float)$p['valor'], 'servido_ml' => (int)$p['servido_ml'],
        'saldo' => (float)val('SELECT saldo FROM be_customers WHERE id = ?', [$p['customer_id']])];
    if (!filter_var(arg('fim', false), FILTER_VALIDATE_BOOLEAN)) {
        q('UPDATE be_pours SET servido_ml = ?, pulsos = ?, atualizado_em = NOW() WHERE id = ?', [max(0, $ml), max(0, $pulsos), $p['id']]);
        return ['ok' => true];
    }
    $erro = (string)arg('erro', '');
    $f = pour_fechar((int)$p['id'], $ml, $pulsos, (string)arg('motivo', $erro ?: 'fim'), $erro ? 'erro' : 'fechada');
    if ($erro) log_event('aviso', 'pour_erro', "Servida por ml em {$d['location_nome']} terminou com erro: $erro (" . ml_fmt($ml) . ")", ['device_id' => $d['id'], 'location_id' => $d['location_id']], true);
    return ['ok' => true, 'fechada' => true, 'valor' => (float)$f['valor'], 'servido_ml' => (int)$f['servido_ml'], 'saldo' => (float)$f['saldo']];
}

/** Fluxo detectado sem pedido (vazamento ou torneira forçada). */
function r_dev_stray(array $d): array {
    $canal = (int)arg('canal'); $ml = (int)arg('ml', 0);
    $tap = row('SELECT * FROM be_taps WHERE device_id = ? AND canal = ?', [$d['id'], $canal]);
    if ($tap) tap_baixar_estoque((int)$tap['id'], $ml);
    log_event('critico', 'fluxo_sem_pedido', "🚨 Fluxo de " . ml_fmt($ml) . " na torneira $canal de {$d['location_nome']} SEM pedido (vazamento, válvula travada ou violação).",
        ['device_id' => $d['id'], 'location_id' => $d['location_id']], true);
    return ['ok' => true];
}

/* ============================== WEBHOOKS ============================== */

function r_mp_webhook(): array {
    $id = $_GET['data_id'] ?? $_GET['id'] ?? (input()['data']['id'] ?? null);
    $type = $_GET['type'] ?? $_GET['topic'] ?? (input()['type'] ?? '');
    if (!$id || ($type && !in_array($type, ['payment', 'payments'], true))) return ['ok' => true];
    $r = mp_api('GET', '/v1/payments/' . urlencode((string)$id));
    $p = $r['body'] ?? [];
    $ref = $p['external_reference'] ?? '';
    $o = $ref ? row('SELECT * FROM be_orders WHERE ref = ?', [$ref]) : null;
    if ($o && ($p['status'] ?? '') === 'approved' && $o['status'] === 'aguardando_pagamento' && abs((float)$p['transaction_amount'] - (float)$o['total']) < 0.01) {
        $met = ($p['payment_method_id'] ?? '') === 'pix' ? 'pix' : (($p['payment_type_id'] ?? '') === 'debit_card' ? 'debito' : 'credito');
        pedido_marcar_pago($o, ['metodo' => $met, 'pagamento_id' => (string)$p['id'], 'verificado' => 1,
            'bandeira' => $p['payment_method_id'] ?? null, 'final_cartao' => $p['card']['last_four_digits'] ?? null]);
    }
    return ['ok' => true];
}

function r_tg_webhook(): array {
    if (($_GET['key'] ?? '') !== BE_CRON_KEY) fail('key', 'chave inválida', 403);
    $u = input();
    $chat = (string)($u['message']['chat']['id'] ?? '');
    $txt = trim((string)($u['message']['text'] ?? ''));
    if (!$chat || !in_array($chat, tg_chat_ids(), true)) return ['ok' => true];
    $cmd = strtolower(explode('@', explode(' ', $txt)[0])[0]);
    if ($cmd === '/hoje') tg_send(resumo_dia(date('Y-m-d')), $chat);
    elseif ($cmd === '/status') tg_send(status_texto(), $chat);
    elseif ($cmd === '/barris') tg_send(barris_texto(), $chat);
    else tg_send("🍺 <b>" . h(setting('marca_nome', 'Beer Express')) . "</b>\n/status — chopeiras e maquininhas\n/hoje — vendas de hoje\n/barris — nível dos barris", $chat);
    return ['ok' => true];
}

function resumo_dia(string $dia): string {
    $tot = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) s, COALESCE(SUM(total_ml),0) ml FROM be_orders WHERE status IN ('pago','servindo','concluido','parcial') AND DATE(created_at) = ?", [$dia]);
    $porLoc = rows("SELECT l.nome, COUNT(*) n, SUM(o.total) s, SUM(o.total_ml) ml FROM be_orders o JOIN be_locations l ON l.id = o.location_id
        WHERE o.status IN ('pago','servindo','concluido','parcial') AND DATE(o.created_at) = ? GROUP BY l.id ORDER BY s DESC", [$dia]);
    $porMet = rows("SELECT metodo, COUNT(*) n, SUM(total) s FROM be_orders WHERE status IN ('pago','servindo','concluido','parcial') AND DATE(created_at) = ? GROUP BY metodo", [$dia]);
    $prob = (int)val("SELECT COUNT(*) FROM be_orders WHERE status IN ('parcial','falhou') AND DATE(created_at) = ?", [$dia]);
    $menor = (int)val("SELECT COUNT(*) FROM be_verifications WHERE motivo = 'menor_idade' AND DATE(created_at) = ?", [$dia]);
    $novos = (int)val("SELECT COUNT(*) FROM be_customers WHERE DATE(created_at) = ?", [$dia]);
    $t = "📊 <b>Resumo " . date('d/m/Y', strtotime($dia)) . "</b>\n💰 " . money($tot['s']) . " em {$tot['n']} vendas\n🍺 " . ml_fmt((int)$tot['ml']) . " servidos"
        . ($tot['n'] ? "\n🎯 Ticket médio " . money($tot['s'] / $tot['n']) : '');
    foreach ($porMet as $m) $t .= "\n   " . (['credito' => 'Crédito', 'debito' => 'Débito', 'pix' => 'Pix'][$m['metodo']] ?? 'Outro') . ": {$m['n']} • " . money($m['s']);
    foreach ($porLoc as $l) $t .= "\n📍 " . h($l['nome']) . ": {$l['n']} • " . money($l['s']) . " • " . ml_fmt((int)$l['ml']);
    $pp = row("SELECT COUNT(*) n, COALESCE(SUM(valor),0) s, COALESCE(SUM(servido_ml),0) ml FROM be_pours WHERE status <> 'aberta' AND DATE(inicio_em) = ?", [$dia]);
    if ($pp['n']) $t .= "\n🎫 Pass (por ml): {$pp['n']} servidas • " . ml_fmt((int)$pp['ml']) . " • consumo " . money($pp['s']);
    $t .= "\n👤 Novos clientes: $novos";
    if ($menor) $t .= "\n🔞 Tentativas de menores bloqueadas: $menor";
    if ($prob) $t .= "\n⚠️ Pedidos com problema: $prob";
    return $t;
}
function status_texto(): string {
    $t = "🛰️ <b>Status</b>";
    foreach (rows('SELECT d.*, l.nome loc FROM be_devices d JOIN be_locations l ON l.id = d.location_id WHERE d.ativo = 1') as $d)
        $t .= "\n" . (device_online($d) ? '🟢' : '🔴') . " " . h($d['loc']) . " / " . h($d['nome']) . ($d['temperatura'] !== null ? " • 🌡️ {$d['temperatura']}°C" : '') . ($d['rssi'] ? " • 📶 {$d['rssi']}dBm" : '');
    foreach (rows('SELECT t.*, l.nome loc FROM be_terminals t JOIN be_locations l ON l.id = t.location_id WHERE t.ativo = 1') as $m)
        $t .= "\n" . (device_online($m) ? '🟢' : '🔴') . " 📱 " . h($m['loc']) . " / " . h($m['nome']);
    return $t;
}
function barris_texto(): string {
    $t = "🛢️ <b>Barris</b>";
    foreach (rows('SELECT t.*, p.nome produto, l.nome loc FROM be_taps t JOIN be_devices d ON d.id = t.device_id JOIN be_locations l ON l.id = d.location_id LEFT JOIN be_products p ON p.id = t.product_id WHERE t.ativo = 1 ORDER BY l.id, t.canal') as $b) {
        $pct = $b['barril_ml'] ? round(100 * $b['restante_ml'] / $b['barril_ml']) : 0;
        $t .= "\n" . ($pct <= $b['alerta_pct'] ? '🔴' : ($pct <= 40 ? '🟡' : '🟢')) . " " . h($b['loc']) . " / " . h($b['nome']) . " — " . h((string)$b['produto']) . ": " . ml_fmt((int)$b['restante_ml']) . " ($pct%)";
    }
    return $t;
}

/* ============================== PAINEL ============================== */

function r_admin_needs_setup(): array { return ['ok' => true, 'needs_setup' => (int)val('SELECT COUNT(*) FROM be_users') === 0, 'versao' => BE_VERSION]; }
function r_admin_setup(): array {
    if ((int)val('SELECT COUNT(*) FROM be_users') > 0) fail('setup', 'Já configurado', 403);
    $email = strtolower(trim((string)arg('email'))); $senha = (string)arg('senha'); $nome = trim((string)arg('nome', 'Administrador'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 8) fail('dados', 'E-mail válido e senha com 8+ caracteres');
    q('INSERT INTO be_users (nome, email, senha_hash, role) VALUES (?,?,?,"admin")', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
    return r_admin_login();
}
function r_admin_login(): array {
    $u = row('SELECT * FROM be_users WHERE email = ? AND ativo = 1', [strtolower(trim((string)arg('email')))]);
    if (!$u || !password_verify((string)arg('senha'), $u['senha_hash'])) { usleep(600000); fail('login', 'E-mail ou senha incorretos', 401); }
    q('UPDATE be_users SET last_login = NOW() WHERE id = ?', [$u['id']]);
    return ['ok' => true, 'token' => jwt_make(['uid' => (int)$u['id']]), 'user' => ['nome' => $u['nome'], 'email' => $u['email'], 'role' => $u['role']]];
}
function r_admin_password(array $u): array {
    $full = row('SELECT * FROM be_users WHERE id = ?', [$u['id']]);
    if (!password_verify((string)arg('atual'), $full['senha_hash'])) fail('senha', 'Senha atual incorreta');
    if (strlen((string)arg('nova')) < 8) fail('senha', 'Nova senha precisa de 8+ caracteres');
    q('UPDATE be_users SET senha_hash = ? WHERE id = ?', [password_hash((string)arg('nova'), PASSWORD_DEFAULT), $u['id']]);
    return ['ok' => true];
}

function r_admin_dashboard(): array {
    $pagos = "status IN ('pago','servindo','concluido','parcial')";
    $k = fn($w) => row("SELECT COUNT(*) n, COALESCE(SUM(total),0) s, COALESCE(SUM(total_ml),0) ml FROM be_orders WHERE $pagos AND $w");
    $hoje = $k('created_at >= CURDATE()'); $ontem = $k('created_at >= CURDATE() - INTERVAL 1 DAY AND created_at < CURDATE()');
    $mes = $k("created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $dias = rows("SELECT DATE(created_at) d, COUNT(*) n, SUM(total) s, SUM(total_ml) ml FROM be_orders WHERE $pagos AND created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at) ORDER BY d");
    $horas = rows("SELECT HOUR(created_at) h, COUNT(*) n FROM be_orders WHERE $pagos AND created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY HOUR(created_at) ORDER BY h");
    $prods = rows("SELECT produto, SUM(copos) copos, SUM(ml) ml, SUM(s) s FROM (
            SELECT i.produto, SUM(i.qtd) copos, SUM(i.qtd * i.volume_ml) ml, SUM(i.qtd * i.preco_unit) s FROM be_order_items i JOIN be_orders o ON o.id = i.order_id
             WHERE o.$pagos AND o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY i.produto
            UNION ALL
            SELECT produto, COUNT(*) copos, SUM(servido_ml) ml, SUM(valor) s FROM be_pours WHERE status <> 'aberta' AND servido_ml > 0 AND inicio_em >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY produto
        ) x GROUP BY produto ORDER BY s DESC");
    $devices = rows('SELECT d.id, d.nome, d.last_seen, d.temperatura, d.rssi, d.fw_version, d.temp_min, d.temp_max, d.porta_aberta, l.nome loc FROM be_devices d JOIN be_locations l ON l.id = d.location_id WHERE d.ativo = 1');
    foreach ($devices as &$d) $d['online'] = device_online($d);
    $terms = rows('SELECT t.id, t.nome, t.last_seen, t.provider, t.bloqueado_ate, l.nome loc FROM be_terminals t JOIN be_locations l ON l.id = t.location_id WHERE t.ativo = 1');
    foreach ($terms as &$t) $t['online'] = device_online($t);
    $barris = rows('SELECT t.id, t.nome, t.restante_ml, t.barril_ml, t.alerta_pct, p.nome produto, l.nome loc FROM be_taps t JOIN be_devices d ON d.id = t.device_id
        JOIN be_locations l ON l.id = d.location_id LEFT JOIN be_products p ON p.id = t.product_id WHERE t.ativo = 1 ORDER BY l.id, t.canal');
    $alertas = rows("SELECT id, nivel, tipo, msg, created_at FROM be_events WHERE nivel <> 'info' ORDER BY id DESC LIMIT 8");
    $problemas = rows("SELECT id, ref, status, total, created_at FROM be_orders WHERE status IN ('parcial','falhou') AND created_at >= CURDATE() - INTERVAL 7 DAY ORDER BY id DESC LIMIT 10");
    $menores = (int)val("SELECT COUNT(*) FROM be_verifications WHERE motivo = 'menor_idade' AND created_at >= CURDATE()");
    $clientes = row("SELECT COUNT(*) total, SUM(created_at >= CURDATE()) hoje FROM be_customers");
    $pass = row("SELECT COUNT(*) n, COALESCE(SUM(valor),0) s, COALESCE(SUM(servido_ml),0) ml FROM be_pours WHERE status <> 'aberta' AND inicio_em >= CURDATE()");
    $pass['recargas'] = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) s, COALESCE(SUM(bonus),0) b FROM be_orders WHERE tipo = 'recarga' AND status = 'concluido' AND created_at >= CURDATE()");
    $pass['saldo_carteiras'] = (float)val('SELECT COALESCE(SUM(saldo),0) FROM be_customers');
    $pass['abertas'] = rows("SELECT p.id, p.produto, p.servido_ml, p.limite_ml, t.nome torneira, c.nome cliente FROM be_pours p JOIN be_taps t ON t.id = p.tap_id JOIN be_customers c ON c.id = p.customer_id WHERE p.status = 'aberta'");
    foreach ($pass['abertas'] as &$a) $a['cliente'] = nome_exibicao($a['cliente']);
    unset($a);
    return ['ok' => true, 'hoje' => $hoje, 'ontem' => $ontem, 'mes' => $mes, 'dias' => $dias, 'horas' => $horas, 'produtos' => $prods, 'devices' => $devices,
        'terminais' => $terms, 'barris' => $barris, 'alertas' => $alertas, 'problemas' => $problemas, 'menores_hoje' => $menores, 'clientes' => $clientes, 'pass' => $pass, 'pass_ativo' => setting('pass_ativo', '0') === '1'];
}

function r_admin_orders(): array {
    $w = ['1=1']; $p = [];
    if (arg('de')) { $w[] = 'o.created_at >= ?'; $p[] = arg('de') . ' 00:00:00'; }
    if (arg('ate')) { $w[] = 'o.created_at <= ?'; $p[] = arg('ate') . ' 23:59:59'; }
    if (arg('status')) { $w[] = 'o.status = ?'; $p[] = arg('status'); }
    if (arg('location_id')) { $w[] = 'o.location_id = ?'; $p[] = (int)arg('location_id'); }
    if (arg('q')) { $w[] = '(o.ref LIKE ? OR c.cpf LIKE ? OR c.nome LIKE ?)'; $s = '%' . arg('q') . '%'; array_push($p, $s, $s, $s); }
    $page = max(1, (int)arg('page', 1)); $lim = 50; $off = ($page - 1) * $lim;
    $where = implode(' AND ', $w);
    $total = row("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN o.status IN ('pago','servindo','concluido','parcial') THEN o.total END),0) s FROM be_orders o LEFT JOIN be_customers c ON c.id = o.customer_id WHERE $where", $p);
    $lst = rows("SELECT o.id, o.ref, o.tipo, o.bonus, o.status, o.total, o.total_ml, o.metodo, o.bandeira, o.final_cartao, o.verificado_servidor, o.created_at, l.nome loc, c.nome cliente, c.cpf
        FROM be_orders o JOIN be_locations l ON l.id = o.location_id LEFT JOIN be_customers c ON c.id = o.customer_id WHERE $where ORDER BY o.id DESC LIMIT $lim OFFSET $off", $p);
    foreach ($lst as &$o) $o['cpf'] = $o['cpf'] ? cpf_mask($o['cpf']) : null;
    return ['ok' => true, 'pedidos' => $lst, 'total' => (int)$total['n'], 'soma' => (float)$total['s'], 'pagina' => $page, 'paginas' => (int)ceil($total['n'] / $lim)];
}
function r_admin_order(): array {
    $o = row('SELECT o.*, l.nome loc, t.nome terminal, c.nome cliente, c.cpf, c.nascimento, c.compras FROM be_orders o JOIN be_locations l ON l.id = o.location_id
        JOIN be_terminals t ON t.id = o.terminal_id LEFT JOIN be_customers c ON c.id = o.customer_id WHERE o.id = ?', [(int)arg('id')]);
    if (!$o) fail('pedido', 'Não encontrado', 404);
    unset($o['pix_qr_base64']);
    $o['cpf'] = $o['cpf'] ? cpf_mask($o['cpf']) : null;
    return ['ok' => true, 'pedido' => $o, 'itens' => rows('SELECT * FROM be_order_items WHERE order_id = ?', [$o['id']]),
        'copos' => rows('SELECT d.*, t.nome torneira FROM be_dispenses d JOIN be_taps t ON t.id = d.tap_id WHERE d.order_id = ? ORDER BY d.seq', [$o['id']]),
        'eventos' => rows('SELECT nivel, tipo, msg, created_at FROM be_events WHERE order_id = ? ORDER BY id', [$o['id']])];
}
function r_admin_order_refund(array $u): array {
    $o = row('SELECT * FROM be_orders WHERE id = ?', [(int)arg('id')]);
    if (!$o || !in_array($o['status'], ['pago', 'servindo', 'concluido', 'parcial', 'falhou'], true)) fail('pedido', 'Pedido não pode ser estornado');
    $valor = arg('valor') !== null && arg('valor') !== '' ? round((float)arg('valor'), 2) : (float)$o['total'];
    $auto = false; $det = 'Estorno manual (faça na maquininha/app da adquirente)';
    if ($o['pagamento_id'] && ctype_digit((string)$o['pagamento_id']) && mp_token()) {
        $body = $valor < (float)$o['total'] ? ['amount' => $valor] : new stdClass();
        $r = mp_api('POST', '/v1/payments/' . $o['pagamento_id'] . '/refunds', $body, ['X-Idempotency-Key: refund-' . $o['ref'] . '-' . $valor]);
        if (in_array($r['code'], [200, 201], true)) { $auto = true; $det = 'Estornado automaticamente via Mercado Pago'; }
        else $det = 'Mercado Pago recusou o estorno automático (HTTP ' . $r['code'] . '). Faça manualmente.';
    }
    if ($o['tipo'] === 'recarga') {   // tira da carteira o que ainda houver (sem deixar negativo)
        $saldo = (float)val('SELECT saldo FROM be_customers WHERE id = ?', [$o['customer_id']]);
        $tirar = min($saldo, $valor + ($valor >= (float)$o['total'] ? (float)$o['bonus'] : 0));
        if ($tirar > 0) carteira_mov((int)$o['customer_id'], 'estorno', -$tirar, ['order_id' => $o['id'], 'obs' => 'Estorno da recarga ' . $o['ref']]);
        $det .= ' • retirado ' . money($tirar) . ' da carteira';
    }
    q('UPDATE be_dispenses SET status = "cancelado" WHERE order_id = ? AND status IN ("pendente","armado","liberado")', [$o['id']]);
    q('UPDATE be_orders SET status = "estornado", obs = ? WHERE id = ?', [mb_substr($det . ' — ' . money($valor) . ' por ' . $u['nome'], 0, 255), $o['id']]);
    log_event('aviso', 'estorno', "↩️ Pedido {$o['ref']} estornado (" . money($valor) . ") por {$u['nome']}. $det", ['order_id' => $o['id'], 'location_id' => $o['location_id']], true);
    return ['ok' => true, 'automatico' => $auto, 'mensagem' => $det];
}
function r_admin_dispense_retry(array $u): array {
    $d = row('SELECT * FROM be_dispenses WHERE id = ?', [(int)arg('id')]);
    if (!$d || !in_array($d['status'], ['timeout', 'erro', 'cancelado'], true)) fail('copo', 'Só copos com erro/tempo esgotado podem ser reliberados');
    $falta = max(0, (int)$d['volume_ml'] - (int)$d['servido_ml']);
    if ($falta < 50) fail('copo', 'Esse copo já foi praticamente todo servido');
    q('INSERT INTO be_dispenses (order_id, item_id, tap_id, device_id, seq, volume_ml) VALUES (?,?,?,?,?,?)', [$d['order_id'], $d['item_id'], $d['tap_id'], $d['device_id'], $d['seq'], $falta]);
    q('UPDATE be_dispenses SET status = "cancelado", erro = CONCAT(COALESCE(erro,""), " • reliberado") WHERE id = ?', [$d['id']]);
    q('UPDATE be_orders SET status = "pago", concluido_em = NULL WHERE id = ? AND status IN ("parcial","falhou","concluido")', [$d['order_id']]);
    log_event('info', 'reliberado', "Copo reliberado ($falta ml) por {$u['nome']}", ['order_id' => $d['order_id'], 'device_id' => $d['device_id']]);
    return ['ok' => true, 'volume_ml' => $falta];
}
/** Liberação manual (teste, calibração, cortesia) — cria pedido R$ 0,00. */
function r_admin_manual_release(array $u): array {
    $tap = row('SELECT t.*, d.location_id, p.nome produto FROM be_taps t JOIN be_devices d ON d.id = t.device_id LEFT JOIN be_products p ON p.id = t.product_id WHERE t.id = ?', [(int)arg('tap_id')]);
    if (!$tap) fail('tap', 'Torneira não encontrada');
    $ml = max(50, min(2000, (int)arg('volume_ml', 300)));
    $term = (int)val('SELECT id FROM be_terminals WHERE location_id = ? ORDER BY id LIMIT 1', [$tap['location_id']]);
    if (!$term) fail('terminal', 'Cadastre ao menos uma maquininha neste ponto');
    $ref = 'MN' . date('ymdHis') . strtoupper(substr(rand_token(2), 0, 3));
    q('INSERT INTO be_orders (ref, terminal_id, location_id, customer_id, status, total, total_ml, provider, obs, pago_em) VALUES (?,?,?,0,"pago",0,?,"manual",?,NOW())',
        [$ref, $term, $tap['location_id'], $ml, mb_substr('Liberação manual: ' . arg('motivo', 'teste') . ' — ' . $u['nome'], 0, 255)]);
    $oid = (int)db()->lastInsertId();
    q('INSERT INTO be_order_items (order_id, tap_id, offer_id, produto, volume_ml, qtd, preco_unit) VALUES (?,?,0,?,?,1,0)', [$oid, $tap['id'], $tap['produto'] ?? $tap['nome'], $ml]);
    q('INSERT INTO be_dispenses (order_id, item_id, tap_id, device_id, seq, volume_ml) VALUES (?,?,?,?,1,?)', [$oid, (int)db()->lastInsertId(), $tap['id'], $tap['device_id'], $ml]);
    log_event('aviso', 'liberacao_manual', "🔓 Liberação manual de $ml ml ({$tap['nome']}) por {$u['nome']}: " . arg('motivo', 'teste'), ['order_id' => $oid, 'device_id' => $tap['device_id']], true);
    return ['ok' => true, 'ref' => $ref, 'order_id' => $oid];
}

function r_admin_customers(): array {
    $w = '1=1'; $p = [];
    if (arg('q')) { $w = '(cpf LIKE ? OR nome LIKE ?)'; $s = '%' . preg_replace('/[.\-]/', '', (string)arg('q')) . '%'; $p = [$s, '%' . arg('q') . '%']; }
    $lst = rows("SELECT id, cpf, nome, nascimento, verificado_por, verificado_em, bloqueado, motivo_bloqueio, compras, total_ml, total_gasto, saldo, ultima_compra, created_at,
        (SELECT COUNT(*) FROM be_cards k WHERE k.customer_id = be_customers.id AND k.ativo = 1) cartoes
        FROM be_customers WHERE $w ORDER BY COALESCE(ultima_compra, created_at) DESC LIMIT 200", $p);
    foreach ($lst as &$c) { $c['cpf'] = cpf_mask($c['cpf']); $c['idade'] = idade($c['nascimento']); unset($c['nascimento']); }
    return ['ok' => true, 'clientes' => $lst, 'total' => (int)val('SELECT COUNT(*) FROM be_customers')];
}
function r_admin_customer_block(array $u): array {
    $b = (int)(bool)arg('bloqueado');
    q('UPDATE be_customers SET bloqueado = ?, motivo_bloqueio = ? WHERE id = ?', [$b, $b ? mb_substr((string)arg('motivo', ''), 0, 255) : null, (int)arg('id')]);
    log_event('aviso', 'cliente_' . ($b ? 'bloqueado' : 'desbloqueado'), "Cliente #" . arg('id') . ($b ? ' bloqueado' : ' desbloqueado') . " por {$u['nome']}");
    return ['ok' => true];
}

function r_admin_catalog(): array {
    $devices = rows('SELECT * FROM be_devices ORDER BY id');
    foreach ($devices as &$d) $d['online'] = device_online($d);
    $terms = rows('SELECT id, location_id, tap_id, nome, codigo, provider, serial, app_version, last_seen, aceita_pix, aceita_credito, aceita_debito, bloqueado_ate, ativo, (token IS NOT NULL) pareado FROM be_terminals ORDER BY id');
    foreach ($terms as &$t) $t['online'] = device_online($t);
    return ['ok' => true,
        'locations' => rows('SELECT * FROM be_locations ORDER BY id'),
        'devices' => $devices, 'terminais' => $terms,
        'produtos' => rows('SELECT * FROM be_products ORDER BY ativo DESC, nome'),
        'torneiras' => rows('SELECT t.*, p.nome produto FROM be_taps t LEFT JOIN be_products p ON p.id = t.product_id ORDER BY t.device_id, t.canal'),
        'ofertas' => rows('SELECT * FROM be_offers ORDER BY tap_id, ordem, volume_ml'),
        'cupons' => rows('SELECT * FROM be_coupons ORDER BY ativo DESC, id DESC')];
}

/** Salvar/excluir genérico com lista branca de campos. */
function r_admin_save(array $u): array {
    $map = [
        'locations' => ['be_locations', ['nome', 'endereco', 'ativo']],
        'devices' => ['be_devices', ['location_id', 'nome', 'tipo', 'start_mode', 'arm_timeout_s', 'temp_min', 'temp_max', 'ativo']],
        'terminais' => ['be_terminals', ['location_id', 'tap_id', 'nome', 'provider', 'aceita_pix', 'aceita_credito', 'aceita_debito', 'ativo']],
        'produtos' => ['be_products', ['nome', 'cervejaria', 'estilo', 'descricao', 'abv', 'ibu', 'imagem_url', 'cor', 'ativo']],
        'cupons' => ['be_coupons', ['codigo', 'descricao', 'tipo', 'valor', 'minimo', 'validade', 'usos_max', 'ativo']],
        'torneiras' => ['be_taps', ['device_id', 'canal', 'nome', 'product_id', 'modo', 'pulsos_por_litro', 'ml_por_pulso', 'preco_litro', 'max_ml_servida', 'barril_ml', 'alerta_pct', 'ativo']],
        'ofertas' => ['be_offers', ['tap_id', 'volume_ml', 'preco', 'ordem', 'ativo']],
    ];
    $ent = (string)arg('entity');
    if (!isset($map[$ent])) fail('entidade', 'Entidade inválida');
    [$tab, $campos] = $map[$ent];
    $data = (array)arg('data', []);
    $id = (int)($data['id'] ?? 0);
    if (!empty($data['_delete']) && $id) {
        if ($ent === 'locations' && val('SELECT COUNT(*) FROM be_orders WHERE location_id = ?', [$id])) fail('uso', 'Ponto com vendas — desative em vez de excluir');
        if ($ent === 'torneiras' && val('SELECT COUNT(*) FROM be_order_items WHERE tap_id = ?', [$id])) fail('uso', 'Torneira com vendas — desative em vez de excluir');
        if ($ent === 'devices' && val('SELECT COUNT(*) FROM be_taps WHERE device_id = ?', [$id])) fail('uso', 'Remova as torneiras do controlador antes');
        if ($ent === 'terminais' && val('SELECT COUNT(*) FROM be_orders WHERE terminal_id = ?', [$id])) fail('uso', 'Maquininha com vendas — desative em vez de excluir');
        q("DELETE FROM $tab WHERE id = ?", [$id]);
        return ['ok' => true];
    }
    $vals = [];
    foreach ($campos as $c) if (array_key_exists($c, $data)) $vals[$c] = $data[$c] === '' ? null : $data[$c];
    if ($ent === 'cupons' && isset($vals['codigo'])) $vals['codigo'] = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$vals['codigo']));
    if (!$vals) fail('dados', 'Nada para salvar');
    if ($id) {
        q("UPDATE $tab SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($vals))) . " WHERE id = ?", [...array_values($vals), $id]);
    } else {
        if ($ent === 'devices') $vals['device_key'] = rand_token(16);
        if ($ent === 'terminais') $vals['codigo'] = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        if ($ent === 'torneiras') $vals['restante_ml'] = (int)($vals['barril_ml'] ?? 50000);
        q("INSERT INTO $tab (" . implode(', ', array_keys($vals)) . ") VALUES (" . implode(', ', array_fill(0, count($vals), '?')) . ")", array_values($vals));
        $id = (int)db()->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}
function r_admin_keg_change(array $u): array {
    $t = row('SELECT * FROM be_taps WHERE id = ?', [(int)arg('tap_id')]);
    if (!$t) fail('tap', 'Torneira não encontrada');
    $ml = (int)arg('barril_ml', $t['barril_ml']);
    q('UPDATE be_taps SET barril_ml = ?, restante_ml = ?, alerta_enviado = 0, product_id = COALESCE(?, product_id) WHERE id = ?', [$ml, $ml, arg('product_id') ?: null, $t['id']]);
    log_event('info', 'troca_barril', "🛢️ Barril trocado em {$t['nome']}: " . ml_fmt($ml) . " por {$u['nome']}", ['device_id' => $t['device_id']], true);
    return ['ok' => true];
}
/** Calibração: libere X ml pela torneira, meça na proveta e informe o real. */
function r_admin_calibrate(array $u): array {
    $t = row('SELECT * FROM be_taps WHERE id = ?', [(int)arg('tap_id')]);
    $esp = (float)arg('ml_esperado'); $real = (float)arg('ml_real');
    if (!$t || $esp <= 0 || $real <= 0) fail('dados', 'Informe ml esperado e ml real medido');
    $campo = $t['modo'] === 'flow' ? 'pulsos_por_litro' : 'ml_por_pulso';
    $novo = $t['modo'] === 'flow' ? round($t['pulsos_por_litro'] * $esp / $real, 2) : round($t['ml_por_pulso'] * $real / $esp, 2);
    q("UPDATE be_taps SET $campo = ? WHERE id = ?", [$novo, $t['id']]);
    log_event('info', 'calibracao', "Calibração {$t['nome']}: $campo {$t[$campo]} → $novo (esperado $esp ml, medido $real ml) por {$u['nome']}", ['device_id' => $t['device_id']]);
    return ['ok' => true, 'campo' => $campo, 'antes' => (float)$t[$campo], 'depois' => $novo];
}
function r_admin_events(): array {
    $w = '1=1'; $p = [];
    if (arg('nivel')) { $w .= ' AND nivel = ?'; $p[] = arg('nivel'); }
    if (arg('tipo')) { $w .= ' AND tipo = ?'; $p[] = arg('tipo'); }
    return ['ok' => true, 'eventos' => rows("SELECT * FROM be_events WHERE $w ORDER BY id DESC LIMIT 300", $p)];
}
const SEGREDOS = ['nfeio_api_key', 'serpro_consumer_secret', 'mp_access_token', 'telegram_token'];
function r_admin_settings(): array {
    $s = [];
    foreach (rows('SELECT k, v FROM be_settings WHERE k NOT IN ("serpro_token_cache","ultimo_resumo")') as $r)
        $s[$r['k']] = in_array($r['k'], SEGREDOS, true) && $r['v'] ? '••••' . substr($r['v'], -4) : $r['v'];
    return ['ok' => true, 'settings' => $s, 'webhooks' => [
        'mercadopago' => BE_BASE_URL . '/api.php?r=mp/webhook',
        'telegram' => BE_BASE_URL . '/api.php?r=tg/webhook&key=' . BE_CRON_KEY,
    ]];
}
function r_admin_settings_save(array $u): array {
    foreach ((array)arg('settings', []) as $k => $v) {
        if (!preg_match('/^[a-z0-9_]{2,64}$/', $k) || in_array($k, ['serpro_token_cache', 'ultimo_resumo'], true)) continue;
        if (in_array($k, SEGREDOS, true) && str_starts_with((string)$v, '••••')) continue; // não alterado
        setting_set($k, trim((string)$v));
    }
    log_event('info', 'config', "Configurações alteradas por {$u['nome']}");
    return ['ok' => true];
}
function r_admin_telegram_test(): array {
    $ok = tg_send("✅ <b>" . h(setting('marca_nome', 'Beer Express')) . "</b> conectado ao Telegram.\n\n" . status_texto());
    $wh = null;
    if (setting('telegram_token') && arg('registrar_webhook')) {
        $r = http_json('POST', 'https://api.telegram.org/bot' . setting('telegram_token') . '/setWebhook', [], ['url' => BE_BASE_URL . '/api.php?r=tg/webhook&key=' . BE_CRON_KEY]);
        $wh = $r['body']['ok'] ?? false;
    }
    return ['ok' => $ok, 'webhook' => $wh, 'mensagem' => $ok ? 'Mensagem enviada' : 'Falhou — confira token e chat id (mande /start para o bot antes)'];
}
function r_admin_telemetry(): array {
    $h = max(1, min(720, (int)arg('horas', 24)));
    return ['ok' => true, 'pontos' => rows("SELECT created_at, temperatura, rssi FROM be_telemetry WHERE device_id = ? AND created_at >= NOW() - INTERVAL $h HOUR ORDER BY created_at", [(int)arg('device_id')])];
}

/* ---------- Painel: foto do chopp ---------- */

/** Recebe a foto já reduzida pelo navegador (data URL JPEG/PNG/WebP), valida e salva em uploads/produtos/. */
function r_admin_product_image(array $u): array {
    $id = (int)arg('id');
    $p = row('SELECT * FROM be_products WHERE id = ?', [$id]);
    if (!$p) fail('produto', 'Salve a bebida antes de enviar a foto');
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', (string)arg('imagem'), $m)) fail('imagem', 'Envie uma foto JPG, PNG ou WebP');
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 2 * 1024 * 1024) fail('imagem', 'Foto muito grande (máx. 2 MB)');
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] < 64 || $info[1] < 64) fail('imagem', 'Arquivo não é uma imagem válida');
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']];
    $dir = __DIR__ . '/uploads/produtos';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('imagem', 'Sem permissão para salvar a foto no servidor', 500);
    $nome = 'p' . $id . '_' . date('YmdHis') . '.' . $ext;
    if (@file_put_contents("$dir/$nome", $bin) === false) fail('imagem', 'Falha ao gravar a foto', 500);
    $antiga = (string)$p['imagem_url'];
    if (str_starts_with($antiga, 'uploads/produtos/') && is_file(__DIR__ . '/' . $antiga)) @unlink(__DIR__ . '/' . $antiga);
    q('UPDATE be_products SET imagem_url = ? WHERE id = ?', ["uploads/produtos/$nome", $id]);
    log_event('info', 'foto_produto', "Foto de {$p['nome']} atualizada por {$u['nome']}");
    return ['ok' => true, 'imagem_url' => "uploads/produtos/$nome"];
}

/* ---------- Painel: Modo Pass ---------- */

function r_admin_pours(): array {
    $w = ['1=1']; $p = [];
    if (arg('de')) { $w[] = 'p.inicio_em >= ?'; $p[] = arg('de') . ' 00:00:00'; }
    if (arg('ate')) { $w[] = 'p.inicio_em <= ?'; $p[] = arg('ate') . ' 23:59:59'; }
    if (arg('customer_id')) { $w[] = 'p.customer_id = ?'; $p[] = (int)arg('customer_id'); }
    $where = implode(' AND ', $w);
    $tot = row("SELECT COUNT(*) n, COALESCE(SUM(p.valor),0) s, COALESCE(SUM(p.servido_ml),0) ml FROM be_pours p WHERE $where", $p);
    $lst = rows("SELECT p.*, t.nome torneira, l.nome loc, c.nome cliente, c.cpf FROM be_pours p JOIN be_taps t ON t.id = p.tap_id JOIN be_locations l ON l.id = p.location_id
        JOIN be_customers c ON c.id = p.customer_id WHERE $where ORDER BY p.id DESC LIMIT 300", $p);
    foreach ($lst as &$x) $x['cpf'] = cpf_mask($x['cpf']);
    return ['ok' => true, 'servidas' => $lst, 'total' => (int)$tot['n'], 'soma' => (float)$tot['s'], 'ml' => (int)$tot['ml']];
}
function r_admin_customer(): array {
    $c = row('SELECT * FROM be_customers WHERE id = ?', [(int)arg('id')]);
    if (!$c) fail('cliente', 'Não encontrado', 404);
    $c['cpf'] = cpf_mask($c['cpf']); $c['idade'] = idade($c['nascimento']); unset($c['nascimento']);
    return ['ok' => true, 'cliente' => $c,
        'cartoes' => rows('SELECT id, uid, apelido, ativo, ultimo_uso, created_at FROM be_cards WHERE customer_id = ? ORDER BY id', [$c['id']]),
        'extrato' => rows('SELECT * FROM be_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$c['id']])];
}
function r_admin_wallet_adjust(array $u): array {
    $v = round((float)str_replace(',', '.', (string)arg('valor')), 2);
    $obs = trim((string)arg('obs', ''));
    if (!$v || !$obs) fail('dados', 'Informe valor (+ crédito / − débito) e motivo');
    $novo = carteira_mov((int)arg('customer_id'), 'ajuste', $v, ['obs' => "$obs — {$u['nome']}"]);
    log_event('aviso', 'ajuste_carteira', "Ajuste de " . money($v) . " na carteira do cliente #" . arg('customer_id') . " por {$u['nome']}: $obs", [], true);
    return ['ok' => true, 'saldo' => $novo];
}
function r_admin_card_update(array $u): array {
    $id = (int)arg('id');
    if (arg('desvincular')) q('UPDATE be_cards SET customer_id = NULL WHERE id = ?', [$id]);
    else q('UPDATE be_cards SET ativo = ? WHERE id = ?', [(int)(bool)arg('ativo'), $id]);
    log_event('info', 'cartao', "Cartão #$id alterado por {$u['nome']}");
    return ['ok' => true];
}

/* ---------- BeerList (TV / tela pública do ponto) ---------- */
function r_pub_tv(): array {
    $loc = row('SELECT id, nome FROM be_locations WHERE id = ? AND ativo = 1', [(int)arg('loc', 0)]);
    if (!$loc) fail('ponto', 'Ponto não encontrado', 404);
    $taps = rows('SELECT t.id, t.nome, t.canal, t.preco_litro, t.restante_ml, t.barril_ml, p.nome produto, p.estilo, p.descricao, p.abv, p.cor, p.imagem_url, d.last_seen
        FROM be_taps t JOIN be_devices d ON d.id = t.device_id LEFT JOIN be_products p ON p.id = t.product_id
        WHERE d.location_id = ? AND t.ativo = 1 AND d.ativo = 1 ORDER BY d.id, t.canal', [$loc['id']]);
    $out = [];
    foreach ($taps as $t) {
        $ab = row("SELECT servido_ml, limite_ml FROM be_pours WHERE tap_id = ? AND status = 'aberta' ORDER BY id DESC LIMIT 1", [$t['id']]);
        $out[] = ['torneira' => $t['nome'], 'canal' => (int)$t['canal'], 'produto' => $t['produto'], 'estilo' => $t['estilo'], 'descricao' => $t['descricao'],
            'abv' => $t['abv'] !== null ? (float)$t['abv'] : null, 'cor' => $t['cor'] ?: '#F5B301', 'imagem_url' => $t['imagem_url'],
            'preco_litro' => $t['preco_litro'] !== null && setting('pass_ativo', '0') === '1' ? (float)$t['preco_litro'] : null,
            'ofertas' => array_map(fn($o) => ['volume_ml' => (int)$o['volume_ml'], 'preco' => (float)$o['preco']], rows('SELECT volume_ml, preco FROM be_offers WHERE tap_id = ? AND ativo = 1 ORDER BY ordem, volume_ml', [$t['id']])),
            'nivel_pct' => $t['barril_ml'] ? (int)round(100 * $t['restante_ml'] / $t['barril_ml']) : 0, 'online' => device_online($t),
            'servindo_ml' => $ab ? (int)$ab['servido_ml'] : null];
    }
    $rank = [];
    if (setting('tv_ranking', '0') === '1') {
        foreach (rows("SELECT c.nome, SUM(p.servido_ml) ml FROM be_pours p JOIN be_customers c ON c.id = p.customer_id
                       WHERE p.location_id = ? AND p.status <> 'aberta' AND p.inicio_em >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY c.id ORDER BY ml DESC LIMIT 10", [$loc['id']]) as $r)
            $rank[] = ['nome' => nome_exibicao($r['nome']), 'ml' => (int)$r['ml']];
    }
    $hoje = (int)val("SELECT COALESCE(SUM(servido_ml),0) FROM be_pours WHERE location_id = ? AND inicio_em >= CURDATE()", [$loc['id']])
          + (int)val("SELECT COALESCE(SUM(d.servido_ml),0) FROM be_dispenses d JOIN be_orders o ON o.id = d.order_id WHERE o.location_id = ? AND d.created_at >= CURDATE()", [$loc['id']]);
    return ['ok' => true, 'local' => $loc['nome'], 'marca' => setting('marca_nome', 'Beer Express'), 'slogan' => setting('marca_slogan'), 'cor' => setting('marca_cor', '#F5B301'),
        'mensagem' => setting('tv_mensagem'), 'torneiras' => $out, 'ranking' => $rank, 'litros_hoje' => round($hoje / 1000, 1), 'idade_minima' => (int)setting('idade_minima', 18)];
}

/* ============================== ROTEADOR ============================== */

/* ============================== SAÚDE / MIGRAÇÕES ============================== */

/** Situação do sistema (deploy, monitor do GitHub e "conferir"). */
function be_health(): array {
    $h = ['ok' => false, 'status' => 'ERRO', 'versao' => BE_VERSION, 'db' => false, 'cron_min' => null, 'deploy' => trim((string)@file_get_contents(__DIR__ . '/.be_versao'))];
    try {
        $h['db'] = (int)val('SELECT 1') === 1;
        $h['instalado'] = (bool)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'be_settings'");
        if ($h['instalado']) {
            $c = val("SELECT v FROM be_settings WHERE k = 'cron_ultimo'");
            $h['cron_min'] = $c ? (int)floor((time() - (int)$c) / 60) : null;
            $h['devices_online'] = (int)val('SELECT COUNT(*) FROM be_devices WHERE ativo = 1 AND online = 1');
            $h['terminais_online'] = (int)val('SELECT COUNT(*) FROM be_terminals WHERE ativo = 1 AND online = 1');
        }
        $h['ok'] = $h['db'] && $h['instalado'];
        $h['status'] = $h['ok'] ? 'OK' : 'SEM_TABELAS';
    } catch (Throwable $e) {
        $h['erro'] = 'banco: ' . preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 160));
    }
    return $h;
}

/** Cria as tabelas na 1ª vez (install.sql) e aplica migrations/*.sql pendentes, em ordem. */
function be_migrate(): array {
    $exec = function (string $sql) {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $st) if (trim($st) !== '') db()->exec($st);
    };
    $feito = [];
    db()->exec('CREATE TABLE IF NOT EXISTS be_migrations (nome VARCHAR(120) PRIMARY KEY, aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'be_settings'")) {
        $exec((string)file_get_contents(__DIR__ . '/install.sql'));
        $feito[] = 'install.sql';
    } else {
        $exec((string)file_get_contents(__DIR__ . '/install.sql'));   // idempotente: só acrescenta configurações novas (INSERT IGNORE)
    }
    $ja = array_flip(array_column(rows('SELECT nome FROM be_migrations'), 'nome'));
    $lista = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($lista);
    foreach ($lista as $f) {
        $n = basename($f);
        if (isset($ja[$n])) continue;
        $exec((string)file_get_contents($f));
        q('INSERT INTO be_migrations (nome) VALUES (?)', [$n]);
        $feito[] = $n;
    }
    return $feito;
}

if (defined('BE_LIB_ONLY')) return;

/* Linha de comando: php api.php health | migrate */
if (PHP_SAPI === 'cli') {
    $cmd = $argv[1] ?? '';
    if ($cmd === 'migrate') {
        try { $f = be_migrate(); echo $f ? 'aplicado: ' . implode(', ', $f) . "\n" : "banco em dia\n"; exit(0); }
        catch (Throwable $e) {
            $m = $e->getMessage();
            $dica = str_contains($m, '1049') || stripos($m, 'Unknown database') !== false ? "o banco '" . BE_DB_NAME . "' não existe — crie no hPanel → Bancos de dados MySQL"
                : (str_contains($m, '1045') || stripos($m, 'Access denied') !== false ? "usuário/senha do banco recusados (usuário com " . strlen(BE_DB_USER) . " caracteres, senha com " . strlen(BE_DB_PASS) . ", banco com " . strlen(BE_DB_NAME) . ") — confira BE_DB_USER e BE_DB_PASS e se o usuário foi ligado ao banco no hPanel"
                : (BE_DB_NAME === '' ? 'BE_DB_NAME vazio — cadastre os segredos BE_DB_* no GitHub' : $m));
            fwrite(STDERR, "ERRO no banco: $dica\n"); exit(1);
        }
    }
    if ($cmd === 'health') {
        $h = be_health();
        echo json_encode($h, JSON_UNESCAPED_UNICODE) . "\n";
        exit($h['ok'] ? 0 : 1);
    }
    fwrite(STDERR, "uso: php api.php health|migrate\n"); exit(2);
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Terminal-Token, X-Device-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;

try {
    $r = trim((string)($_GET['r'] ?? ''), '/');
    switch (true) {
        case $r === 'health': $h = be_health(); if (!isset($_GET['full'])) $h = ['ok' => $h['ok'], 'status' => $h['status']]; out($h, $h['ok'] ? 200 : 503);
        case $r === 'pos/pair': out(r_pos_pair());
        case str_starts_with($r, 'pos/'):
            $t = auth_terminal();
            $fn = ['pos/config' => 'r_pos_config', 'pos/menu' => 'r_pos_menu', 'pos/identify' => 'r_pos_identify', 'pos/order' => 'r_pos_order',
                'pos/paid' => 'r_pos_paid', 'pos/payment_failed' => 'r_pos_payment_failed', 'pos/order_status' => 'r_pos_order_status',
                'pos/serve' => 'r_pos_serve', 'pos/cancel' => 'r_pos_cancel', 'pos/wallet' => 'r_pos_wallet', 'pos/recharge' => 'r_pos_recharge',
                'pos/link_card' => 'r_pos_link_card', 'pos/coupon' => 'r_pos_coupon', 'pos/card_block' => 'r_pos_card_block', 'pos/logout' => 'r_pos_logout'][$r] ?? null;
            if ($r === 'pos/heartbeat') { q('UPDATE be_terminals SET app_version = COALESCE(?, app_version) WHERE id = ?', [arg('app_version'), $t['id']]); out(['ok' => true]); }
            if (!$fn) fail('rota', 'Rota inexistente', 404);
            out($fn($t));
        case str_starts_with($r, 'dev/'):
            $d = auth_device();
            $fn = ['dev/poll' => 'r_dev_poll', 'dev/report' => 'r_dev_report', 'dev/stray' => 'r_dev_stray', 'dev/card' => 'r_dev_card', 'dev/pour' => 'r_dev_pour'][$r] ?? null;
            if (!$fn) fail('rota', 'Rota inexistente', 404);
            out($fn($d));
        case $r === 'pub/tv': out(r_pub_tv());
        case $r === 'mp/webhook': out(r_mp_webhook());
        case $r === 'tg/webhook': out(r_tg_webhook());
        case $r === 'admin/needs_setup': out(r_admin_needs_setup());
        case $r === 'admin/setup': out(r_admin_setup());
        case $r === 'admin/login': out(r_admin_login());
        case str_starts_with($r, 'admin/'):
            $u = require_admin();
            $fn = 'r_' . str_replace('/', '_', $r);
            if ($r === 'admin/me') out(['ok' => true, 'user' => $u, 'versao' => BE_VERSION]);
            if (!function_exists($fn)) fail('rota', 'Rota inexistente', 404);
            out($fn($u));
        default: fail('rota', 'Rota inexistente', 404);
    }
} catch (ApiError $e) {
    out(['ok' => false, 'erro' => $e->code_str, 'mensagem' => $e->getMessage()] + $e->extra, $e->http);
} catch (Throwable $e) {
    error_log('[beerexpress] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['ok' => false, 'erro' => 'interno', 'mensagem' => 'Erro interno. Tente novamente.'], 500);
}
