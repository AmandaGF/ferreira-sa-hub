<?php
/**
 * Migração: tabela document_history
 * Acesse: ferreiraesa.com.br/conecta/migrar_documentos.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `document_history` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT UNSIGNED NOT NULL,
        `doc_type` VARCHAR(40) NOT NULL COMMENT 'procuracao, contrato, hipossuficiencia, etc',
        `doc_label` VARCHAR(150) NOT NULL COMMENT 'Nome legível do documento',
        `tipo_acao` VARCHAR(60) DEFAULT NULL,
        `generated_by` INT UNSIGNED DEFAULT NULL,
        `params_json` TEXT DEFAULT NULL COMMENT 'Parâmetros usados na geração',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_client` (`client_id`),
        INDEX `idx_type` (`doc_type`),
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`generated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela 'document_history' criada!\n";
} catch (Exception $e) { echo "document_history: " . $e->getMessage() . "\n"; }

echo "\nPronto!\n";
