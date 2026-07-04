<?php
/**
 * Migração: negociação de inadimplentes.
 * Guarda, por cliente, se a proposta de acordo já foi enviada e qual a forma
 * de pagamento acordada. (O valor é editado direto na cobrança do Asaas.)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: inadimplente_nego ===\n\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inadimplente_nego (
        client_id INT NOT NULL PRIMARY KEY,
        proposta_enviada TINYINT(1) NOT NULL DEFAULT 0,
        proposta_em DATETIME NULL,
        forma_acordada VARCHAR(120) NULL,
        obs VARCHAR(255) NULL,
        updated_by INT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK: tabela inadimplente_nego criada (ou já existia)\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
echo "\nPronto!\n";
