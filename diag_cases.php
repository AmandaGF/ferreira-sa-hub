<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAGNOSTICO TABELA CASES ===\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Total ativos
$r = $pdo->query("SELECT COUNT(*) as total FROM cases WHERE status NOT IN ('arquivado', 'cancelado')")->fetch();
echo "1. Total de casos ativos: {$r['total']}\n\n";

// 2. Com numero de processo
$r = $pdo->query("SELECT COUNT(*) as total FROM cases WHERE status NOT IN ('arquivado', 'cancelado') AND case_number IS NOT NULL AND case_number != ''")->fetch();
echo "2. Com numero de processo: {$r['total']}\n\n";

// 3. Sem numero de processo
$r = $pdo->query("SELECT COUNT(*) as total FROM cases WHERE status NOT IN ('arquivado', 'cancelado') AND (case_number IS NULL OR case_number = '')")->fetch();
echo "3. Sem numero de processo: {$r['total']}\n\n";

// 4. Formato CNJ
$r = $pdo->query("SELECT COUNT(*) as total FROM cases WHERE status NOT IN ('arquivado', 'cancelado') AND case_number REGEXP '^[0-9]{7}-[0-9]{2}\\.[0-9]{4}\\.[0-9]\\.[0-9]{2}\\.[0-9]{4}$'")->fetch();
echo "4. No formato CNJ correto: {$r['total']}\n\n";

// 5. Amostra de 10
echo "5. Amostra de numeros cadastrados:\n";
$rows = $pdo->query("SELECT id, case_number, title FROM cases WHERE case_number IS NOT NULL AND case_number != '' LIMIT 10")->fetchAll();
foreach ($rows as $row) {
    echo "   #{$row['id']} | {$row['case_number']} | {$row['title']}\n";
}

// Extra: distribuicao por status
echo "\n6. Distribuicao por status (ativos):\n";
$rows = $pdo->query("SELECT status, COUNT(*) as total FROM cases WHERE status NOT IN ('arquivado', 'cancelado') GROUP BY status ORDER BY total DESC")->fetchAll();
foreach ($rows as $row) {
    echo "   {$row['status']}: {$row['total']}\n";
}

echo "\n=== FIM ===\n";
