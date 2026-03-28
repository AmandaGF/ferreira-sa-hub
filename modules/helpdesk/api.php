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

        $validStatuses = ['aberto','em_andamento','aguardando','resolvido','cancelado'];
        $validPriorities = ['baixa','normal','urgente'];

        if ($ticketId && in_array($status, $validStatuses) && in_array($priority, $validPriorities)) {
            $resolvedAt = ($status === 'resolvido') ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE tickets SET status=?, priority=?, resolved_at=COALESCE(?,resolved_at), updated_at=NOW() WHERE id=?')
                ->execute([$status, $priority, $resolvedAt, $ticketId]);
            audit_log('ticket_updated', 'ticket', $ticketId, "status:$status priority:$priority");
            flash_set('success', 'Chamado atualizado.');
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
