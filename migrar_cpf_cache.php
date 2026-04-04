<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Cache CPF + Token API ===\n\n";

$queries = array(
    "CREATE TABLE IF NOT EXISTS cpf_cache (
        cpf VARCHAR(11) PRIMARY KEY,
        dados JSON NOT NULL,
        consultado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_consultado (consultado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "INSERT IGNORE INTO configuracoes (chave, valor) VALUES ('cpfcnpj_api_token', '9320d4099cf4099528cce511241c48a0')",
    "INSERT IGNORE INTO configuracoes (chave, valor) VALUES ('cpfcnpj_pacote', '1')",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] " . substr($q, 0, 80) . "...\n";
    } catch (Exception $e) {
        echo "[INFO] " . $e->getMessage() . "\n";
    }
}
echo "\n=== FIM ===\n";
