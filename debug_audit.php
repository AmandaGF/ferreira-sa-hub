<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Audit Log — Jhonatan / case_deleted ===\n\n";

// Buscar exclusões recentes
$stmt = $pdo->query("SELECT * FROM audit_log WHERE action LIKE '%delete%' OR action LIKE '%exclu%' OR details LIKE '%Jhonatan%' ORDER BY created_at DESC LIMIT 20");
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    echo "ID: " . $r['id'] . "\n";
    echo "Action: " . $r['action'] . "\n";
    echo "Entity: " . ($r['entity_type'] ?? '') . " #" . ($r['entity_id'] ?? '') . "\n";
    echo "Details: " . ($r['details'] ?? '') . "\n";
    echo "User: " . ($r['user_id'] ?? '') . "\n";
    echo "Date: " . ($r['created_at'] ?? '') . "\n";
    echo "---\n";
}

echo "\n=== Buscar client Jhonatan ===\n";
$stmt2 = $pdo->query("SELECT id, name, cpf, phone, email FROM clients WHERE name LIKE '%Jhonatan%' OR name LIKE '%Jonathan%'");
$clients = $stmt2->fetchAll();
foreach ($clients as $c) {
    echo "Client #" . $c['id'] . ": " . $c['name'] . " | CPF: " . ($c['cpf'] ?: '-') . " | Tel: " . ($c['phone'] ?: '-') . "\n";
}

echo "\n=== Cases restantes desse cliente ===\n";
foreach ($clients as $c) {
    $stmt3 = $pdo->prepare("SELECT id, title, case_number, case_type, status, court FROM cases WHERE client_id = ?");
    $stmt3->execute(array($c['id']));
    $cases = $stmt3->fetchAll();
    foreach ($cases as $cs) {
        echo "Case #" . $cs['id'] . ": " . $cs['title'] . " | Nº: " . ($cs['case_number'] ?: '-') . " | Tipo: " . $cs['case_type'] . " | Status: " . $cs['status'] . " | Vara: " . ($cs['court'] ?: '-') . "\n";
    }
    if (empty($cases)) echo "Nenhum caso restante.\n";
}

echo "\n=== Pipeline leads Jhonatan ===\n";
foreach ($clients as $c) {
    $stmt4 = $pdo->prepare("SELECT id, name, stage, case_type, linked_case_id FROM pipeline_leads WHERE client_id = ?");
    $stmt4->execute(array($c['id']));
    $leads = $stmt4->fetchAll();
    foreach ($leads as $l) {
        echo "Lead #" . $l['id'] . ": " . $l['name'] . " | Stage: " . $l['stage'] . " | Tipo: " . ($l['case_type'] ?: '-') . " | Case linked: " . ($l['linked_case_id'] ?: 'NULL') . "\n";
    }
}

echo "\n=== FIM ===\n";
