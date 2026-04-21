<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== tipo_origem (origem do cadastro) ===\n\n";
foreach ($pdo->query("SELECT tipo_origem, COUNT(*) AS qt FROM case_andamentos GROUP BY tipo_origem ORDER BY qt DESC") as $r) {
    echo sprintf("  %-25s %d\n", '[' . ($r['tipo_origem'] ?? 'NULL') . ']', $r['qt']);
}

echo "\n=== tipo (TODOS os valores já existentes) ===\n\n";
foreach ($pdo->query("SELECT tipo, COUNT(*) AS qt FROM case_andamentos GROUP BY tipo ORDER BY qt DESC") as $r) {
    echo sprintf("  %-30s %d\n", '[' . ($r['tipo'] ?? 'NULL') . ']', $r['qt']);
}

echo "\n=== Total de registros ===\n";
echo $pdo->query("SELECT COUNT(*) FROM case_andamentos")->fetchColumn() . "\n";
