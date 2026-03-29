<?php
/**
 * Ferreira & Sá Hub — API Operacional
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
// Também detectar se veio do fetch/XHR sem header especial
if (!$isAjax && isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $isAjax = true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('operacional')); }
if (!validate_csrf()) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Token inválido')); exit; }
    flash_set('error', 'Token inválido.'); redirect(module_url('operacional'));
}

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'update_status':
        if (!has_min_role('gestao') && !has_role('colaborador')) { break; }
        $caseId = (int)($_POST['case_id'] ?? 0);
        $status = isset($_POST['new_status']) && $_POST['new_status'] ? $_POST['new_status'] : (isset($_POST['status']) ? $_POST['status'] : '');
        $validStatuses = array('aguardando_docs','em_elaboracao','aguardando_prazo','distribuido','em_andamento','concluido','arquivado','suspenso');

        if ($caseId && in_array($status, $validStatuses)) {
            // RB-01: Bloqueio — não avança para Revisão ou Concluído sem checklist completo
            if (in_array($status, array('distribuido', 'concluido'))) {
                $pendentes = $pdo->prepare("SELECT COUNT(*) FROM case_tasks WHERE case_id = ? AND status = 'pendente'");
                $pendentes->execute(array($caseId));
                $numPendentes = (int)$pendentes->fetchColumn();

                $totalTasks = $pdo->prepare("SELECT COUNT(*) FROM case_tasks WHERE case_id = ?");
                $totalTasks->execute(array($caseId));
                $numTotal = (int)$totalTasks->fetchColumn();

                if ($numTotal > 0 && $numPendentes > 0) {
                    $label = $status === 'distribuido' ? 'Revisão' : 'Concluído';
                    $msg = "Não é possível mover para \"$label\": há $numPendentes tarefa(s) pendente(s) no checklist.";
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => $msg)); exit; }
                    flash_set('error', $msg);
                    redirect(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : module_url('operacional'));
                    exit;
                }
            }

            $closedAt = in_array($status, array('concluido','arquivado')) ? date('Y-m-d') : null;
            $pdo->prepare('UPDATE cases SET status=?, closed_at=?, updated_at=NOW() WHERE id=?')
                ->execute(array($status, $closedAt, $caseId));
            audit_log('case_status', 'case', $caseId, $status);

            // Notificar quando pronto para revisão
            if ($status === 'distribuido') {
                notify_gestao('Caso pronto para revisão', 'Caso #' . $caseId . ' está pronto para revisão.', 'pendencia', url('modules/operacional/caso_ver.php?id=' . $caseId), '🔍');
            }

            // ── Auto-finalizar no Pipeline ──
            // Se o caso vai para "Em Execução" e o lead vinculado está em "Pasta Apta", remove do Pipeline
            if ($status === 'em_andamento') {
                $linkedLead = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ?");
                $linkedLead->execute(array($caseId));
                $leadRow = $linkedLead->fetch();
                if ($leadRow && $leadRow['stage'] === 'pasta_apta') {
                    $pdo->prepare("UPDATE pipeline_leads SET stage = 'finalizado', updated_at = NOW() WHERE id = ?")
                        ->execute(array($leadRow['id']));
                    $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                        ->execute(array($leadRow['id'], 'pasta_apta', 'finalizado', current_user_id(), 'Auto: caso entrou em execução no Operacional'));
                    audit_log('lead_auto_finalized', 'lead', $leadRow['id'], 'case_id: ' . $caseId);
                }
            }

            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
            flash_set('success', 'Status atualizado.');
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : module_url('operacional');
        if (strpos($referer, 'caso_ver') !== false) {
            redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        } else {
            redirect(module_url('operacional'));
        }
        break;

    case 'update_case_info':
        if (!has_min_role('gestao')) { break; }
        $caseId = (int)($_POST['case_id'] ?? 0);
        $priority = $_POST['priority'] ?? 'normal';
        $responsibleId = (int)($_POST['responsible_user_id'] ?? 0) ?: null;

        $validPriorities = ['urgente','alta','normal','baixa'];
        if ($caseId && in_array($priority, $validPriorities)) {
            $pdo->prepare('UPDATE cases SET priority=?, responsible_user_id=?, updated_at=NOW() WHERE id=?')
                ->execute([$priority, $responsibleId, $caseId]);
            audit_log('case_updated', 'case', $caseId);
            flash_set('success', 'Caso atualizado.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'add_task':
        $caseId = (int)($_POST['case_id'] ?? 0);
        $title = clean_str($_POST['title'] ?? '', 200);
        $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $dueDate = $_POST['due_date'] ?? null;
        if ($dueDate === '') $dueDate = null;

        if ($caseId && $title) {
            $maxOrder = (int)$pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM case_tasks WHERE case_id=?')
                ->execute([$caseId]) ? $pdo->query('SELECT FOUND_ROWS()')->fetchColumn() : 0;

            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM case_tasks WHERE case_id = ?');
            $stmt->execute([$caseId]);
            $nextOrder = (int)$stmt->fetchColumn();

            $pdo->prepare('INSERT INTO case_tasks (case_id, title, assigned_to, due_date, sort_order) VALUES (?,?,?,?,?)')
                ->execute([$caseId, $title, $assignedTo, $dueDate, $nextOrder]);
            audit_log('task_added', 'case', $caseId, $title);
            flash_set('success', 'Tarefa adicionada.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'toggle_task':
        $taskId = (int)($_POST['task_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);

        if ($taskId) {
            $stmt = $pdo->prepare('SELECT status FROM case_tasks WHERE id = ?');
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();

            if ($task) {
                $newStatus = $task['status'] === 'pendente' ? 'feito' : 'pendente';
                $completedAt = $newStatus === 'feito' ? date('Y-m-d H:i:s') : null;
                $pdo->prepare('UPDATE case_tasks SET status=?, completed_at=? WHERE id=?')
                    ->execute([$newStatus, $completedAt, $taskId]);
                audit_log('task_toggled', 'case_task', $taskId, $newStatus);
            }
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'delete_task':
        $taskId = (int)($_POST['task_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($taskId) {
            $pdo->prepare('DELETE FROM case_tasks WHERE id = ?')->execute([$taskId]);
            flash_set('success', 'Tarefa removida.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('operacional'));
}
