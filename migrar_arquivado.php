<?php
/**
 * Migração: adicionar campos arquivado_por e arquivado_em em pipeline_leads
 * Rodar uma vez: ?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$queries = array(
    "ALTER TABLE pipeline_leads ADD COLUMN IF NOT EXISTS arquivado_por INT DEFAULT NULL",
    "ALTER TABLE pipeline_leads ADD COLUMN IF NOT EXISTS arquivado_em DATETIME DEFAULT NULL",
);

echo "=== Migração: campos arquivado ===\n\n";
foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] $q\n";
    } catch (Exception $e) {
        // Se "IF NOT EXISTS" não funciona (MySQL < 8), tentar sem
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "[SKIP] Coluna já existe: $q\n";
        } else {
            echo "[ERRO] $q — " . $e->getMessage() . "\n";
        }
    }
}
echo "\n=== FIM ===\n";
