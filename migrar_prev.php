<?php
/**
 * Migração: Kanban PREV — campos previdenciários na tabela cases
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/core/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = db();
    echo "=== Migracao Kanban PREV ===\n\n";

    $cols = array(
        array("kanban_prev", "ALTER TABLE cases ADD COLUMN kanban_prev TINYINT(1) NOT NULL DEFAULT 0"),
        array("prev_status", "ALTER TABLE cases ADD COLUMN prev_status VARCHAR(50) DEFAULT NULL"),
        array("prev_enviado_em", "ALTER TABLE cases ADD COLUMN prev_enviado_em DATETIME DEFAULT NULL"),
        array("prev_mes_envio", "ALTER TABLE cases ADD COLUMN prev_mes_envio INT DEFAULT NULL"),
        array("prev_ano_envio", "ALTER TABLE cases ADD COLUMN prev_ano_envio INT DEFAULT NULL"),
        array("prev_tipo_beneficio", "ALTER TABLE cases ADD COLUMN prev_tipo_beneficio VARCHAR(60) DEFAULT NULL"),
        array("prev_numero_beneficio", "ALTER TABLE cases ADD COLUMN prev_numero_beneficio VARCHAR(30) DEFAULT NULL"),
    );

    foreach ($cols as $c) {
        try {
            $pdo->exec($c[1]);
            echo "[OK] " . $c[0] . "\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "[SKIP] " . $c[0] . " ja existe\n";
            } else {
                echo "[ERRO] " . $c[0] . " -- " . $e->getMessage() . "\n";
            }
        }
    }

    // Indices
    $indices = array(
        array("idx_kanban_prev", "ALTER TABLE cases ADD INDEX idx_kanban_prev (kanban_prev)"),
        array("idx_prev_status", "ALTER TABLE cases ADD INDEX idx_prev_status (prev_status)"),
        array("idx_prev_mes_ano", "ALTER TABLE cases ADD INDEX idx_prev_mes_ano (prev_mes_envio, prev_ano_envio)"),
    );
    foreach ($indices as $idx) {
        try {
            $pdo->exec($idx[1]);
            echo "[OK] indice " . $idx[0] . "\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "[SKIP] indice " . $idx[0] . " ja existe\n";
            } else {
                echo "[ERRO] indice " . $idx[0] . " -- " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n=== Migracao PREV concluida! ===\n";
} catch (Exception $e) {
    echo "ERRO FATAL: " . $e->getMessage() . "\n";
}
