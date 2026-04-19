<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Case 779 e o outro da Amanda ===\n\n";
$rows = $pdo->query("SELECT cs.id, cs.client_id, cs.title, cs.case_type, cs.status, cs.drive_folder_url,
                            cl.name AS cliente_nome, cl.phone AS cliente_phone, cl.cpf AS cliente_cpf
                     FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id
                     WHERE cs.title LIKE '%Amanda%'
                     ORDER BY cs.id DESC")->fetchAll();
foreach ($rows as $r) {
    echo "Case #{$r['id']}\n";
    echo "  Título: {$r['title']}\n";
    echo "  Tipo: {$r['case_type']} | Status: {$r['status']}\n";
    echo "  client_id NO CASE: {$r['client_id']}\n";
    echo "  Cliente vinculado: {$r['cliente_nome']} (phone={$r['cliente_phone']}, cpf={$r['cliente_cpf']})\n";
    echo "  Drive: " . ($r['drive_folder_url'] ? substr($r['drive_folder_url'], 0, 60) . '...' : 'SEM PASTA') . "\n\n";
}

echo "=== Registros de clients com nome contendo 'Amanda Guedes' (buscando duplicatas) ===\n";
$rows = $pdo->query("SELECT id, name, phone, cpf, email FROM clients
                     WHERE name LIKE '%Amanda Guedes%' OR name LIKE '%amanda guedes%' OR cpf = '121.538.287-16'
                     ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    echo "  Client #{$r['id']} | {$r['name']} | phone={$r['phone']} | cpf={$r['cpf']} | email={$r['email']}\n";
}
