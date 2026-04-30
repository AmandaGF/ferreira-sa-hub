<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG: Jenyfer Cruz da Costa ===\n\n";

// 1) Buscar cliente
$cli = $pdo->prepare("SELECT id, name, phone, email, created_at FROM clients WHERE name LIKE ? ORDER BY id");
$cli->execute(array('%Jenyfer%Cruz%'));
$clientes = $cli->fetchAll();
echo "--- CLIENTES (clients) ---\n";
foreach ($clientes as $c) {
    echo "id={$c['id']} | nome={$c['name']} | tel={$c['phone']} | email={$c['email']} | criado={$c['created_at']}\n";
}
echo (empty($clientes) ? "(nenhum)\n" : "") . "\n";

// 2) Buscar lead
$lead = $pdo->prepare("SELECT id, name, phone, email, stage, case_type, client_id, linked_case_id, assigned_to, valor_acao, honorarios_cents, estimated_value_cents, converted_at, created_at, updated_at FROM pipeline_leads WHERE name LIKE ? ORDER BY id");
$lead->execute(array('%Jenyfer%Cruz%'));
$leads = $lead->fetchAll();
echo "--- LEADS (pipeline_leads) ---\n";
foreach ($leads as $l) {
    echo "id={$l['id']} | nome={$l['name']} | stage={$l['stage']} | client_id={$l['client_id']} | linked_case_id={$l['linked_case_id']} | case_type={$l['case_type']} | converted_at={$l['converted_at']} | atualizado={$l['updated_at']}\n";
}
echo (empty($leads) ? "(nenhum)\n" : "") . "\n";

// 3) Cases vinculados (por cliente OU linked_case_id)
$clientIds = array_column($clientes, 'id');
$caseIds = array();
foreach ($leads as $l) {
    if ($l['linked_case_id']) $caseIds[] = $l['linked_case_id'];
}
echo "--- CASES (cases) ---\n";
if (!empty($clientIds) || !empty($caseIds)) {
    $where = array();
    $params = array();
    if (!empty($clientIds)) {
        $where[] = "client_id IN (" . implode(',', array_fill(0, count($clientIds), '?')) . ")";
        $params = array_merge($params, $clientIds);
    }
    if (!empty($caseIds)) {
        $where[] = "id IN (" . implode(',', array_fill(0, count($caseIds), '?')) . ")";
        $params = array_merge($params, $caseIds);
    }
    $sql = "SELECT id, client_id, title, case_type, status, drive_folder_url, created_at FROM cases WHERE " . implode(' OR ', $where) . " ORDER BY id";
    $cs = $pdo->prepare($sql);
    $cs->execute($params);
    $cases = $cs->fetchAll();
    foreach ($cases as $c) {
        $drive = $c['drive_folder_url'] ? substr($c['drive_folder_url'], 0, 70) . '...' : '(SEM PASTA)';
        echo "id={$c['id']} | client_id={$c['client_id']} | title={$c['title']} | case_type={$c['case_type']} | status={$c['status']} | drive={$drive} | criado={$c['created_at']}\n";
    }
    if (empty($cases)) echo "(nenhum)\n";
}
echo "\n";

// 4) Audit log do(s) lead(s)
echo "--- AUDIT LOG (lead_moved + case_auto_created) ---\n";
foreach ($leads as $l) {
    $a = $pdo->prepare("SELECT id, action, entity_type, entity_id, details, user_id, created_at FROM audit_log WHERE (action='lead_moved' AND entity_id=?) OR (action='case_auto_created' AND details LIKE ?) ORDER BY id DESC LIMIT 30");
    $a->execute(array($l['id'], '%lead: ' . $l['id'] . '%'));
    while ($row = $a->fetch()) {
        echo "{$row['created_at']} | uid={$row['user_id']} | {$row['action']} | entity={$row['entity_type']}#{$row['entity_id']} | {$row['details']}\n";
    }
}
echo "\n";

// 5) Audit log com Drive
echo "--- AUDIT LOG drive_folder_created (recentes da Jenyfer) ---\n";
foreach ($caseIds as $cid) {
    $a = $pdo->prepare("SELECT created_at, details FROM audit_log WHERE action='drive_folder_created' AND entity_id=?");
    $a->execute(array($cid));
    while ($row = $a->fetch()) {
        echo "case#{$cid} | {$row['created_at']} | {$row['details']}\n";
    }
}
foreach ($clientes as $c) {
    $cs2 = $pdo->prepare("SELECT id FROM cases WHERE client_id=?");
    $cs2->execute(array($c['id']));
    while ($cid = $cs2->fetchColumn()) {
        $a = $pdo->prepare("SELECT created_at, details FROM audit_log WHERE action='drive_folder_created' AND entity_id=?");
        $a->execute(array($cid));
        while ($row = $a->fetch()) {
            echo "case#{$cid} | {$row['created_at']} | {$row['details']}\n";
        }
    }
}
echo "\n=== FIM ===\n";
