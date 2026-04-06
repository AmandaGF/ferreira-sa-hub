<?php
/**
 * Migração: Campos de suspensão expandidos
 * Rodar uma vez: /conecta/migrar_suspensao.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$sqls = array(
    "ALTER TABLE cases ADD COLUMN suspensao_motivo VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN suspensao_tipo VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN suspensao_processo_id INT DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN suspensao_retorno_previsto DATE DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN suspensao_observacao TEXT DEFAULT NULL",
);

foreach ($sqls as $i => $sql) {
    try {
        $pdo->exec($sql);
        echo ($i + 1) . ". OK\n";
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false) {
            echo ($i + 1) . ". JA EXISTE — OK\n";
        } else {
            echo ($i + 1) . ". ERRO: $msg\n";
        }
    }
}

echo "\n=== MIGRACAO SUSPENSAO CONCLUIDA ===\n";
