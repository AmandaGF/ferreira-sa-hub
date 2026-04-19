<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "ETAPA 1: total de cases\n";
try {
    $total = $pdo->query("SELECT COUNT(*) FROM cases")->fetchColumn();
    echo "  Total: {$total}\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\nETAPA 2: colunas da tabela cases\n";
$cols = $pdo->query("SHOW COLUMNS FROM cases")->fetchAll();
foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";

echo "\nETAPA 3: últimos 5 cases (só id, client_id, client_title)\n";
try {
    $r = $pdo->query("SELECT id, client_id, client_title, case_type, status FROM cases ORDER BY id DESC LIMIT 5")->fetchAll();
    foreach ($r as $row) echo "  #{$row['id']} | client_id={$row['client_id']} | {$row['client_title']} | {$row['case_type']} | {$row['status']}\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\nETAPA 4: todos os cases com client_id = 447 (Amanda Guedes)\n";
try {
    $r = $pdo->query("SELECT id, client_title, case_type, status FROM cases WHERE client_id = 447")->fetchAll();
    if (empty($r)) echo "  Nenhum.\n";
    else foreach ($r as $row) echo "  #{$row['id']} | {$row['client_title']} | {$row['case_type']} | {$row['status']}\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
