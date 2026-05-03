<?php
/**
 * Apaga as 4 suspensões cadastradas indevidamente em 13–16/abril/2026.
 * IDs 288, 289, 290, 291 — todas com motivo "Suspensão de prazos" sem ato/legislação,
 * cadastradas em lote por Amanda em 26/04/2026 09:34. Confirmado por ela que não há
 * feriado real nesses dias.
 */
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$idsAlvo = array(288, 289, 290, 291);
$inIds = implode(',', array_map('intval', $idsAlvo));

echo "=== Antes ===\n";
$st = $pdo->query("SELECT id, data_inicio, motivo FROM prazos_suspensoes WHERE id IN ($inIds)");
$achadas = $st->fetchAll();
foreach ($achadas as $r) {
    echo "  #{$r['id']}  {$r['data_inicio']}  {$r['motivo']}\n";
}
if (!$achadas) { echo "  (nenhuma — talvez já apagadas)\n"; exit; }

$pdo->exec("DELETE FROM prazos_suspensoes WHERE id IN ($inIds)");

echo "\n=== Depois ===\n";
$check = $pdo->query("SELECT id FROM prazos_suspensoes WHERE id IN ($inIds)")->fetchAll();
echo "  restantes: " . count($check) . "\n";

if (function_exists('audit_log')) {
    try { audit_log('prazos_suspensoes_delete', 'prazos_suspensoes', 0, 'Apagou IDs ' . $inIds . ' (cadastros indevidos abril/2026 sem ato/legislacao)'); } catch (Exception $e) {}
}

echo "\nOK — 4 suspensoes removidas. Recalcule o prazo na calculadora pra confirmar.\n";
