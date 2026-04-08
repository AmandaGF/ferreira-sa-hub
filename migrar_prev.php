<?php
/**
 * Migração: Kanban PREV — campos previdenciários na tabela cases
 * Rodar UMA vez via navegador ou deploy.
 */

require_once __DIR__ . '/core/config.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== Migração Kanban PREV ===\n\n";

// Adicionar colunas PREV na tabela cases
$cols = array(
    "ADD COLUMN kanban_prev TINYINT(1) NOT NULL DEFAULT 0",
    "ADD COLUMN prev_status VARCHAR(50) DEFAULT NULL",
    "ADD COLUMN prev_enviado_em DATETIME DEFAULT NULL",
    "ADD COLUMN prev_mes_envio INT DEFAULT NULL",
    "ADD COLUMN prev_ano_envio INT DEFAULT NULL",
    "ADD COLUMN prev_tipo_beneficio VARCHAR(60) DEFAULT NULL",
    "ADD COLUMN prev_numero_beneficio VARCHAR(30) DEFAULT NULL",
    "ADD INDEX idx_kanban_prev (kanban_prev)",
    "ADD INDEX idx_prev_status (prev_status)",
    "ADD INDEX idx_prev_mes_ano (prev_mes_envio, prev_ano_envio)",
);

foreach ($cols as $alter) {
    try {
        $pdo->exec("ALTER TABLE cases $alter");
        echo "[OK] $alter\n";
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate') !== false || strpos($msg, 'duplicate') !== false) {
            echo "[SKIP] Ja existe: $alter\n";
        } else {
            echo "[ERRO] $alter -- $msg\n";
        }
    }
}

echo "\n=== Migracao PREV concluida! ===\n";
