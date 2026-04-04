<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Colunas extras tickets ===\n\n";

$queries = array(
    "ALTER TABLE tickets ADD COLUMN client_id INT UNSIGNED DEFAULT NULL AFTER requester_id",
    "ALTER TABLE tickets ADD COLUMN case_id INT UNSIGNED DEFAULT NULL AFTER client_id",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] $q\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "[SKIP] Coluna já existe\n";
        } else {
            echo "[ERRO] " . $e->getMessage() . "\n";
        }
    }
}
echo "\n=== FIM ===\n";
