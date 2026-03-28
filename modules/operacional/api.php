<?php
/**
 * Ferreira & Sá Hub — API Operacional
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('operacional')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('operacional')); }

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'update_status':
        if (!has_min_role('gestao') && !has_role('colaborador')) { break; }
        $caseId = (int)($_POST['case_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $validStatuses = ['aguardando_docs','em_elaboracao','aguardando_prazo','distribuido','em_andamento','concluido','arquivado','suspenso'];

        if ($caseId && in_array($status, $validStatuses)) {
            $closedAt = in_array($status, ['concluido','arquivado']) ? date('Y-m-d') : null;
            $pdo->prepare('UPDATE cases SET status=?, closed_at=?, updated_at=NOW() WHERE id=?')
                ->execute([$status, $closedAt, $caseId]);
            audit_log('case_status', 'case', $caseId, $status);
            flash_set('success', 'Status atualizado.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
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
