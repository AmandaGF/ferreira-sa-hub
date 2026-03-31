<?php
/**
 * Migração: Colunas da planilha comercial em pipeline_leads
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: Colunas Comerciais ===\n\n";

$queries = array(
    "ALTER TABLE pipeline_leads ADD COLUMN valor_acao VARCHAR(80) DEFAULT NULL COMMENT 'Valor da ação (texto livre: R$ 3.108, Risco, 500+30%)' AFTER estimated_value_cents",
    "ALTER TABLE pipeline_leads ADD COLUMN vencimento_parcela VARCHAR(60) DEFAULT NULL COMMENT 'Vencimento 1ª parcela' AFTER valor_acao",
    "ALTER TABLE pipeline_leads ADD COLUMN forma_pagamento VARCHAR(60) DEFAULT NULL COMMENT 'Boleto, PIX, Cartão, etc' AFTER vencimento_parcela",
    "ALTER TABLE pipeline_leads ADD COLUMN urgencia VARCHAR(40) DEFAULT NULL COMMENT 'Urgência' AFTER forma_pagamento",
    "ALTER TABLE pipeline_leads ADD COLUMN cadastro_asaas VARCHAR(20) DEFAULT NULL COMMENT 'Sim/Não' AFTER urgencia",
    "ALTER TABLE pipeline_leads ADD COLUMN observacoes TEXT DEFAULT NULL COMMENT 'Observações do comercial' AFTER cadastro_asaas",
    "ALTER TABLE pipeline_leads ADD COLUMN nome_pasta VARCHAR(255) DEFAULT NULL COMMENT 'Nome da pasta no Drive' AFTER observacoes",
    "ALTER TABLE pipeline_leads ADD COLUMN pendencias TEXT DEFAULT NULL COMMENT 'Pendências' AFTER nome_pasta",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "OK: " . substr($q, 0, 70) . "...\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "Já existe: " . substr($q, 30, 40) . "\n";
        } else {
            echo "ERRO: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nVerificação:\n";
$cols = $pdo->query("SHOW COLUMNS FROM pipeline_leads")->fetchAll();
foreach ($cols as $c) { echo "  {$c['Field']} ({$c['Type']})\n"; }
echo "\nPronto!\n";
