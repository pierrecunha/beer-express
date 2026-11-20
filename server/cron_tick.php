<?php
/* =====================================================================
 *  BEER EXPRESS — rotina de 1 em 1 minuto
 *  Hostinger → Avançado → Cron Jobs → a cada minuto:
 *    /usr/bin/php /home/u338897690/domains/SEU_DOMINIO/public_html/beerexpress/cron_tick.php
 *  (ou por URL: https://.../beerexpress/cron_tick.php?key=BE_CRON_KEY)
 * ===================================================================== */
define('BE_LIB_ONLY', true);
require __DIR__ . '/api.php';

if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== BE_CRON_KEY) { http_response_code(403); exit('forbidden'); }

$lock = fopen(sys_get_temp_dir() . '/beerexpress_cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("já rodando\n");

setting_set('cron_ultimo', (string)time());   // sinal de vida para o monitor do GitHub
$off = (int)setting('offline_minutos', 3);
$log = [];

/* 1) Chopeiras (ESP32) que ficaram offline */
foreach (rows("SELECT d.*, l.nome loc FROM be_devices d JOIN be_locations l ON l.id = d.location_id
               WHERE d.ativo = 1 AND d.online = 1 AND (d.last_seen IS NULL OR d.last_seen < NOW() - INTERVAL $off MINUTE)") as $d) {
    q('UPDATE be_devices SET online = 0 WHERE id = ?', [$d['id']]);
    log_event('critico', 'device_offline', "🔴 Chopeira {$d['nome']} ({$d['loc']}) OFFLINE desde " . date('H:i', strtotime((string)$d['last_seen'])) . ". Vendas desse ponto ficam suspensas até voltar.",
        ['device_id' => $d['id'], 'location_id' => $d['location_id']], true);
    $log[] = "offline device {$d['id']}";
}

/* 2) Maquininhas offline */
foreach (rows("SELECT t.*, l.nome loc FROM be_terminals t JOIN be_locations l ON l.id = t.location_id
               WHERE t.ativo = 1 AND t.online = 1 AND (t.last_seen IS NULL OR t.last_seen < NOW() - INTERVAL $off MINUTE)") as $t) {
    q('UPDATE be_terminals SET online = 0 WHERE id = ?', [$t['id']]);
    log_event('aviso', 'terminal_offline', "📵 Maquininha {$t['nome']} ({$t['loc']}) sem comunicação desde " . date('H:i', strtotime((string)$t['last_seen'])) . " (bateria, Wi-Fi/chip ou app fechado).",
        ['terminal_id' => $t['id'], 'location_id' => $t['location_id']], true);
}

/* 3) Temperatura fora da faixa por mais de 10 minutos (alerta 1x por hora) */
foreach (rows("SELECT d.*, l.nome loc FROM be_devices d JOIN be_locations l ON l.id = d.location_id
               WHERE d.ativo = 1 AND d.temp_alert_since IS NOT NULL AND d.temp_alert_since < NOW() - INTERVAL 10 MINUTE") as $d) {
    $ja = val("SELECT id FROM be_events WHERE tipo = 'temperatura' AND device_id = ? AND created_at >= NOW() - INTERVAL 60 MINUTE", [$d['id']]);
    if (!$ja) log_event('critico', 'temperatura', "🌡️ Temperatura do freezer {$d['nome']} ({$d['loc']}) em {$d['temperatura']}°C — fora da faixa {$d['temp_min']}°C a {$d['temp_max']}°C há mais de 10 min." .
        ($d['porta_aberta'] ? ' Porta ABERTA.' : ''), ['device_id' => $d['id'], 'location_id' => $d['location_id']], true);
}

/* 4) Copos armados sem retirada (ESP32 travou/reiniciou sem reportar) */
foreach (rows("SELECT ds.*, d.arm_timeout_s FROM be_dispenses ds JOIN be_devices d ON d.id = ds.device_id
               WHERE ds.status IN ('armado','liberado') AND ds.armado_em < NOW() - INTERVAL (d.arm_timeout_s + 90) SECOND") as $ds) {
    q('UPDATE be_dispenses SET status = "timeout", erro = "sem resposta do controlador", fim_em = NOW() WHERE id = ?', [$ds['id']]);
    pedido_recalcular((int)$ds['order_id']);
}
foreach (rows("SELECT * FROM be_dispenses WHERE status = 'servindo' AND COALESCE(inicio_em, armado_em) < NOW() - INTERVAL 5 MINUTE") as $ds) {
    q('UPDATE be_dispenses SET status = "erro", erro = "servindo sem finalizar (controlador reiniciou?)", fim_em = NOW() WHERE id = ?', [$ds['id']]);
    tap_baixar_estoque((int)$ds['tap_id'], (int)$ds['servido_ml']);
    pedido_recalcular((int)$ds['order_id']);
}

/* 5) Pedidos pagos parados na fila (controlador offline) — alerta 1x */
foreach (rows("SELECT o.* FROM be_orders o WHERE o.status = 'pago' AND o.pago_em < NOW() - INTERVAL 10 MINUTE
               AND NOT EXISTS (SELECT 1 FROM be_events e WHERE e.order_id = o.id AND e.tipo = 'pedido_parado')") as $o) {
    log_event('critico', 'pedido_parado', "⏳ Pedido {$o['ref']} (" . money($o['total']) . ") pago há mais de 10 min e ainda não servido. Verifique a chopeira ou estorne.",
        ['order_id' => $o['id'], 'location_id' => $o['location_id']], true);
}

/* 6) Pedidos não pagos abandonados → cancelados */
q("UPDATE be_orders SET status = 'cancelado', obs = 'Expirado sem pagamento' WHERE status = 'aguardando_pagamento' AND created_at < NOW() - INTERVAL ? MINUTE",
    [max(10, (int)setting('pix_expira_min', 5) + 5)]);

/* 7) Desbloqueia maquininhas cujo bloqueio venceu */
q('UPDATE be_terminals SET bloqueado_ate = NULL WHERE bloqueado_ate IS NOT NULL AND bloqueado_ate < NOW()');

/* 8) Resumo diário às 23:55 */
if (setting('tg_resumo_diario', '1') === '1' && date('H:i') >= '23:55' && setting('ultimo_resumo') !== date('Y-m-d')) {
    setting_set('ultimo_resumo', date('Y-m-d'));
    tg_send(resumo_dia(date('Y-m-d')) . "\n\n" . barris_texto());
}

/* 9) Faxina: telemetria > 90 dias, eventos info > 180 dias, tentativas > 365 dias */
if (date('i') === '17') {
    q('DELETE FROM be_telemetry WHERE created_at < NOW() - INTERVAL 90 DAY');
    q("DELETE FROM be_events WHERE nivel = 'info' AND created_at < NOW() - INTERVAL 180 DAY");
    q('DELETE FROM be_verifications WHERE created_at < NOW() - INTERVAL 365 DAY');
}

if (PHP_SAPI === 'cli') echo date('c') . ' ok ' . implode(', ', $log) . "\n";
else echo 'ok';
