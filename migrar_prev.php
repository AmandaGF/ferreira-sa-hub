<?php
/**
 * Migração: Kanban PREV — campos previdenciários na tabela cases
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

// Forçar exibição de erros DEPOIS do config
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "Inicio migracao PREV\n";

try {
    $pdo = db();
    echo "Conexao OK\n\n";

    $cols = array(
        "ALTER TABLE cases ADD COLUMN kanban_prev TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE cases ADD COLUMN prev_status VARCHAR(50) DEFAULT NULL",
        "ALTER TABLE cases ADD COLUMN prev_enviado_em DATETIME DEFAULT NULL",
        "ALTER TABLE cases ADD COLUMN prev_mes_envio INT DEFAULT NULL",
        "ALTER TABLE cases ADD COLUMN prev_ano_envio INT DEFAULT NULL",
        "ALTER TABLE cases ADD COLUMN prev_tipo_beneficio VARCHAR(60) DEFAULT NULL",
        "ALTER TABLE cases ADD COLUMN prev_numero_beneficio VARCHAR(30) DEFAULT NULL",
    );

    foreach ($cols as $sql) {
        try {
            $pdo->exec($sql);
            echo "[OK] $sql\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "[SKIP] ja existe\n";
            } else {
                echo "[ERRO] " . $e->getMessage() . "\n";
            }
        }
    }

    // Indices
    try { $pdo->exec("ALTER TABLE cases ADD INDEX idx_kanban_prev (kanban_prev)"); echo "[OK] idx_kanban_prev\n"; } catch (PDOException $e) { echo "[SKIP] idx_kanban_prev\n"; }
    try { $pdo->exec("ALTER TABLE cases ADD INDEX idx_prev_status (prev_status)"); echo "[OK] idx_prev_status\n"; } catch (PDOException $e) { echo "[SKIP] idx_prev_status\n"; }
    try { $pdo->exec("ALTER TABLE cases ADD INDEX idx_prev_mes_ano (prev_mes_envio, prev_ano_envio)"); echo "[OK] idx_prev_mes_ano\n"; } catch (PDOException $e) { echo "[SKIP] idx_prev_mes_ano\n"; }

    // tipo_vinculo para diferenciar incidental de recurso
    try {
        $pdo->exec("ALTER TABLE cases ADD COLUMN tipo_vinculo VARCHAR(20) DEFAULT NULL");
        echo "[OK] tipo_vinculo\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "[SKIP] tipo_vinculo ja existe\n";
        } else {
            echo "[ERRO] tipo_vinculo -- " . $e->getMessage() . "\n";
        }
    }

    // children_json na tabela clients
    try {
        $pdo->exec("ALTER TABLE clients ADD COLUMN children_json TEXT DEFAULT NULL");
        echo "[OK] children_json em clients\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "[SKIP] children_json ja existe em clients\n";
        } else {
            echo "[ERRO] children_json -- " . $e->getMessage() . "\n";
        }
    }

    echo "\nMigracao concluida!\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
