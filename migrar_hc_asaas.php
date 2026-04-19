<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: honorarios_cobranca ↔ Asaas ===\n\n";

$cols = $pdo->query("SHOW COLUMNS FROM honorarios_cobranca")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('asaas_payment_id', $cols, true)) {
    $pdo->exec("ALTER TABLE honorarios_cobranca ADD COLUMN asaas_payment_id VARCHAR(50) DEFAULT NULL AFTER contrato_id");
    $pdo->exec("ALTER TABLE honorarios_cobranca ADD UNIQUE KEY uk_asaas_payment (asaas_payment_id)");
    echo "✓ Coluna asaas_payment_id + índice único criados\n";
} else {
    echo "= Coluna asaas_payment_id já existe\n";
}
echo "\n=== FIM ===\n";
