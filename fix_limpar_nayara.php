<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
try {
    $pdo = db();

    // Buscar caso da Nayara (adoção/destituição)
    $stmt = $pdo->query("SELECT id, title, case_number FROM cases WHERE title LIKE '%Nayara%' OR title LIKE '%NAYARA%'");
    $cases = $stmt->fetchAll();

    echo "Processos encontrados com 'Nayara':\n";
    foreach ($cases as $c) {
        echo "  ID={$c['id']} | {$c['title']} | Nº {$c['case_number']}\n";
    }

    if (isset($_GET['fix']) && isset($_GET['case_id'])) {
        $caseId = (int)$_GET['case_id'];
        $count = $pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE case_id = {$caseId}")->fetchColumn();
        echo "\nAndamentos no case {$caseId}: {$count}\n";
        $deleted = $pdo->exec("DELETE FROM case_andamentos WHERE case_id = {$caseId}");
        echo "=== DELETADOS: {$deleted} andamento(s) do case {$caseId} ===\n";
    } else {
        echo "\nAdicione &fix=1&case_id=XXX para limpar.\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
