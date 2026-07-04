<?php
/**
 * Backfill: vincula cobranças antigas (asaas_cobrancas) ao caso, pra coluna Asaas
 * do Kanban Comercial contar POR DEMANDA em vez de por cliente inteiro.
 *
 * Só resolve o caso NÃO-ambíguo: cliente que tem exatamente 1 caso. Nesses, toda
 * cobrança sem case_id é atribuída àquele único caso. Clientes com 2+ casos ficam
 * como estão (ambíguo) — resolvidos manualmente pelo override ✋ na própria coluna.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Backfill asaas_cobrancas.case_id ===\n\n";

// Diagnóstico antes
$antes = (int)$pdo->query("SELECT COUNT(*) FROM asaas_cobrancas WHERE case_id IS NULL")->fetchColumn();
echo "Cobranças sem case_id (antes): $antes\n";

try {
    $sql = "UPDATE asaas_cobrancas ac
            JOIN (
                SELECT client_id, MIN(id) AS only_case
                FROM cases
                GROUP BY client_id
                HAVING COUNT(*) = 1
            ) sc ON sc.client_id = ac.client_id
            SET ac.case_id = sc.only_case
            WHERE ac.case_id IS NULL";
    $n = $pdo->exec($sql);
    echo "Vinculadas (cliente com 1 caso só): $n\n";
} catch (Exception $e) {
    echo "ERRO no backfill: " . $e->getMessage() . "\n";
}

$depois = (int)$pdo->query("SELECT COUNT(*) FROM asaas_cobrancas WHERE case_id IS NULL")->fetchColumn();
echo "Cobranças ainda sem case_id (ambíguas / cliente multi-caso): $depois\n";

echo "\nPronto! As ambíguas restantes se resolvem sozinhas quando uma nova cobrança\n";
echo "é criada pra elas, ou manualmente pelo override ✋ na coluna Asaas.\n";
