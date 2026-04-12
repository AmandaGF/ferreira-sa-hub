<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Fix prazos concluídos sem tarefa/evento atualizados ===\n\n";

// Buscar TODOS os prazos concluídos
$prazos = $pdo->query("SELECT pp.id, pp.case_id, pp.descricao_acao, pp.prazo_fatal
    FROM prazos_processuais pp WHERE pp.concluido = 1")->fetchAll();
echo "Prazos concluídos: " . count($prazos) . "\n\n";

$fixedTasks = 0; $fixedEvents = 0;
foreach ($prazos as $pz) {
    $desc = $pz['descricao_acao'] ?: '';
    $caseId = (int)$pz['case_id'];
    if (!$caseId || !$desc) continue;

    // Concluir tarefa vinculada
    $r1 = $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=COALESCE(completed_at,NOW()) WHERE case_id=? AND title LIKE ? AND status != 'concluido'");
    $r1->execute(array($caseId, '%PRAZO: ' . $desc . '%'));
    if ($r1->rowCount() > 0) {
        echo "TASK FIX: case #$caseId — $desc\n";
        $fixedTasks += $r1->rowCount();
    }

    // Marcar evento agenda como realizado
    $r2 = $pdo->prepare("UPDATE agenda_eventos SET status='realizado', updated_at=NOW() WHERE case_id=? AND tipo='prazo' AND titulo LIKE ? AND status NOT IN ('cancelado','realizado')");
    $r2->execute(array($caseId, '%PRAZO:%' . mb_substr($desc, 0, 50, 'UTF-8') . '%'));
    if ($r2->rowCount() > 0) {
        echo "EVENT FIX: case #$caseId — $desc\n";
        $fixedEvents += $r2->rowCount();
    }
}

echo "\nTarefas corrigidas: $fixedTasks\n";
echo "Eventos corrigidos: $fixedEvents\n";
echo "=== FIM ===\n";
