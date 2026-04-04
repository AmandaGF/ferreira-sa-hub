<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Processos Incidentais ===\n\n";

$queries = array(
    "ALTER TABLE cases ADD COLUMN processo_principal_id INT UNSIGNED DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN tipo_relacao VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN is_incidental TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE cases ADD INDEX idx_principal (processo_principal_id)",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] $q\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "[SKIP] Já existe\n";
        } else {
            echo "[INFO] " . $e->getMessage() . "\n";
        }
    }
}
echo "\n=== FIM ===\n";
