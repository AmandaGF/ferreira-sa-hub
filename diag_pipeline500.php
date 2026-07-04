<?php
/** Diag: roda a query principal da planilha do pipeline p/ achar o 500. Apagar depois. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
error_reporting(E_ALL); ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$planilhaWhere = "pl.converted_at IS NOT NULL AND pl.stage NOT IN ('arquivado')";
echo "Rodando query principal da planilha...\n";
try {
    $stmtT = $pdo->prepare(
        "SELECT pl.*, u.name as assigned_name, c.name as client_name,
         c.asaas_customer_id AS asaas_customer_id,
         DATEDIFF(NOW(), pl.created_at) as days_in_pipeline,
         cs.drive_folder_url,
         (SELECT COUNT(*) FROM asaas_cobrancas ac
            WHERE (pl.linked_case_id IS NOT NULL AND (ac.case_id = pl.linked_case_id
                   OR EXISTS(SELECT 1 FROM asaas_cobranca_cases jc WHERE jc.cobranca_id = ac.id AND jc.case_id = pl.linked_case_id)))
               OR (pl.linked_case_id IS NULL AND ac.client_id = c.id)) AS asaas_total_cobrancas,
         (SELECT COUNT(*) FROM asaas_cobrancas ac
            WHERE ((pl.linked_case_id IS NOT NULL AND (ac.case_id = pl.linked_case_id
                    OR EXISTS(SELECT 1 FROM asaas_cobranca_cases jc WHERE jc.cobranca_id = ac.id AND jc.case_id = pl.linked_case_id)))
                OR (pl.linked_case_id IS NULL AND ac.client_id = c.id))
              AND ac.status NOT IN ('CANCELED','REFUNDED','REFUND_REQUESTED','REFUND_IN_PROGRESS')) AS asaas_cobrancas_ativas
         FROM pipeline_leads pl
         LEFT JOIN users u ON u.id = pl.assigned_to
         LEFT JOIN clients c ON c.id = pl.client_id
         LEFT JOIN cases cs ON cs.id = pl.linked_case_id
         WHERE $planilhaWhere
         ORDER BY pl.converted_at DESC"
    );
    $stmtT->execute();
    $rows = $stmtT->fetchAll();
    echo "OK — " . count($rows) . " linhas.\n";
    echo "Colunas exemplo: " . implode(', ', array_keys($rows[0] ?? array())) . "\n";
} catch (Throwable $e) {
    echo ">>> ERRO: " . $e->getMessage() . "\n";
}

// Também: checa se pl.linked_case_id existe como coluna
echo "\nColunas de pipeline_leads relevantes:\n";
foreach ($pdo->query("SHOW COLUMNS FROM pipeline_leads")->fetchAll() as $c) {
    if (in_array($c['Field'], array('linked_case_id','client_id','forma_pagamento','asaas_manual','cadastro_asaas'), true)) {
        echo "  {$c['Field']} ({$c['Type']})\n";
    }
}
