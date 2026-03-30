<?php
/**
 * ══════════════════════════════════════════════════════════
 * FERREIRA & SÁ HUB — INSTALADOR AUTOMÁTICO
 * ══════════════════════════════════════════════════════════
 *
 * Este arquivo configura o banco de dados e cria o primeiro
 * usuário admin. APAGUE ESTE ARQUIVO após a instalação!
 */

// Impedir cache
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: text/html; charset=UTF-8');

$step    = (int)($_POST['step'] ?? ($_GET['step'] ?? 1));
$message = '';
$error   = '';
$success = false;

// ─── PASSO 2: Tentar conectar e criar tabelas ──────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $adminName  = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_pass'] ?? '';

    // Validar
    if (empty($dbName) || empty($dbUser)) {
        $error = 'Preencha o nome do banco e o usuário.';
        $step = 1;
    } elseif (empty($adminName) || empty($adminEmail) || empty($adminPass)) {
        $error = 'Preencha todos os dados do administrador.';
        $step = 1;
    } elseif (strlen($adminPass) < 6) {
        $error = 'A senha deve ter pelo menos 6 caracteres.';
        $step = 1;
    } else {
        try {
            // Conectar ao banco
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Criar todas as tabelas
            $pdo->exec("SET NAMES utf8mb4");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                    INDEX `idx_phone` (`phone`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `pipeline_leads` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `client_id` INT UNSIGNED DEFAULT NULL,
                    `linked_case_id` INT UNSIGNED DEFAULT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `phone` VARCHAR(40) DEFAULT NULL,
                    `email` VARCHAR(190) DEFAULT NULL,
                    `source` ENUM('calculadora','landing','indicacao','instagram','google','whatsapp','outro') NOT NULL DEFAULT 'outro',
                    `stage` ENUM('novo','contato_inicial','agendado','proposta','elaboracao','contrato','preparacao_pasta','pasta_apta','finalizado','perdido') NOT NULL DEFAULT 'novo',
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `ticket_assignees` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `ticket_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_ticket_user` (`ticket_id`, `user_id`),
                    FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `ticket_messages` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `ticket_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `message` TEXT NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_ticket` (`ticket_id`),
                    FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
                    FOREIGN KEY (`linked_client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
                    FOREIGN KEY (`linked_case_id`) REFERENCES `cases`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Notificações
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `notifications` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT UNSIGNED NOT NULL,
                    `type` VARCHAR(30) NOT NULL DEFAULT 'info',
                    `title` VARCHAR(200) NOT NULL,
                    `message` TEXT DEFAULT NULL,
                    `link` VARCHAR(500) DEFAULT NULL,
                    `icon` VARCHAR(10) DEFAULT NULL,
                    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_user` (`user_id`),
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Aniversários - registro de parabéns
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `birthday_greetings` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `client_id` INT UNSIGNED NOT NULL,
                    `year` SMALLINT NOT NULL,
                    `sent_by` INT UNSIGNED DEFAULT NULL,
                    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_client_year` (`client_id`, `year`),
                    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`sent_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Aniversários - mensagens por mês
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `birthday_messages` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `month` TINYINT NOT NULL COMMENT '1-12',
                    `title` VARCHAR(100) NOT NULL,
                    `body` TEXT NOT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `updated_by` INT UNSIGNED DEFAULT NULL,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_month` (`month`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Mensagens prontas
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `message_templates` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `category` VARCHAR(60) NOT NULL,
                    `title` VARCHAR(150) NOT NULL,
                    `body` TEXT NOT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_by` INT UNSIGNED DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_category` (`category`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Histórico de documentos gerados
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `document_history` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `client_id` INT UNSIGNED NOT NULL,
                    `doc_type` VARCHAR(40) NOT NULL,
                    `doc_label` VARCHAR(150) NOT NULL,
                    `tipo_acao` VARCHAR(60) DEFAULT NULL,
                    `generated_by` INT UNSIGNED DEFAULT NULL,
                    `params_json` TEXT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_client` (`client_id`),
                    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`generated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            // Criar usuário admin
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, role, is_active, setor)
                 VALUES (?, ?, ?, 'admin', 1, 'Administração')
                 ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash)"
            );
            $stmt->execute([$adminName, $adminEmail, $hash]);

            // Atualizar config.php com as credenciais corretas
            $configFile = __DIR__ . '/core/config.php';
            if (is_writable($configFile)) {
                $config = file_get_contents($configFile);
                $config = str_replace("'localhost'",           "'" . addslashes($dbHost) . "'", $config);
                $config = str_replace("'ferre3151357_conecta'", "'" . addslashes($dbName) . "'", $config);
                $config = str_replace("'ferre3151357_conecta_user'", "'" . addslashes($dbUser) . "'", $config);
                $config = str_replace("'ALTERAR_SENHA_AQUI'",  "'" . addslashes($dbPass) . "'", $config);

                // Gerar chave de criptografia
                $encryptKey = bin2hex(random_bytes(32));
                $config = str_replace("'ALTERAR_CHAVE_AQUI'", "'" . $encryptKey . "'", $config);

                file_put_contents($configFile, $config);
            }

            $success = true;
            $step = 3;

        } catch (PDOException $e) {
            $error = 'Erro ao conectar no banco: ' . $e->getMessage();
            $step = 1;
        } catch (Exception $e) {
            $error = 'Erro: ' . $e->getMessage();
            $step = 1;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador — Ferreira &amp; Sá Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Open Sans', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #052228 0%, #0b2f36 40%, #173d46 100%);
            padding: 1rem;
        }

        .installer {
            width: 100%;
            max-width: 520px;
        }

        .brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .brand-logo {
            width: 60px; height: 60px;
            background: #d7ab90; border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.5rem; font-weight: 800; color: #052228;
            margin-bottom: .75rem;
        }

        .brand h1 { font-size: 1.5rem; color: #fff; }
        .brand p { color: #d7ab90; font-size: .82rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-top: .25rem; }

        .card {
            background: #fff; border-radius: 24px;
            padding: 2rem; box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }

        .card h2 { font-size: 1.1rem; color: #052228; margin-bottom: .25rem; }
        .card .subtitle { font-size: .85rem; color: #6b7280; margin-bottom: 1.5rem; }

        .section-title {
            font-size: .75rem; font-weight: 700; color: #d7ab90;
            text-transform: uppercase; letter-spacing: 1px;
            margin: 1.5rem 0 .75rem; padding-top: .75rem;
            border-top: 1px solid #e5e7eb;
        }

        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: .82rem; font-weight: 600; color: #052228; margin-bottom: .3rem; }
        .form-hint { font-size: .72rem; color: #9ca3af; margin-top: .2rem; }

        .form-input {
            width: 100%; padding: .7rem 1rem;
            font-family: inherit; font-size: .88rem;
            color: #0f1c20; background: #f9fafb;
            border: 1.5px solid #e5e7eb; border-radius: 12px;
            outline: none; transition: .2s ease;
        }

        .form-input:focus { border-color: #d7ab90; box-shadow: 0 0 0 3px rgba(215,171,144,.2); background: #fff; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }

        .btn {
            width: 100%; padding: .85rem; margin-top: 1rem;
            font-family: inherit; font-size: .95rem; font-weight: 700;
            color: #fff; background: linear-gradient(135deg, #052228, #173d46);
            border: none; border-radius: 12px; cursor: pointer; transition: .2s ease;
        }

        .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(5,34,40,.4); }

        .error {
            background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
            border-radius: 12px; padding: .7rem 1rem; font-size: .85rem;
            margin-bottom: 1rem;
        }

        .success-box {
            text-align: center; padding: 1rem 0;
        }

        .success-box .check {
            width: 64px; height: 64px; background: #ecfdf5;
            border-radius: 50%; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 2rem; margin-bottom: 1rem;
        }

        .success-box h2 { color: #059669; margin-bottom: .5rem; }
        .success-box p { color: #6b7280; font-size: .88rem; margin-bottom: .5rem; }

        .info-box {
            background: #f0f9ff; border: 1px solid #bae6fd;
            border-radius: 12px; padding: 1rem; margin: 1rem 0;
            font-size: .85rem; color: #0284c7; text-align: left;
        }

        .info-box strong { display: block; margin-bottom: .25rem; }

        .btn-success {
            background: linear-gradient(135deg, #059669, #047857);
        }

        .warning {
            background: #fffbeb; border: 1px solid #fde68a;
            border-radius: 12px; padding: .7rem 1rem;
            font-size: .82rem; color: #d97706; margin-top: 1rem;
        }

        .steps {
            display: flex; justify-content: center; gap: .5rem;
            margin-bottom: 1.5rem;
        }

        .step {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .8rem; font-weight: 700;
            background: rgba(255,255,255,.1); color: rgba(255,255,255,.4);
        }

        .step.active { background: #d7ab90; color: #052228; }
        .step.done { background: #059669; color: #fff; }

        @media (max-width: 480px) {
            .card { padding: 1.5rem; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="installer">
        <div class="brand">
            <div class="brand-logo">F&S</div>
            <h1>Ferreira &amp; Sá Hub</h1>
            <p>Instalador</p>
        </div>

        <div class="steps">
            <div class="step <?= $step >= 2 ? 'done' : ($step === 1 ? 'active' : '') ?>">1</div>
            <div class="step <?= $step >= 3 ? 'done' : ($step === 2 ? 'active' : '') ?>">2</div>
            <div class="step <?= $step === 3 ? 'active' : '' ?>">3</div>
        </div>

        <div class="card">
            <?php if ($step === 1): ?>
                <h2>Configurar o Sistema</h2>
                <p class="subtitle">Preencha os dados do banco de dados e do administrador</p>

                <?php if ($error): ?>
                    <div class="error">✕ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="step" value="2">

                    <div class="section-title">Banco de Dados</div>

                    <div class="form-group">
                        <label class="form-label">Servidor</label>
                        <input type="text" name="db_host" class="form-input"
                               value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
                        <p class="form-hint">Normalmente é "localhost"</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nome do banco *</label>
                            <input type="text" name="db_name" class="form-input" required
                                   value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>"
                                   placeholder="ferre3151357_conecta">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Usuário do banco *</label>
                            <input type="text" name="db_user" class="form-input" required
                                   value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>"
                                   placeholder="ferre3151357_user">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Senha do banco</label>
                        <input type="password" name="db_pass" class="form-input"
                               value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                    </div>

                    <div class="section-title">Administrador</div>

                    <div class="form-group">
                        <label class="form-label">Seu nome *</label>
                        <input type="text" name="admin_name" class="form-input" required
                               value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>"
                               placeholder="Amanda Ferreira">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Seu e-mail *</label>
                            <input type="email" name="admin_email" class="form-input" required
                                   value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>"
                                   placeholder="seu@ferreiraesa.com.br">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Sua senha *</label>
                            <input type="password" name="admin_pass" class="form-input" required
                                   placeholder="Mínimo 6 caracteres">
                        </div>
                    </div>

                    <button type="submit" class="btn">Instalar Sistema</button>

                    <div class="warning">
                        ⚠️ Antes de clicar, você precisa ter criado o banco de dados e o usuário no cPanel do TurboCloud.
                    </div>
                </form>

            <?php elseif ($step === 3 && $success): ?>
                <div class="success-box">
                    <div class="check">✓</div>
                    <h2>Instalação concluída!</h2>
                    <p>O Ferreira &amp; Sá Hub foi instalado com sucesso.</p>
                    <p>14 tabelas criadas no banco de dados.</p>

                    <div class="info-box">
                        <strong>Seus dados de acesso:</strong>
                        E-mail: <?= htmlspecialchars($adminEmail) ?><br>
                        Senha: a que você definiu acima
                    </div>

                    <a href="auth/login.php" class="btn btn-success" style="display:inline-block;text-decoration:none;text-align:center;">
                        Acessar o Sistema →
                    </a>

                    <div class="warning">
                        ⚠️ <strong>IMPORTANTE:</strong> Apague este arquivo (instalar.php) do servidor por segurança!
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
