<?php
/**
 * Migração v3: Prazos, Ofícios, Alvarás, Parceiros + tipo_especial nos cases
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração v3: Novos módulos ===\n\n";

// 1. Tabela parceiros
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `parceiros` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `nome` VARCHAR(150) NOT NULL,
        `oab` VARCHAR(30) DEFAULT NULL,
        `area` VARCHAR(60) DEFAULT NULL,
        `email` VARCHAR(100) DEFAULT NULL,
        `telefone` VARCHAR(30) DEFAULT NULL,
        `pct_honorarios` DECIMAL(5,2) DEFAULT NULL,
        `observacoes` TEXT DEFAULT NULL,
        `ativo` TINYINT(1) DEFAULT 1,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "1. Tabela parceiros OK\n";
} catch (Exception $e) { echo "1. " . $e->getMessage() . "\n"; }

// 2. Tabela prazos_processuais
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `prazos_processuais` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT UNSIGNED DEFAULT NULL,
        `case_id` INT UNSIGNED DEFAULT NULL,
        `numero_processo` VARCHAR(50) DEFAULT NULL,
        `descricao_acao` VARCHAR(250) NOT NULL,
        `prazo_fatal` DATE NOT NULL,
        `alertado_em` DATETIME DEFAULT NULL,
        `concluido` TINYINT(1) DEFAULT 0,
        `concluido_em` DATETIME DEFAULT NULL,
        `usuario_id` INT UNSIGNED DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_prazo` (`prazo_fatal`),
        INDEX `idx_client` (`client_id`),
        INDEX `idx_concluido` (`concluido`),
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "2. Tabela prazos_processuais OK\n";
} catch (Exception $e) { echo "2. " . $e->getMessage() . "\n"; }

// 3. Tabela oficios_enviados
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `oficios_enviados` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT UNSIGNED DEFAULT NULL,
        `case_id` INT UNSIGNED DEFAULT NULL,
        `numero_processo` VARCHAR(50) DEFAULT NULL,
        `empregador` VARCHAR(250) DEFAULT NULL,
        `data_envio` DATE DEFAULT NULL,
        `retorno_ar` VARCHAR(100) DEFAULT NULL,
        `cod_rastreio` VARCHAR(100) DEFAULT NULL,
        `plataforma` VARCHAR(50) DEFAULT NULL,
        `observacoes` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_client` (`client_id`),
        INDEX `idx_envio` (`data_envio`),
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "3. Tabela oficios_enviados OK\n";
} catch (Exception $e) { echo "3. " . $e->getMessage() . "\n"; }

// 4. Tabela alvaras
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `alvaras` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT UNSIGNED DEFAULT NULL,
        `case_id` INT UNSIGNED DEFAULT NULL,
        `numero_processo` VARCHAR(50) DEFAULT NULL,
        `data_peticionamento` DATE DEFAULT NULL,
        `valor` DECIMAL(12,2) DEFAULT NULL,
        `honorarios_pct` DECIMAL(5,2) DEFAULT NULL,
        `valor_honorarios` DECIMAL(12,2) DEFAULT NULL,
        `repasse_cliente` DECIMAL(12,2) DEFAULT NULL,
        `natureza` VARCHAR(100) DEFAULT NULL,
        `prazo_pagamento` VARCHAR(50) DEFAULT NULL,
        `observacoes` TEXT DEFAULT NULL,
        `estado_uf` VARCHAR(30) DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_client` (`client_id`),
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "4. Tabela alvaras OK\n";
} catch (Exception $e) { echo "4. " . $e->getMessage() . "\n"; }

// 5. Adicionar tipo_especial e parceiro_id nos cases
try {
    $pdo->exec("ALTER TABLE `cases` ADD COLUMN `tipo_especial` VARCHAR(40) DEFAULT 'nenhum' AFTER `case_type`");
    echo "5a. cases.tipo_especial OK\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "5a. tipo_especial já existe\n";
    else echo "5a. " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE `cases` ADD COLUMN `parceiro_id` INT UNSIGNED DEFAULT NULL AFTER `tipo_especial`");
    echo "5b. cases.parceiro_id OK\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "5b. parceiro_id já existe\n";
    else echo "5b. " . $e->getMessage() . "\n";
}

// 6. Adicionar tipo_especial no pipeline_leads
try {
    $pdo->exec("ALTER TABLE `pipeline_leads` ADD COLUMN `tipo_especial` VARCHAR(40) DEFAULT 'nenhum' AFTER `case_type`");
    echo "6. pipeline_leads.tipo_especial OK\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "6. tipo_especial já existe\n";
    else echo "6. " . $e->getMessage() . "\n";
}

echo "\nPronto!\n";
