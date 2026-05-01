<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG 2: Origem dos cases com kanban_oculto=1 ===\n\n";

echo "--- Agrupado por status: ---\n";
$g = $pdo->query("SELECT status, COUNT(*) AS qtd FROM cases WHERE kanban_oculto = 1 GROUP BY status ORDER BY qtd DESC")->fetchAll();
$total = 0;
foreach ($g as $r) {
    echo str_pad((string)($r['status'] ?? '(null)'), 30) . $r['qtd'] . "\n";
    $total += $r['qtd'];
}
echo "TOTAL: $total\n\n";

echo "--- Cases ocultos com status NÃO arquivado/cancelado/concluido (estes provavelmente NÃO deveriam estar ocultos): ---\n";
$rows = $pdo->query("SELECT id, title, status, kanban_oculto, updated_at, closed_at, opened_at, created_at
                     FROM cases
                     WHERE kanban_oculto = 1
                       AND status NOT IN ('arquivado','cancelado','concluido','finalizado')
                     ORDER BY updated_at DESC")->fetchAll();
echo "Total: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "case#{$r['id']} | {$r['title']} | status={$r['status']} | updated={$r['updated_at']} | closed={$r['closed_at']} | opened={$r['opened_at']} | created={$r['created_at']}\n";
}
echo "\n";

// Pra cada case oculto sem status fechado, ver TODO audit_log
echo "--- AUDIT_LOG completo de cada case oculto problemático (top 30 mais recentes) ---\n";
$ids = array_column(array_slice($rows, 0, 30), 'id');
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $aud = $pdo->prepare("SELECT created_at, user_id, action, entity_id, details FROM audit_log
                          WHERE entity_type='case' AND entity_id IN ({$placeholders})
                          ORDER BY entity_id, id DESC");
    $aud->execute($ids);
    $aud_rows = $aud->fetchAll();
    $por_case = array();
    foreach ($aud_rows as $a) $por_case[$a['entity_id']][] = $a;
    foreach ($ids as $cid) {
        echo "\n>>> case#{$cid}:\n";
        if (empty($por_case[$cid])) {
            echo "  (sem audit_log)\n";
        } else {
            foreach (array_slice($por_case[$cid], 0, 8) as $a) {
                echo "  {$a['created_at']} | uid={$a['user_id']} | {$a['action']} | " . substr($a['details'], 0, 120) . "\n";
            }
        }
    }
}
echo "\n=== FIM ===\n";
