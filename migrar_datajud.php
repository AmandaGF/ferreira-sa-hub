<?php
/**
 * Migração: Integração DataJud (CNJ)
 * Rodar uma vez: /conecta/migrar_datajud.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$sqls = array(
    "ALTER TABLE cases ADD COLUMN datajud_sincronizado TINYINT(1) DEFAULT 0",
    "ALTER TABLE cases ADD COLUMN datajud_ultima_sync DATETIME DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN datajud_ultimo_movimento_id VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN datajud_erro VARCHAR(300) DEFAULT NULL",

    "ALTER TABLE case_andamentos ADD COLUMN tipo_origem VARCHAR(20) DEFAULT 'manual'",
    "ALTER TABLE case_andamentos ADD COLUMN datajud_movimento_id VARCHAR(100) DEFAULT NULL",

    "CREATE TABLE IF NOT EXISTS datajud_sync_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id INT UNSIGNED NOT NULL,
        status ENUM('sucesso','erro','segredo','nao_encontrado') NOT NULL,
        movimentos_novos INT DEFAULT 0,
        mensagem VARCHAR(300),
        sincronizado_por INT UNSIGNED DEFAULT NULL,
        tipo ENUM('automatico','manual') DEFAULT 'automatico',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case (case_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
);

foreach ($sqls as $i => $sql) {
    try {
        $pdo->exec($sql);
        echo ($i + 1) . ". OK\n";
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false || strpos($msg, 'already exists') !== false) {
            echo ($i + 1) . ". JA EXISTE — OK\n";
        } else {
            echo ($i + 1) . ". ERRO: $msg\n";
        }
    }
}

echo "\n=== MIGRACAO DATAJUD CONCLUIDA ===\n";
