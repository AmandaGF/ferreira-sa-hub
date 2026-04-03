<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Visibilidade Andamentos ===\n\n";

$queries = array(
    "ALTER TABLE case_andamentos ADD COLUMN visivel_cliente TINYINT(1) DEFAULT 1",
    "ALTER TABLE case_andamentos ADD COLUMN segredo_justica TINYINT(1) DEFAULT 0",
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
