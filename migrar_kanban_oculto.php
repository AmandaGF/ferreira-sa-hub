<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: kanban_oculto ===\n\n";

$queries = array(
    "ALTER TABLE cases ADD COLUMN kanban_oculto TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ocultar do Kanban sem mudar status'",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] $q\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "[SKIP] Coluna já existe\n";
        } else {
            echo "[ERRO] " . $e->getMessage() . "\n";
        }
    }
}

// Reverter processos que foram indevidamente marcados como "arquivado"
// quando o usuário queria apenas ocultar do Kanban
// (Não faz nada automático aqui — precisa de análise manual)

echo "\n=== FIM ===\n";
