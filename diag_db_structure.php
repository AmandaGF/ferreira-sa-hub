<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== SHOW TABLES ===\n";
$all = array();
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $all[] = $t;
    echo "  $t\n";
}

$patterns = array('case', 'process', 'client', 'cliente', 'andament', 'movement', 'timeline', 'partes');
$relevant = array();
foreach ($all as $t) {
    foreach ($patterns as $p) {
        if (stripos($t, $p) !== false) { $relevant[] = $t; break; }
    }
}

echo "\n\n=== TABELAS RELEVANTES (cases/processos/clientes/andamentos) ===\n";
foreach (array_unique($relevant) as $t) {
    echo "\n\n────────────────────────────────────────────────────\n";
    echo "SHOW CREATE TABLE `$t`\n";
    echo "────────────────────────────────────────────────────\n";
    try {
        $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        if ($row) echo $row['Create Table'] . "\n";
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}
