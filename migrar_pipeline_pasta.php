<?php
/**
 * Migração: novos estágios Pipeline + campo linked_case_id
 * Acesse: ferreiraesa.com.br/conecta/migrar_pipeline_pasta.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// 1. Alterar ENUM do stage para incluir novos estágios
try {
    $pdo->exec("ALTER TABLE `pipeline_leads` MODIFY `stage` ENUM('novo','contato_inicial','agendado','proposta','elaboracao','contrato','preparacao_pasta','pasta_apta','finalizado','perdido') NOT NULL DEFAULT 'novo'");
    echo "ENUM stage atualizado com novos estágios!\n";
} catch (Exception $e) { echo "stage ENUM: " . $e->getMessage() . "\n"; }

// 2. Adicionar linked_case_id
try {
    $pdo->exec("ALTER TABLE `pipeline_leads` ADD COLUMN `linked_case_id` INT UNSIGNED DEFAULT NULL AFTER `client_id`");
    echo "Coluna linked_case_id adicionada!\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "linked_case_id já existe.\n";
    } else {
        echo "linked_case_id: " . $e->getMessage() . "\n";
    }
}

echo "\nPronto!\n";
