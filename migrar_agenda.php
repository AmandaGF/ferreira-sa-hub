<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: Módulo Agenda ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS agenda_eventos (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        titulo            VARCHAR(200) NOT NULL,
        tipo              VARCHAR(30) NOT NULL DEFAULT 'reuniao_cliente',
        modalidade        VARCHAR(20) DEFAULT 'nao_aplicavel',
        data_inicio       DATETIME NOT NULL,
        data_fim          DATETIME,
        dia_todo          TINYINT(1) DEFAULT 0,
        local             VARCHAR(250),
        meet_link         VARCHAR(500),
        descricao         TEXT,
        client_id         INT,
        case_id           INT,
        prazo_id          INT,
        responsavel_id    INT NOT NULL,
        participantes     TEXT,
        google_event_id   VARCHAR(200),
        google_calendar_id VARCHAR(200),
        lembrete_email    TINYINT(1) DEFAULT 1,
        lembrete_whatsapp TINYINT(1) DEFAULT 1,
        lembrete_portal   TINYINT(1) DEFAULT 1,
        lembrete_cliente  TINYINT(1) DEFAULT 1,
        msg_cliente       TEXT,
        lembrete_1d_enviado TINYINT(1) DEFAULT 0,
        lembrete_2h_enviado TINYINT(1) DEFAULT 0,
        status            VARCHAR(20) DEFAULT 'agendado',
        created_by        INT,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_data (data_inicio),
        INDEX idx_responsavel (responsavel_id),
        INDEX idx_client (client_id),
        INDEX idx_case (case_id),
        INDEX idx_status (status),
        INDEX idx_google (google_event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK: tabela agenda_eventos criada\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// Verificar
$stmt = $pdo->query("SHOW COLUMNS FROM agenda_eventos");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "\nColunas: " . implode(', ', $cols) . "\n";
echo "\n=== FIM ===\n";
