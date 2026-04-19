<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Todos os clientes com nome 'Amanda' ===\n\n";
$clients = $pdo->query("SELECT id, name, phone, email, cpf FROM clients WHERE name LIKE '%Amanda%' ORDER BY id")->fetchAll();
foreach ($clients as $c) {
    echo "  Cliente #{$c['id']} | {$c['name']} | phone={$c['phone']} | cpf={$c['cpf']} | email={$c['email']}\n";
}

echo "\n=== Casos associados a algum Amanda ===\n\n";
$cases = $pdo->query("SELECT c.id, c.client_id, c.client_title, c.case_type, c.status, c.drive_folder_url, cl.name as client_name
                      FROM cases c JOIN clients cl ON cl.id = c.client_id
                      WHERE cl.name LIKE '%Amanda%' ORDER BY c.id DESC")->fetchAll();
foreach ($cases as $c) {
    echo "  Caso #{$c['id']} | client_id={$c['client_id']} ({$c['client_name']}) | {$c['client_title']} | {$c['case_type']} | status={$c['status']} | drive=" . ($c['drive_folder_url'] ? substr($c['drive_folder_url'], 0, 50) . '...' : 'SEM PASTA') . "\n";
}

echo "\n=== Conversa WhatsApp DDD 24 do 24992234554 ===\n\n";
$conv = $pdo->query("SELECT * FROM zapi_conversas WHERE canal = '24' AND telefone LIKE '%4992234554%' LIMIT 1")->fetch();
if ($conv) {
    echo "  Conversa #{$conv['id']} | client_id={$conv['client_id']} | lead_id={$conv['lead_id']}\n";
}
