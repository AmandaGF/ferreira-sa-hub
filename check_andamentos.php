<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Andamentos do case 736 ===\n";
$total = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE case_id = 736")->fetchColumn();
$visiveis = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE case_id = 736 AND visivel_cliente = 1")->fetchColumn();
$ocultos = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE case_id = 736 AND visivel_cliente = 0")->fetchColumn();
$semColuna = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE case_id = 736 AND visivel_cliente IS NULL")->fetchColumn();
echo "Total: $total | Visíveis: $visiveis | Ocultos: $ocultos | NULL: $semColuna\n\n";

echo "=== Últimos 5 andamentos (todos) ===\n";
$rows = $pdo->query("SELECT id, data_andamento, tipo, visivel_cliente, LEFT(descricao,80) as trecho FROM case_andamentos WHERE case_id = 736 ORDER BY data_andamento DESC LIMIT 5")->fetchAll();
foreach ($rows as $r) {
    echo "#" . $r['id'] . " vis=" . ($r['visivel_cliente'] ?? 'NULL') . " " . $r['data_andamento'] . " " . $r['tipo'] . " :: " . $r['trecho'] . "\n";
}

echo "\n=== Default da coluna visivel_cliente ===\n";
$col = $pdo->query("SHOW COLUMNS FROM case_andamentos LIKE 'visivel_cliente'")->fetch();
echo "Type: " . $col['Type'] . " Default: " . ($col['Default'] ?? 'NULL') . " Null: " . $col['Null'] . "\n";
