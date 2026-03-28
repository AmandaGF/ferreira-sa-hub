-- ═══════════════════════════════════════════════════════════
-- Ferreira & Sá Hub — Schema do Banco de Dados
-- Banco: ferre3151357_conecta
-- ═══════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── USUÁRIOS ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','gestao','colaborador') NOT NULL DEFAULT 'colaborador',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `phone` VARCHAR(40) DEFAULT NULL,
    `setor` VARCHAR(60) DEFAULT NULL,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── CRM: CLIENTES ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `clients` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `cpf` VARCHAR(14) DEFAULT NULL,
    `rg` VARCHAR(20) DEFAULT NULL,
    `birth_date` DATE DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `phone` VARCHAR(40) DEFAULT NULL,
    `phone2` VARCHAR(40) DEFAULT NULL,
    `address_street` VARCHAR(255) DEFAULT NULL,
    `address_city` VARCHAR(100) DEFAULT NULL,
    `address_state` VARCHAR(2) DEFAULT NULL,
    `address_zip` VARCHAR(10) DEFAULT NULL,
    `profession` VARCHAR(100) DEFAULT NULL,
    `marital_status` VARCHAR(30) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `source` ENUM('landing','calculadora','indicacao','presencial','outro') DEFAULT 'outro',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_name` (`name`),
    INDEX `idx_cpf` (`cpf`),
    INDEX `idx_phone` (`phone`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── CRM: CASOS / PROCESSOS ────────────────────────────
CREATE TABLE IF NOT EXISTS `cases` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `case_type` ENUM('familia','pensao','divorcio','guarda','convivencia','inventario','responsabilidade_civil','outro') NOT NULL DEFAULT 'outro',
    `case_number` VARCHAR(30) DEFAULT NULL,
    `court` VARCHAR(150) DEFAULT NULL,
    `status` ENUM('aguardando_docs','em_elaboracao','aguardando_prazo','distribuido','em_andamento','concluido','arquivado','suspenso') NOT NULL DEFAULT 'aguardando_docs',
    `priority` ENUM('urgente','alta','normal','baixa') NOT NULL DEFAULT 'normal',
    `responsible_user_id` INT UNSIGNED DEFAULT NULL,
    `drive_folder_url` VARCHAR(500) DEFAULT NULL,
    `deadline` DATE DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `opened_at` DATE DEFAULT NULL,
    `closed_at` DATE DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_client` (`client_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_responsible` (`responsible_user_id`),
    INDEX `idx_deadline` (`deadline`),
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`responsible_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── OPERACIONAL: TAREFAS DO CASO ──────────────────────
CREATE TABLE IF NOT EXISTS `case_tasks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `case_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `status` ENUM('pendente','feito') NOT NULL DEFAULT 'pendente',
    `due_date` DATE DEFAULT NULL,
    `assigned_to` INT UNSIGNED DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_case` (`case_id`),
    FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── CRM: HISTÓRICO DE CONTATOS ────────────────────────
CREATE TABLE IF NOT EXISTS `contacts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT UNSIGNED NOT NULL,
    `case_id` INT UNSIGNED DEFAULT NULL,
    `type` ENUM('whatsapp','telefone','email','presencial','reuniao','nota') NOT NULL DEFAULT 'nota',
    `summary` TEXT NOT NULL,
    `contacted_by` INT UNSIGNED DEFAULT NULL,
    `contacted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_client` (`client_id`),
    INDEX `idx_contacted_at` (`contacted_at`),
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`contacted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PIPELINE: LEADS ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `pipeline_leads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(40) DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `source` ENUM('calculadora','landing','indicacao','instagram','google','whatsapp','outro') NOT NULL DEFAULT 'outro',
    `stage` ENUM('novo','contato_inicial','agendado','proposta','contrato','perdido') NOT NULL DEFAULT 'novo',
    `assigned_to` INT UNSIGNED DEFAULT NULL,
    `case_type` VARCHAR(60) DEFAULT NULL,
    `estimated_value_cents` INT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `lost_reason` VARCHAR(255) DEFAULT NULL,
    `converted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_stage` (`stage`),
    INDEX `idx_assigned` (`assigned_to`),
    INDEX `idx_name` (`name`),
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PIPELINE: HISTÓRICO DE ESTÁGIOS ───────────────────
CREATE TABLE IF NOT EXISTS `pipeline_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT UNSIGNED NOT NULL,
    `from_stage` VARCHAR(30) DEFAULT NULL,
    `to_stage` VARCHAR(30) NOT NULL,
    `changed_by` INT UNSIGNED DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_lead` (`lead_id`),
    FOREIGN KEY (`lead_id`) REFERENCES `pipeline_leads`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── HELPDESK: TICKETS ──────────────────────────────────
CREATE TABLE IF NOT EXISTS `tickets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `category` VARCHAR(60) DEFAULT NULL,
    `department` VARCHAR(60) DEFAULT NULL,
    `priority` ENUM('baixa','normal','urgente') NOT NULL DEFAULT 'normal',
    `status` ENUM('aberto','em_andamento','aguardando','resolvido','cancelado') NOT NULL DEFAULT 'aberto',
    `requester_id` INT UNSIGNED NOT NULL,
    `client_name` VARCHAR(150) DEFAULT NULL,
    `client_contact` VARCHAR(100) DEFAULT NULL,
    `case_number` VARCHAR(30) DEFAULT NULL,
    `due_date` DATE DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_requester` (`requester_id`),
    FOREIGN KEY (`requester_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_assignees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ticket_user` (`ticket_id`, `user_id`),
    FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ticket` (`ticket_id`),
    FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PORTAL: LINKS ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `portal_links` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category` VARCHAR(60) NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `icon` VARCHAR(10) DEFAULT NULL,
    `username` VARCHAR(100) DEFAULT NULL,
    `password_encrypted` VARCHAR(500) DEFAULT NULL,
    `hint` TEXT DEFAULT NULL,
    `audience` ENUM('internal','client','both') NOT NULL DEFAULT 'internal',
    `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── FORMULÁRIOS: SUBMISSÕES ────────────────────────────
CREATE TABLE IF NOT EXISTS `form_submissions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `form_type` VARCHAR(60) NOT NULL,
    `protocol` VARCHAR(20) NOT NULL UNIQUE,
    `client_name` VARCHAR(150) DEFAULT NULL,
    `client_email` VARCHAR(190) DEFAULT NULL,
    `client_phone` VARCHAR(40) DEFAULT NULL,
    `status` ENUM('novo','em_analise','processado','arquivado') NOT NULL DEFAULT 'novo',
    `assigned_to` INT UNSIGNED DEFAULT NULL,
    `linked_client_id` INT UNSIGNED DEFAULT NULL,
    `linked_case_id` INT UNSIGNED DEFAULT NULL,
    `payload_json` LONGTEXT NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_form_type` (`form_type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_client_name` (`client_name`),
    INDEX `idx_protocol` (`protocol`),
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`linked_client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`linked_case_id`) REFERENCES `cases`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── LOG DE AUDITORIA ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(60) NOT NULL,
    `entity_type` VARCHAR(40) DEFAULT NULL,
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════
-- SEED: Usuário admin inicial
-- Senha padrão: Hub@2026 (trocar no primeiro acesso!)
-- ═══════════════════════════════════════════════════════════
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `is_active`, `setor`)
VALUES (
    'Administrador',
    'admin@ferreiraesa.com.br',
    '$2y$10$xN5Q8JxMqPQBzK2VEPvLOeWpGvdKjYXmGTnL8vc6xJpHRWqDmqBGy',
    'admin',
    1,
    'Administração'
) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
-- Hash gerado com: password_hash('Hub@2026', PASSWORD_DEFAULT)
