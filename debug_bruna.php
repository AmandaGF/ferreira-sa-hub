<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Debug: BRUNA SENA OLIVEIRA ===\n\n";

// 1. Buscar cliente
$stmt = $pdo->query("SELECT id, name, phone, email FROM clients WHERE name LIKE '%BRUNA SENA%' OR name LIKE '%Bruna Sena%'");
$clients = $stmt->fetchAll();
echo "--- Clientes ---\n";
foreach ($clients as $c) {
    echo "Client #" . $c['id'] . ": " . $c['name'] . " | Tel: " . ($c['phone'] ?: '-') . "\n";
}

// 2. Buscar casos
echo "\n--- Cases ---\n";
foreach ($clients as $c) {
    $stmt2 = $pdo->prepare("SELECT id, title, status, case_type, case_number, responsible_user_id, stage_antes_doc_faltante, kanban_oculto FROM cases WHERE client_id = ?");
    $stmt2->execute(array($c['id']));
    $cases = $stmt2->fetchAll();
    foreach ($cases as $cs) {
        echo "Case #" . $cs['id'] . ": " . $cs['title'] . " | Status: " . $cs['status'] . " | Tipo: " . $cs['case_type'] . " | Resp: " . $cs['responsible_user_id'] . " | stage_antes_doc: " . ($cs['stage_antes_doc_faltante'] ?: 'NULL') . " | oculto: " . $cs['kanban_oculto'] . "\n";
    }
}

// 3. Buscar leads no pipeline
echo "\n--- Pipeline Leads ---\n";
foreach ($clients as $c) {
    $stmt3 = $pdo->prepare("SELECT id, name, stage, case_type, linked_case_id, doc_faltante_motivo, stage_antes_doc_faltante, coluna_antes_suspensao FROM pipeline_leads WHERE client_id = ?");
    $stmt3->execute(array($c['id']));
    $leads = $stmt3->fetchAll();
    foreach ($leads as $l) {
        echo "Lead #" . $l['id'] . ": " . $l['name'] . " | Stage: " . $l['stage'] . " | Tipo: " . ($l['case_type'] ?: '-') . " | Case linked: " . ($l['linked_case_id'] ?: 'NULL') . " | doc_motivo: " . ($l['doc_faltante_motivo'] ?: 'NULL') . " | stage_antes_doc: " . ($l['stage_antes_doc_faltante'] ?: 'NULL') . "\n";
    }
}

// 4. Documentos pendentes
echo "\n--- Documentos Pendentes ---\n";
foreach ($clients as $c) {
    $stmt4 = $pdo->prepare("SELECT id, case_id, lead_id, descricao, status, solicitado_em FROM documentos_pendentes WHERE client_id = ? ORDER BY solicitado_em DESC");
    $stmt4->execute(array($c['id']));
    $docs = $stmt4->fetchAll();
    foreach ($docs as $d) {
        echo "Doc #" . $d['id'] . ": " . $d['descricao'] . " | Status: " . $d['status'] . " | Case: " . ($d['case_id'] ?: 'NULL') . " | Lead: " . ($d['lead_id'] ?: 'NULL') . " | Dt: " . $d['solicitado_em'] . "\n";
    }
}

echo "\n=== FIM ===\n";
