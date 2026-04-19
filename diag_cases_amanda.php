<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Buscar cases por qualquer campo com 'Amanda Guedes' ou telefone ===\n\n";
$rows = $pdo->query("SELECT id, client_id, client_title, case_type, case_number, status, drive_folder_url
                     FROM cases
                     WHERE client_title LIKE '%Amanda Guedes%'
                        OR client_title LIKE '%AMANDA GUEDES%'
                        OR case_number LIKE '%Amanda%'
                     ORDER BY id DESC LIMIT 20")->fetchAll();
if (empty($rows)) echo "  Nenhum.\n";
foreach ($rows as $r) {
    echo "  #{$r['id']} | client_id={$r['client_id']} | {$r['client_title']} | {$r['case_type']} | {$r['status']}\n";
    echo "      Número: {$r['case_number']}\n";
    echo "      Drive: " . ($r['drive_folder_url'] ? substr($r['drive_folder_url'], 0, 50) : 'SEM') . "\n";
}

echo "\n=== Todos os campos de 1 case (qualquer) pra eu ver a estrutura ===\n\n";
$sample = $pdo->query("SELECT * FROM cases ORDER BY id DESC LIMIT 1")->fetch();
if ($sample) {
    foreach ($sample as $k => $v) echo "  $k: " . substr((string)$v, 0, 80) . "\n";
}
