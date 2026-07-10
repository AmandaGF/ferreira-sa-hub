<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== DIAG ticket #7 (Sobre a quebra de sigilo — Juliana) ===\n\n";
$st = $pdo->query("SELECT * FROM tickets WHERE id = 7");
$t = $st->fetch(PDO::FETCH_ASSOC);
if (!$t) { echo "Ticket #7 nao existe. Buscando pelo titulo...\n"; }
if ($t) foreach ($t as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25) . ": $v\n";

echo "\n-- Busca por titulo 'quebra de sigilo' --\n";
$st = $pdo->query("SELECT * FROM tickets WHERE title LIKE '%quebra%sigilo%' OR title LIKE '%Quebra%Sigilo%'");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
    echo str_repeat('-', 60) . "\n";
    foreach ($t as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25) . ": $v\n";
}

echo "\n-- Todos os tickets com category ou origem 'central vip' / 'salavip' --\n";
$st = $pdo->query("SELECT id, title, category, origem, case_id, client_id, requester_id, status, created_at FROM tickets WHERE category LIKE '%central%vip%' OR origem = 'salavip' OR category LIKE '%salavip%' ORDER BY id DESC LIMIT 20");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  #$r[id] category=$r[category] origem=" . ($r['origem']?:'NULL') . " case_id=" . ($r['case_id']?:'NULL') . " client_id=" . ($r['client_id']?:'NULL') . " req_id=" . ($r['requester_id']?:'NULL') . " status=$r[status] em=$r[created_at]\n";
    echo "     title: $r[title]\n";
}

echo "\n-- Colunas da tabela tickets --\n";
$cols = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_COLUMN, 0);
echo implode(', ', $cols) . "\n";
