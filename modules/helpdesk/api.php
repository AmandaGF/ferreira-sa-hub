<?php
/**
 * Ferreira & Sá Hub — API do Helpdesk
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('helpdesk')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('helpdesk')); }

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'update_status':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $category = clean_str($_POST['category'] ?? '', 60);
        $department = clean_str($_POST['department'] ?? '', 60);
        $dueDate = $_POST['due_date'] ?? null;
        if ($dueDate === '') $dueDate = null;

        $validStatuses = ['aberto','em_andamento','aguardando','resolvido','cancelado'];
        $validPriorities = ['baixa','normal','urgente'];

        if ($ticketId && in_array($status, $validStatuses) && in_array($priority, $validPriorities)) {
            $resolvedAt = ($status === 'resolvido') ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE tickets SET status=?, priority=?, category=?, department=?, due_date=?, resolved_at=COALESCE(?,resolved_at), updated_at=NOW() WHERE id=?')
                ->execute([$status, $priority, $category ?: null, $department ?: null, $dueDate, $resolvedAt, $ticketId]);

            // Atualizar responsáveis
            $newAssignees = $_POST['assignees'] ?? array();
            $pdo->prepare('DELETE FROM ticket_assignees WHERE ticket_id = ?')->execute(array($ticketId));
            if (!empty($newAssignees)) {
                $stmtA = $pdo->prepare('INSERT INTO ticket_assignees (ticket_id, user_id) VALUES (?, ?)');
                foreach ($newAssignees as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) $stmtA->execute(array($ticketId, $uid));
                }
            }

            // Andamento automático no processo vinculado ao resolver/cancelar
            if (in_array($status, array('resolvido', 'cancelado'))) {
                $stmtT = $pdo->prepare('SELECT title, case_id FROM tickets WHERE id = ?');
                $stmtT->execute(array($ticketId));
                $ticketData = $stmtT->fetch();
                if ($ticketData && $ticketData['case_id']) {
                    $descAndamento = ($status === 'resolvido')
                        ? 'CHAMADO INTERNO CONCLUÍDO - Chamado #' . $ticketId . ': ' . $ticketData['title']
                        : 'CHAMADO INTERNO CANCELADO - Chamado #' . $ticketId . ': ' . $ticketData['title'];
                    try {
                        $pdo->prepare(
                            "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, created_at) VALUES (?,?,?,?,?,NOW())"
                        )->execute(array(
                            $ticketData['case_id'],
                            date('Y-m-d'),
                            'chamado',
                            $descAndamento,
                            current_user_id()
                        ));
                    } catch (Exception $e) { /* tabela pode não existir */ }
                }
            }

            audit_log('ticket_updated', 'ticket', $ticketId, "status:$status priority:$priority");
            flash_set('success', 'Chamado atualizado.');
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    case 'update_links':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        if ($ticketId) {
            $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
            $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
            $clientName = clean_str($_POST['client_name'] ?? '', 150);
            $clientContact = clean_str($_POST['client_contact'] ?? '', 100);
            $caseNumber = clean_str($_POST['case_number'] ?? '', 30);

            $pdo->prepare('UPDATE tickets SET client_id=?, case_id=?, client_name=?, client_contact=?, case_number=?, updated_at=NOW() WHERE id=?')
                ->execute(array($clientId, $caseId, $clientName ?: null, $clientContact ?: null, $caseNumber ?: null, $ticketId));

            audit_log('ticket_links_updated', 'ticket', $ticketId);
            flash_set('success', 'Vínculos atualizados.');
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    case 'add_message':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = clean_str($_POST['message'] ?? '', 5000);

        if ($ticketId && $message) {
            $pdo->prepare('INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?,?,?)')
                ->execute([$ticketId, current_user_id(), $message]);

            // Auto mudar status para em_andamento se estava aberto
            $stmt = $pdo->prepare('SELECT status FROM tickets WHERE id = ?');
            $stmt->execute([$ticketId]);
            $t = $stmt->fetch();
            if ($t && $t['status'] === 'aberto') {
                $pdo->prepare('UPDATE tickets SET status="em_andamento", updated_at=NOW() WHERE id=?')
                    ->execute([$ticketId]);
            }

            audit_log('ticket_message', 'ticket', $ticketId);
            flash_set('success', 'Mensagem enviada.');
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('helpdesk'));
}
