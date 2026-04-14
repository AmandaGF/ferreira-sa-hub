<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
try {
    $pdo = db();

    // Listar andamentos com segredo_justica=1 que NÃO tinham Confidencial=Sim no CSV
    // (ou seja, foram marcados indevidamente pela detecção automática)
    $stmt = $pdo->query("
        SELECT ca.id, ca.case_id, ca.tipo, ca.segredo_justica, ca.visivel_cliente,
               LEFT(ca.descricao, 80) as desc_resumo, c.title as caso_titulo
        FROM case_andamentos ca
        INNER JOIN cases c ON c.id = ca.case_id
        WHERE ca.segredo_justica = 1
        ORDER BY ca.case_id, ca.data_andamento DESC
    ");
    $rows = $stmt->fetchAll();
    echo "Total andamentos com segredo_justica=1: " . count($rows) . "\n\n";

    foreach ($rows as $r) {
        echo "ID={$r['id']} | Case={$r['case_id']} ({$r['caso_titulo']}) | Tipo={$r['tipo']} | Vis={$r['visivel_cliente']} | {$r['desc_resumo']}...\n";
    }

    if (isset($_GET['fix'])) {
        // Resetar TODOS para segredo=0, visível=1 (quem realmente é confidencial será re-importado)
        $fixed = $pdo->exec("UPDATE case_andamentos SET segredo_justica = 0, visivel_cliente = 1 WHERE segredo_justica = 1");
        echo "\n=== CORRIGIDOS: {$fixed} andamento(s) — segredo_justica=0, visivel_cliente=1 ===\n";
    } else {
        echo "\nAdicione &fix=1 para corrigir todos.\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
