<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== SCHEMA TABELA case_andamentos ===\n\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM case_andamentos")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo str_pad($c['Field'], 25) . " | " . str_pad($c['Type'], 30) . " | " . ($c['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . " | " . ($c['Default'] ?? '') . "\n";
    }
} catch (Exception $e) { echo "Tabela não existe: " . $e->getMessage() . "\n"; }

echo "\n=== TIPOS distintos já usados ===\n";
try {
    $tipos = $pdo->query("SELECT DISTINCT tipo, COUNT(*) as qt FROM case_andamentos GROUP BY tipo ORDER BY qt DESC LIMIT 30")->fetchAll();
    foreach ($tipos as $t) echo "  [" . ($t['tipo'] ?? 'null') . "] — " . $t['qt'] . " registros\n";
} catch (Exception $e) { echo "Erro: " . $e->getMessage() . "\n"; }

echo "\n=== Exemplo de 3 registros recentes ===\n";
try {
    $rows = $pdo->query("SELECT * FROM case_andamentos ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { print_r($r); echo "---\n"; }
} catch (Exception $e) { echo "Erro: " . $e->getMessage() . "\n"; }
