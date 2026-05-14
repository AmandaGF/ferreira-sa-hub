<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

$rows = $pdo->query("SELECT id, protocol, client_name, created_at, updated_at, payload_json FROM form_submissions WHERE form_type='despesas_mensais' ORDER BY id DESC LIMIT 5")->fetchAll();

foreach ($rows as $r) {
    echo "=== #{$r['id']} | {$r['protocol']} | {$r['client_name']} | {$r['created_at']} (upd: {$r['updated_at']}) ===\n";
    $p = json_decode($r['payload_json'], true) ?: array();
    echo "Total de chaves no payload: " . count($p) . "\n";

    // Pega chaves de valor (money) — pulam strings, mostram só numéricos não-zero
    $valorChaves = array();
    foreach ($p as $k => $v) {
        if (is_numeric($v) && (float)$v != 0) $valorChaves[$k] = $v;
    }
    echo "Campos numéricos NÃO-zero (top 20): " . count($valorChaves) . "\n";
    $i = 0;
    foreach ($valorChaves as $k => $v) {
        echo "  {$k} = {$v}" . (is_int($v) || (is_numeric($v) && strpos((string)$v, '.') === false) ? ' (int — provável CENTAVOS)' : ' (decimal — provável REAIS)') . "\n";
        if (++$i >= 20) break;
    }

    // Mostra os totais calculados pelo JS (que vêm no payload)
    echo "\nTotais calculados pelo JS (campos total_*):\n";
    foreach ($p as $k => $v) {
        if (strpos($k, 'total_') === 0) echo "  {$k} = {$v}\n";
    }

    echo "\nrenda_mensal (raw): " . var_export($p['renda_mensal'] ?? null, true) . "\n";
    echo "\n";
}
