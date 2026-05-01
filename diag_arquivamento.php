<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG: Arquivamento últimas 72h ===\n";
echo "Hoje: " . date('Y-m-d H:i:s') . "\n\n";

// 1) CASES arquivados recentemente
echo "--- CASES arquivados nas últimas 72h (cases.status='arquivado') ---\n";
$cs = $pdo->query("SELECT id, client_id, title, case_type, status, kanban_oculto, updated_at, closed_at
                   FROM cases
                   WHERE status = 'arquivado'
                     AND updated_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
                   ORDER BY updated_at DESC")->fetchAll();
echo "Total: " . count($cs) . "\n\n";
foreach ($cs as $c) {
    echo "case#{$c['id']} | {$c['title']} | type={$c['case_type']} | kanban_oculto={$c['kanban_oculto']} | updated={$c['updated_at']} | closed={$c['closed_at']}\n";
}
echo "\n";

// 2) LEADS arquivados recentemente
echo "--- LEADS arquivados nas últimas 72h (pipeline_leads.stage='arquivado') ---\n";
$ls = $pdo->query("SELECT id, name, stage, linked_case_id, arquivado_por, arquivado_em, updated_at
                   FROM pipeline_leads
                   WHERE stage = 'arquivado'
                     AND (arquivado_em >= DATE_SUB(NOW(), INTERVAL 72 HOUR) OR updated_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR))
                   ORDER BY COALESCE(arquivado_em, updated_at) DESC")->fetchAll();
echo "Total: " . count($ls) . "\n\n";
foreach ($ls as $l) {
    echo "lead#{$l['id']} | {$l['name']} | linked_case_id={$l['linked_case_id']} | arq_por={$l['arquivado_por']} | arq_em={$l['arquivado_em']} | updated={$l['updated_at']}\n";
}
echo "\n";

// 3) Audit log de arquivamento últimas 72h
echo "--- AUDIT_LOG com 'arquivad' nas últimas 72h ---\n";
$al = $pdo->query("SELECT created_at, user_id, action, entity_type, entity_id, details
                   FROM audit_log
                   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
                     AND (action LIKE '%arquiv%' OR details LIKE '%arquiv%')
                   ORDER BY id DESC LIMIT 200")->fetchAll();
echo "Total entradas: " . count($al) . "\n\n";
foreach ($al as $a) {
    echo "{$a['created_at']} | uid={$a['user_id']} | {$a['action']} | {$a['entity_type']}#{$a['entity_id']} | " . substr($a['details'], 0, 150) . "\n";
}
echo "\n";

// 4) Audit_log com lead_moved -> arquivado / -> perdido / -> finalizado nas últimas 72h
echo "--- AUDIT_LOG lead_moved (qualquer destino) últimas 72h ---\n";
$lm = $pdo->query("SELECT created_at, user_id, action, entity_id, details
                   FROM audit_log
                   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
                     AND action = 'lead_moved'
                   ORDER BY id DESC LIMIT 200")->fetchAll();
echo "Total: " . count($lm) . "\n\n";
foreach ($lm as $a) {
    echo "{$a['created_at']} | uid={$a['user_id']} | lead#{$a['entity_id']} | {$a['details']}\n";
}
echo "\n";

// 5) Pra cada lead arquivado, ver o último stage antes (via audit_log lead_moved)
echo "--- HISTÓRICO de cada LEAD arquivado: último stage de origem ---\n";
foreach ($ls as $l) {
    $lid = (int)$l['id'];
    $hist = $pdo->prepare("SELECT created_at, details FROM audit_log WHERE entity_type='lead' AND entity_id=? AND action='lead_moved' ORDER BY id DESC LIMIT 5");
    $hist->execute(array($lid));
    $movs = $hist->fetchAll();
    echo "lead#{$lid} {$l['name']}:\n";
    if (empty($movs)) echo "  (sem audit_log de lead_moved)\n";
    foreach ($movs as $m) echo "  {$m['created_at']} — {$m['details']}\n";
}
echo "\n";

// 6) Pra cada case arquivado, ver mudanças de status
echo "--- HISTÓRICO de cada CASE arquivado ---\n";
foreach ($cs as $c) {
    $cid = (int)$c['id'];
    $hist = $pdo->prepare("SELECT created_at, action, details FROM audit_log WHERE entity_type='case' AND entity_id=? AND (action LIKE '%status%' OR action LIKE '%arquiv%' OR action LIKE '%reconcile%' OR action='case_status_changed') ORDER BY id DESC LIMIT 5");
    $hist->execute(array($cid));
    $movs = $hist->fetchAll();
    echo "case#{$cid} {$c['title']}:\n";
    if (empty($movs)) echo "  (sem audit_log relevante)\n";
    foreach ($movs as $m) echo "  {$m['created_at']} — {$m['action']} — {$m['details']}\n";
}
echo "\n";

echo "=== FIM ===\n";
