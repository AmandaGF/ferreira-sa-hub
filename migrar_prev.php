<?php
/**
 * Migração: Kanban PREV — campos previdenciários na tabela cases
 * Rodar UMA vez via navegador ou deploy.
 */

require_once __DIR__ . '/core/config.php';
$pdo = db();

echo "<pre>\n=== Migração Kanban PREV ===\n\n";

// 1. Adicionar colunas PREV na tabela cases
$cols = array(
    "ADD COLUMN kanban_prev TINYINT(1) NOT NULL DEFAULT 0 AFTER kanban_oculto",
    "ADD COLUMN prev_status VARCHAR(50) DEFAULT NULL AFTER kanban_prev",
    "ADD COLUMN prev_enviado_em DATETIME DEFAULT NULL AFTER prev_status",
    "ADD COLUMN prev_mes_envio INT DEFAULT NULL AFTER prev_enviado_em",
    "ADD COLUMN prev_ano_envio INT DEFAULT NULL AFTER prev_mes_envio",
    "ADD COLUMN prev_tipo_beneficio VARCHAR(60) DEFAULT NULL AFTER prev_ano_envio",
    "ADD COLUMN prev_numero_beneficio VARCHAR(30) DEFAULT NULL AFTER prev_tipo_beneficio",
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
            echo "[SKIP] Já existe: $alter\n";
        } else {
            echo "[ERRO] $alter — $msg\n";
        }
    }
}

echo "\n=== Migração PREV concluída! ===\n</pre>";
