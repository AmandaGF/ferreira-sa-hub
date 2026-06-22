<?php
/** Diag temporário: exercita comercial_fetch (read-only). Remover após uso. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_comercial.php';
$pdo = db();
comercial_self_heal($pdo);

try {
    $pend = comercial_fetch($pdo, 'recebida', 45, 0, 300);
    echo "PENDENTES (ultima=lead, 45d): " . count($pend) . "\n";
    foreach (array_slice($pend, 0, 5) as $r) {
        $resp = comercial_responsavel_id($r);
        echo "  conv#{$r['conversa_id']} " . ($r['lead_name'] ?: $r['nome_contato'] ?: $r['telefone'])
           . " | resp=" . ($resp ?: 'sem dono') . " | ult=" . $r['ultima_em'] . "\n";
    }
    $fup = comercial_fetch($pdo, 'enviada', 45, 0, 300);
    echo "\nFOLLOW-UP (ultima=nossa, 45d): " . count($fup) . "\n";
    foreach (array_slice($fup, 0, 5) as $r) {
        echo "  conv#{$r['conversa_id']} " . ($r['lead_name'] ?: $r['nome_contato'] ?: $r['telefone'])
           . " | ult_nossa=" . $r['ultima_nossa_em'] . "\n";
    }
    echo "\nOK — queries rodaram sem erro.\n";
} catch (Exception $e) {
    echo "ERRO SQL: " . $e->getMessage() . "\n";
}
