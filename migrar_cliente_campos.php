<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Campos adicionais do cliente ===\n\n";

$queries = array(
    "ALTER TABLE clients ADD COLUMN nacionalidade VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE clients MODIFY COLUMN cpf VARCHAR(18)",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] $q\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "[SKIP] Coluna já existe\n";
        } else {
            echo "[INFO] " . $e->getMessage() . "\n";
        }
    }
}
echo "\n=== FIM ===\n";
