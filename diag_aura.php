<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG: Aura Tijuca ===\n\n";

// 1) Buscar cliente/lead por nome contendo "Aura"
$cli = $pdo->prepare("SELECT id, name, phone, email, created_at FROM clients WHERE name LIKE ? ORDER BY id DESC LIMIT 10");
$cli->execute(array('%Aura%'));
$clientes = $cli->fetchAll();
echo "--- CLIENTES (clients) com 'Aura' no nome ---\n";
foreach ($clientes as $c) {
    echo "id={$c['id']} | nome={$c['name']} | tel={$c['phone']} | email={$c['email']} | criado={$c['created_at']}\n";
}
echo (empty($clientes) ? "(nenhum)\n" : "") . "\n";

// 2) Lead Aura
$lead = $pdo->prepare("SELECT id, name, stage, case_type, client_id, linked_case_id, converted_at, created_at, updated_at FROM pipeline_leads WHERE name LIKE ? ORDER BY id DESC LIMIT 10");
$lead->execute(array('%Aura%'));
$leads = $lead->fetchAll();
echo "--- LEADS (pipeline_leads) ---\n";
foreach ($leads as $l) {
    echo "id={$l['id']} | nome={$l['name']} | stage={$l['stage']} | client_id={$l['client_id']} | linked_case_id={$l['linked_case_id']} | atualizado={$l['updated_at']}\n";
}
echo (empty($leads) ? "(nenhum)\n" : "") . "\n";

// 3) Cases vinculados
$clientIds = array_column($clientes, 'id');
$caseIds = array();
foreach ($leads as $l) if ($l['linked_case_id']) $caseIds[] = $l['linked_case_id'];
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
    $sql = "SELECT id, client_id, title, case_type, status, drive_folder_url, created_at FROM cases WHERE " . implode(' OR ', $where) . " ORDER BY id DESC";
    $cs = $pdo->prepare($sql);
    $cs->execute($params);
    foreach ($cs->fetchAll() as $c) {
        $drive = $c['drive_folder_url'] ? substr($c['drive_folder_url'], 0, 70) . '...' : '(SEM PASTA)';
        echo "id={$c['id']} | client_id={$c['client_id']} | {$c['title']} | type={$c['case_type']} | status={$c['status']} | drive={$drive} | criado={$c['created_at']}\n";
    }
}
echo "\n";

// 4) Audit log do(s) lead(s)
echo "--- AUDIT LOG ---\n";
foreach ($leads as $l) {
    $a = $pdo->prepare("SELECT created_at, action, entity_type, entity_id, details, user_id FROM audit_log WHERE (action='lead_moved' AND entity_id=?) OR (action='case_auto_created' AND details LIKE ?) ORDER BY id DESC LIMIT 20");
    $a->execute(array($l['id'], '%lead: ' . $l['id'] . '%'));
    while ($row = $a->fetch()) {
        echo "{$row['created_at']} | uid={$row['user_id']} | {$row['action']} | entity={$row['entity_type']}#{$row['entity_id']} | {$row['details']}\n";
    }
}
echo "\n";

// 5) TESTAR Apps Script: chamada direta com teste
echo "--- TESTE Apps Script (chamada direta) ---\n";
if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) {
    echo "GOOGLE_APPS_SCRIPT_URL nao configurado!\n";
} else {
    echo "URL: " . substr(GOOGLE_APPS_SCRIPT_URL, 0, 80) . "...\n";
    $payload = json_encode(array(
        'folderName'  => 'TESTE DIAG ' . date('YmdHis'),
        'clientName'  => 'TESTE DIAG',
        'caseType'    => 'outro',
        'caseId'      => 0,
        'caseTitle'   => 'TESTE DIAG (não usar)',
        'timestamp'   => date('Y-m-d H:i:s'),
    ));
    $ch = curl_init(GOOGLE_APPS_SCRIPT_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $t0 = microtime(true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $elapsed = round((microtime(true) - $t0) * 1000);
    curl_close($ch);
    echo "HTTP: {$httpCode} | tempo: {$elapsed}ms\n";
    if ($error) echo "cURL erro: {$error}\n";
    echo "Resposta (1500 primeiros chars):\n" . substr((string)$response, 0, 1500) . "\n";
}

echo "\n=== FIM ===\n";
