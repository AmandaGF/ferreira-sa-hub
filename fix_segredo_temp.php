<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Corrigir andamentos marcados como segredo_justica=1 onde o PROCESSO não é segredo
$stmt = $pdo->query("
    SELECT ca.id, ca.case_id, ca.tipo, ca.segredo_justica, c.segredo_justica as processo_segredo,
           LEFT(ca.descricao, 80) as desc_resumo
    FROM case_andamentos ca
    INNER JOIN cases c ON c.id = ca.case_id
    WHERE ca.segredo_justica = 1 AND (c.segredo_justica = 0 OR c.segredo_justica IS NULL)
");
$rows = $stmt->fetchAll();

echo "Andamentos marcados como segredo indevidamente: " . count($rows) . "\n\n";

foreach ($rows as $r) {
    echo "ID={$r['id']} | Case={$r['case_id']} | Tipo={$r['tipo']} | {$r['desc_resumo']}...\n";
}

if (isset($_GET['fix'])) {
    $fixed = $pdo->exec("
        UPDATE case_andamentos ca
        INNER JOIN cases c ON c.id = ca.case_id
        SET ca.segredo_justica = 0
        WHERE ca.segredo_justica = 1 AND (c.segredo_justica = 0 OR c.segredo_justica IS NULL)
    ");
    echo "\n=== CORRIGIDOS: {$fixed} andamento(s) ===\n";
} else {
    echo "\nAdicione &fix=1 para corrigir.\n";
}
