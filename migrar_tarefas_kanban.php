<?php
/**
 * Migração: Kanban de Tarefas — expandir case_tasks
 * Rodar uma vez: ?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Kanban de Tarefas ===\n\n";

$queries = array(
    "ALTER TABLE case_tasks MODIFY COLUMN status VARCHAR(20) DEFAULT 'a_fazer'",
    "ALTER TABLE case_tasks ADD COLUMN prioridade VARCHAR(10) DEFAULT 'normal'",
    "ALTER TABLE case_tasks ADD COLUMN descricao TEXT",
    "ALTER TABLE case_tasks ADD COLUMN tipo VARCHAR(30)",
    "ALTER TABLE case_tasks ADD COLUMN tipo_outro VARCHAR(100)",
    "ALTER TABLE case_tasks ADD COLUMN subtipo VARCHAR(50)",
    "ALTER TABLE case_tasks ADD COLUMN prazo_alerta DATE",
    "ALTER TABLE case_tasks ADD COLUMN prazo_id INT",
    "ALTER TABLE case_tasks ADD COLUMN agenda_id INT",
    "ALTER TABLE case_tasks ADD COLUMN alerta_enviado TINYINT(1) DEFAULT 0",
    "UPDATE case_tasks SET status = 'a_fazer' WHERE status = 'pendente'",
    "UPDATE case_tasks SET status = 'concluido' WHERE status = 'feito'",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] $q\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "[SKIP] Coluna já existe\n";
        } else {
            echo "[ERRO] " . $e->getMessage() . " — $q\n";
        }
    }
}

echo "\n=== FIM ===\n";
