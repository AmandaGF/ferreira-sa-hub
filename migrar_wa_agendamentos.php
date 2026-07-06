<?php
/**
 * Migração: agendamento pontual de mensagens WhatsApp.
 * Tabela guarda uma msg escrita à mão pra ser enviada num momento específico.
 * Cron cron/wa_agendamentos_tick.php varre pendentes vencidos e envia.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');

$pdo = db();
echo "=== Migrar: agendamentos WhatsApp ===\n\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS wa_agendamentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canal ENUM('21','24') NOT NULL DEFAULT '24',
    client_id INT UNSIGNED NULL,
    case_id INT UNSIGNED NULL,
    telefone VARCHAR(30) NOT NULL,
    nome_contato VARCHAR(150) NULL,
    mensagem TEXT NOT NULL,
    agendado_para DATETIME NOT NULL,
    status ENUM('pendente','enviado','cancelado','falhou') NOT NULL DEFAULT 'pendente',
    enviado_em DATETIME NULL,
    zapi_message_id VARCHAR(100) NULL,
    erro TEXT NULL,
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    criado_por INT UNSIGNED NULL,
    cancelado_por INT UNSIGNED NULL,
    cancelado_em DATETIME NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_data (status, agendado_para),
    INDEX idx_client (client_id),
    INDEX idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "✓ Tabela wa_agendamentos criada/verificada\n";

// Killswitch — default LIGADO (equipe pediu a feature).
$pdo->exec("INSERT IGNORE INTO configuracoes (chave, valor)
            VALUES ('wa_agenda_ativo', '1')");
echo "✓ Killswitch wa_agenda_ativo criado (padrão: 1 = ligado)\n";

echo "\n=== FIM ===\n";
