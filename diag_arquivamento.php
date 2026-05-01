<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$dias = (int)($_GET['dias'] ?? 14);
echo "=== DIAG: O que sumiu do Kanban nos últimos {$dias} dias ===\n";
echo "Hoje: " . date('Y-m-d H:i:s') . "\n\n";

$kanbanCaseHidden = array('arquivado','cancelado','concluido','finalizado');
$kanbanLeadHidden = array('arquivado','perdido','finalizado','cancelado');

echo "--- CASES que sumiram do Kanban nos últimos {$dias} dias ---\n";
$placeholders = implode(',', array_fill(0, count($kanbanCaseHidden), '?'));
$cs = $pdo->prepare("SELECT id, client_id, title, case_type, status, kanban_oculto, updated_at, closed_at
                     FROM cases
                     WHERE updated_at >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
                       AND (status IN ({$placeholders}) OR kanban_oculto = 1)
                     ORDER BY updated_at DESC");
$cs->execute($kanbanCaseHidden);
$rows = $cs->fetchAll();
echo "Total: " . count($rows) . "\n\n";
foreach ($rows as $c) {
    echo "case#{$c['id']} | {$c['title']} | status={$c['status']} | oculto={$c['kanban_oculto']} | updated={$c['updated_at']} | closed={$c['closed_at']}\n";
}
echo "\n";

echo "--- LEADS que sumiram do Kanban Comercial nos últimos {$dias} dias ---\n";
$placeholders2 = implode(',', array_fill(0, count($kanbanLeadHidden), '?'));
$ls = $pdo->prepare("SELECT id, name, stage, linked_case_id, arquivado_por, arquivado_em, updated_at, created_at
                     FROM pipeline_leads
                     WHERE updated_at >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
                       AND stage IN ({$placeholders2})
                     ORDER BY updated_at DESC");
$ls->execute($kanbanLeadHidden);
$lrows = $ls->fetchAll();
echo "Total: " . count($lrows) . "\n\n";
foreach ($lrows as $l) {
    echo "lead#{$l['id']} | {$l['name']} | stage={$l['stage']} | linked_case_id={$l['linked_case_id']} | arq_em={$l['arquivado_em']} | updated={$l['updated_at']} | created={$l['created_at']}\n";
}
echo "\n";

echo "--- AUDIT_LOG (arquivar/ocultar/status/reconcile) últimos {$dias} dias ---\n";
$al = $pdo->prepare("SELECT created_at, user_id, action, entity_type, entity_id, details
                     FROM audit_log
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
                       AND (action LIKE '%arquiv%' OR action LIKE '%oculta%' OR action LIKE '%kanban%' OR action LIKE '%reconcile%' OR action LIKE '%case_status%' OR action LIKE '%lead_moved%')
                     ORDER BY id DESC LIMIT 500");
$al->execute();
$alrows = $al->fetchAll();
echo "Total: " . count($alrows) . "\n\n";
foreach ($alrows as $a) {
    echo "{$a['created_at']} | uid={$a['user_id']} | {$a['action']} | {$a['entity_type']}#{$a['entity_id']} | " . substr($a['details'], 0, 200) . "\n";
}
echo "\n";

echo "--- Contagem TOTAL atual — cases.status ---\n";
$ct = $pdo->query("SELECT status, COUNT(*) AS qtd FROM cases GROUP BY status ORDER BY qtd DESC")->fetchAll();
foreach ($ct as $r) echo str_pad((string)($r['status'] ?? '(null)'), 30) . $r['qtd'] . "\n";
echo "kanban_oculto=1: " . $pdo->query("SELECT COUNT(*) FROM cases WHERE kanban_oculto = 1")->fetchColumn() . "\n\n";

echo "--- Contagem TOTAL atual — pipeline_leads.stage ---\n";
$lt = $pdo->query("SELECT stage, COUNT(*) AS qtd FROM pipeline_leads GROUP BY stage ORDER BY qtd DESC")->fetchAll();
foreach ($lt as $r) echo str_pad((string)($r['stage'] ?? '(null)'), 30) . $r['qtd'] . "\n";
echo "\n";

echo "--- Ações de uid=0 ou NULL (sistema/cron) últimos {$dias} dias ---\n";
$sys = $pdo->prepare("SELECT created_at, action, entity_type, entity_id, details
                      FROM audit_log
                      WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
                        AND (user_id = 0 OR user_id IS NULL)
                      ORDER BY id DESC LIMIT 200");
$sys->execute();
$sysrows = $sys->fetchAll();
echo "Total: " . count($sysrows) . "\n\n";
foreach ($sysrows as $a) {
    echo "{$a['created_at']} | {$a['action']} | {$a['entity_type']}#{$a['entity_id']} | " . substr($a['details'], 0, 200) . "\n";
}
echo "\n";

echo "=== FIM ===\n";
