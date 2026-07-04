<?php
/**
 * Migração: override manual do status Asaas em pipeline_leads.
 * Resolve clientes duplicados — cadastrado no Asaas por um contrato, mas o
 * outro contrato (client_id duplicado) aparecia como "não cadastrado".
 * Valores: '' (automático), 'sim' (força cadastrado), 'nao' (força não cadastrado).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: asaas_manual ===\n\n";

try {
    $pdo->exec("ALTER TABLE pipeline_leads ADD COLUMN asaas_manual VARCHAR(10) DEFAULT NULL COMMENT 'Override manual status Asaas: sim/nao/(NULL=auto) — resolve clientes duplicados' AFTER cadastro_asaas");
    echo "OK: coluna asaas_manual criada\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Já existe: asaas_manual\n";
    } else {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}

echo "\nPronto!\n";
