<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Debug: ANA CAROLINE DIAS PEREIRA ===\n\n";

// Cliente
$stmt = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%CAROLINE DIAS PEREIRA%' OR name LIKE '%Caroline Dias Pereira%'");
$clients = $stmt->fetchAll();
echo "--- Clientes ---\n";
foreach ($clients as $c) echo "Client #" . $c['id'] . ": " . $c['name'] . "\n";

// Cases
echo "\n--- Cases ---\n";
foreach ($clients as $c) {
    $stmt2 = $pdo->prepare("SELECT id, title, status, case_type, stage_antes_doc_faltante FROM cases WHERE client_id = ?");
    $stmt2->execute(array($c['id']));
    foreach ($stmt2->fetchAll() as $cs) {
        echo "Case #" . $cs['id'] . ": " . $cs['title'] . " | Status: " . $cs['status'] . " | stage_antes_doc: " . ($cs['stage_antes_doc_faltante'] ?: 'NULL') . "\n";
    }
}

// Leads
echo "\n--- Pipeline Leads ---\n";
foreach ($clients as $c) {
    $stmt3 = $pdo->prepare("SELECT id, name, stage, linked_case_id, doc_faltante_motivo, stage_antes_doc_faltante FROM pipeline_leads WHERE client_id = ?");
    $stmt3->execute(array($c['id']));
    foreach ($stmt3->fetchAll() as $l) {
        echo "Lead #" . $l['id'] . " stage=" . $l['stage'] . " linked_case=" . ($l['linked_case_id'] ?: 'NULL') . " motivo=" . ($l['doc_faltante_motivo'] ?: 'NULL') . " stage_antes_doc=" . ($l['stage_antes_doc_faltante'] ?: 'NULL') . "\n";
    }
}

// Documentos pendentes
echo "\n--- Documentos Pendentes ---\n";
foreach ($clients as $c) {
    $stmt4 = $pdo->prepare("SELECT id, case_id, lead_id, descricao, status FROM documentos_pendentes WHERE client_id = ?");
    $stmt4->execute(array($c['id']));
    foreach ($stmt4->fetchAll() as $d) {
        echo "Doc #" . $d['id'] . " case=" . ($d['case_id'] ?: 'NULL') . " lead=" . ($d['lead_id'] ?: 'NULL') . " status=" . $d['status'] . " :: " . $d['descricao'] . "\n";
    }
}

// Verificar se o banner do Kanban Comercial detecta este caso
echo "\n--- Query do Kanban Comercial (doc_faltante) ---\n";
$stmt5 = $pdo->query("SELECT id, name, stage, linked_case_id FROM pipeline_leads WHERE stage = 'doc_faltante' ORDER BY id DESC LIMIT 20");
$leads = $stmt5->fetchAll();
echo "Total leads em 'doc_faltante': " . count($leads) . "\n";
foreach ($leads as $l) {
    echo "  Lead #" . $l['id'] . " - " . $l['name'] . " (case " . ($l['linked_case_id'] ?: 'NULL') . ")\n";
}

// Verificar enum stages do pipeline_leads
echo "\n--- Estrutura pipeline_leads.stage ---\n";
$stmt6 = $pdo->query("SHOW COLUMNS FROM pipeline_leads LIKE 'stage'");
$col = $stmt6->fetch();
echo "Type: " . $col['Type'] . "\n";

echo "\n=== FIM ===\n";
