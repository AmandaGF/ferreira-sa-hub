<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Debug case #685 (Cleide Mendes) ===\n\n";

echo "--- Prazos ---\n";
$r = $pdo->query("SELECT id, descricao_acao, prazo_fatal, concluido FROM prazos_processuais WHERE case_id=685")->fetchAll();
foreach ($r as $x) echo "#" . $x['id'] . " concluido=" . $x['concluido'] . " data=" . $x['prazo_fatal'] . " :: " . $x['descricao_acao'] . "\n";

echo "\n--- Tarefas ---\n";
$r = $pdo->query("SELECT id, title, status, due_date FROM case_tasks WHERE case_id=685")->fetchAll();
foreach ($r as $x) echo "#" . $x['id'] . " status=" . $x['status'] . " due=" . $x['due_date'] . " :: " . $x['title'] . "\n";

echo "\n--- Agenda ---\n";
$r = $pdo->query("SELECT id, titulo, tipo, status, data_inicio FROM agenda_eventos WHERE case_id=685")->fetchAll();
foreach ($r as $x) echo "#" . $x['id'] . " status=" . $x['status'] . " tipo=" . $x['tipo'] . " data=" . $x['data_inicio'] . " :: " . $x['titulo'] . "\n";

echo "\n=== Corrigindo ===\n";
// Concluir tarefas de prazo que estão ativas
$pdo->exec("UPDATE case_tasks SET status='concluido', completed_at=NOW() WHERE case_id=685 AND title LIKE '%PRAZO:%' AND status != 'concluido'");
echo "Tarefas atualizadas: " . $pdo->query("SELECT ROW_COUNT()")->fetchColumn() . "\n";

// Marcar eventos de prazo como realizados
$pdo->exec("UPDATE agenda_eventos SET status='realizado', updated_at=NOW() WHERE case_id=685 AND tipo='prazo' AND status NOT IN ('cancelado','realizado')");
echo "Eventos atualizados: " . $pdo->query("SELECT ROW_COUNT()")->fetchColumn() . "\n";

echo "\n=== FIM ===\n";
