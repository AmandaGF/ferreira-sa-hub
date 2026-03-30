<?php
/**
 * Migração: Kanban v2 — novos estágios + tabela documentos_pendentes
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// 1. Atualizar ENUM do Pipeline com novos estágios
try {
    $pdo->exec("ALTER TABLE `pipeline_leads` MODIFY `stage` ENUM(
        'cadastro_preenchido','elaboracao_docs','link_enviados','contrato_assinado',
        'agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta',
        'finalizado','perdido',
        'novo','contato_inicial','agendado','proposta','elaboracao','contrato','preparacao_pasta'
    ) NOT NULL DEFAULT 'cadastro_preenchido'");
    echo "1. ENUM pipeline_leads.stage atualizado!\n";
} catch (Exception $e) { echo "1. ENUM: " . $e->getMessage() . "\n"; }

// 2. Migrar dados antigos para novos nomes de estágio
$migrations = array(
    array('elaboracao', 'cadastro_preenchido'),
    array('contrato', 'contrato_assinado'),
    array('preparacao_pasta', 'contrato_assinado'),
    array('novo', 'cadastro_preenchido'),
    array('contato_inicial', 'elaboracao_docs'),
    array('agendado', 'elaboracao_docs'),
    array('proposta', 'elaboracao_docs'),
);
foreach ($migrations as $m) {
    try {
        $stmt = $pdo->prepare("UPDATE pipeline_leads SET stage = ? WHERE stage = ?");
        $stmt->execute(array($m[1], $m[0]));
        $count = $stmt->rowCount();
        if ($count > 0) echo "   Migrado $count leads de '{$m[0]}' para '{$m[1]}'\n";
    } catch (Exception $e) {}
}

// 3. Adicionar coluna doc_faltante_motivo ao pipeline_leads
try {
    $pdo->exec("ALTER TABLE `pipeline_leads` ADD COLUMN `doc_faltante_motivo` TEXT DEFAULT NULL AFTER `lost_reason`");
    echo "2. Coluna doc_faltante_motivo adicionada!\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "2. doc_faltante_motivo já existe.\n";
    else echo "2. " . $e->getMessage() . "\n";
}

// 4. Adicionar coluna stage_antes_doc_faltante
try {
    $pdo->exec("ALTER TABLE `pipeline_leads` ADD COLUMN `stage_antes_doc_faltante` VARCHAR(40) DEFAULT NULL AFTER `doc_faltante_motivo`");
    echo "3. Coluna stage_antes_doc_faltante adicionada!\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "3. stage_antes_doc_faltante já existe.\n";
    else echo "3. " . $e->getMessage() . "\n";
}

// 5. Atualizar status do Operacional (cases) para incluir novos
// Já usa VARCHAR, não precisa alterar ENUM
// Mas adicionar coluna stage_antes_doc_faltante no cases
try {
    $pdo->exec("ALTER TABLE `cases` ADD COLUMN `stage_antes_doc_faltante` VARCHAR(40) DEFAULT NULL");
    echo "4. cases.stage_antes_doc_faltante adicionada!\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "4. Já existe.\n";
    else echo "4. " . $e->getMessage() . "\n";
}

// 6. Tabela documentos_pendentes
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `documentos_pendentes` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT UNSIGNED NOT NULL,
        `case_id` INT UNSIGNED DEFAULT NULL,
        `lead_id` INT UNSIGNED DEFAULT NULL,
        `descricao` VARCHAR(300) NOT NULL,
        `solicitado_por` INT UNSIGNED DEFAULT NULL,
        `recebido_por` INT UNSIGNED DEFAULT NULL,
        `status` ENUM('pendente','recebido') NOT NULL DEFAULT 'pendente',
        `solicitado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `recebido_em` DATETIME DEFAULT NULL,
        INDEX `idx_client` (`client_id`),
        INDEX `idx_status` (`status`),
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "5. Tabela documentos_pendentes criada!\n";
} catch (Exception $e) { echo "5. " . $e->getMessage() . "\n"; }

// 7. Adicionar coluna case_number e court no cases (se não existir)
try {
    $pdo->exec("ALTER TABLE `cases` ADD COLUMN `court` VARCHAR(150) DEFAULT NULL AFTER `case_number`");
    echo "6. cases.court adicionada!\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "6. court já existe.\n";
}

try {
    $pdo->exec("ALTER TABLE `cases` ADD COLUMN `distribution_date` DATE DEFAULT NULL AFTER `court`");
    echo "7. cases.distribution_date adicionada!\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "7. distribution_date já existe.\n";
}

echo "\nPronto!\n";
