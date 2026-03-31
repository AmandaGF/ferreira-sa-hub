<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: Campos Comercial v2 ===\n\n";

$queries = array(
    "ALTER TABLE pipeline_leads ADD COLUMN data_agendamento DATE DEFAULT NULL COMMENT 'Data agendamento onboard' AFTER pendencias",
    "ALTER TABLE pipeline_leads ADD COLUMN onboard_realizado TINYINT(1) DEFAULT 0 COMMENT '1=sim 0=nao' AFTER data_agendamento",
    "ALTER TABLE pipeline_leads ADD COLUMN origem_lead VARCHAR(60) DEFAULT NULL COMMENT 'trafego_pago, indicacao, ltv, instagram, whatsapp, etc' AFTER onboard_realizado",
);

foreach ($queries as $q) {
    try { $pdo->exec($q); echo "OK: " . substr($q, 25, 60) . "...\n"; }
    catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) echo "Já existe.\n";
        else echo "ERRO: " . $e->getMessage() . "\n";
    }
}
echo "\nPronto!\n";
