<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migrar: asaas_cobrancas.case_id ===\n\n";
$cols = $pdo->query("SHOW COLUMNS FROM asaas_cobrancas")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('case_id', $cols, true)) {
    $pdo->exec("ALTER TABLE asaas_cobrancas ADD COLUMN case_id INT UNSIGNED NULL AFTER client_id, ADD KEY idx_case (case_id)");
    echo "✓ Coluna case_id criada (NULL = histórico sem processo vinculado)\n";
} else {
    echo "= Coluna já existe\n";
}
echo "\n=== FIM ===\n";
