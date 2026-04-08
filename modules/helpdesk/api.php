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
            $stmt = $pdo->prepare('SELECT status, title FROM tickets WHERE id = ?');
            $stmt->execute([$ticketId]);
            $t = $stmt->fetch();
            if ($t && $t['status'] === 'aberto') {
                $pdo->prepare('UPDATE tickets SET status="em_andamento", updated_at=NOW() WHERE id=?')
                    ->execute([$ticketId]);
            }

            // ── @Menções: notificar + enviar e-mail ──
            $ticketTitle = $t ? $t['title'] : 'Chamado #' . $ticketId;
            $senderName = current_user()['name'] ?? 'Alguém';

            // Extrair @PrimeiroNome da mensagem
            if (preg_match_all('/@([A-Za-zÀ-ÿ]+)/', $message, $matches)) {
                $mentionedNames = array_unique($matches[1]);
                $usersAll = $pdo->query("SELECT id, name, email FROM users WHERE is_active = 1")->fetchAll();
                $notifiedIds = array();

                foreach ($mentionedNames as $firstName) {
                    foreach ($usersAll as $u) {
                        $uFirst = explode(' ', $u['name'])[0];
                        if (mb_strtolower($uFirst, 'UTF-8') === mb_strtolower($firstName, 'UTF-8') && !in_array((int)$u['id'], $notifiedIds)) {
                            $uid = (int)$u['id'];
                            // Não notificar a si mesmo
                            if ($uid === current_user_id()) continue;

                            $notifiedIds[] = $uid;
                            $ticketUrl = url('modules/helpdesk/ver.php?id=' . $ticketId);

                            // 1. Notificação interna (sino)
                            notify($uid,
                                'Menção no chamado #' . $ticketId,
                                $senderName . ' mencionou você: "' . mb_substr($message, 0, 120, 'UTF-8') . (mb_strlen($message, 'UTF-8') > 120 ? '...' : '') . '"',
                                'info',
                                $ticketUrl,
                                '💬'
                            );

                            // 2. E-mail via Brevo (transactional)
                            if ($u['email']) {
                                helpdesk_enviar_email_mencao($u, $senderName, $ticketId, $ticketTitle, $message, $ticketUrl);
                            }

                            break; // primeiro match por nome é suficiente
                        }
                    }
                }
            }

            audit_log('ticket_message', 'ticket', $ticketId);

            // Feedback com nomes notificados
            if (!empty($notifiedIds)) {
                $notifNames = array();
                foreach ($notifiedIds as $nid) {
                    foreach ($usersAll as $u) {
                        if ((int)$u['id'] === $nid) { $notifNames[] = explode(' ', $u['name'])[0]; break; }
                    }
                }
                flash_set('success', 'Mensagem enviada. 🔔 ' . implode(', ', $notifNames) . ' foi notificado(a) por e-mail e na plataforma.');
            } else {
                flash_set('success', 'Mensagem enviada.');
            }
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    case 'delete_message':
        $msgId = (int)($_POST['message_id'] ?? 0);
        $ticketId = (int)($_POST['ticket_id'] ?? 0);

        if ($msgId) {
            // Verificar se é o autor ou admin
            $stmt = $pdo->prepare('SELECT user_id FROM ticket_messages WHERE id = ?');
            $stmt->execute(array($msgId));
            $msgRow = $stmt->fetch();

            if ($msgRow && ((int)$msgRow['user_id'] === current_user_id() || has_role('admin'))) {
                $pdo->prepare('DELETE FROM ticket_messages WHERE id = ?')->execute(array($msgId));
                audit_log('ticket_message_deleted', 'ticket', $ticketId, 'Msg #' . $msgId);
                flash_set('success', 'Mensagem apagada.');
            } else {
                flash_set('error', 'Sem permissão para apagar esta mensagem.');
            }
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('helpdesk'));
}

// ── Helper: enviar e-mail de menção via Brevo ──
function helpdesk_enviar_email_mencao($user, $senderName, $ticketId, $ticketTitle, $message, $ticketUrl) {
    try {
        $pdo = db();
        $cfg = array('key' => '', 'email' => 'contato@ferreiraesa.com.br', 'name' => 'Ferreira & Sá Advocacia');
        $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'brevo_api_key') $cfg['key'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_name') $cfg['name'] = $r['valor'];
        }
        if (!$cfg['key']) return; // Sem Brevo configurado

        $firstName = explode(' ', $user['name'])[0];
        $msgPreview = mb_substr(strip_tags($message), 0, 300, 'UTF-8');
        $msgPreview = nl2br(htmlspecialchars($msgPreview, ENT_QUOTES, 'UTF-8'));

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:#052228;padding:20px 24px;">
        <h1 style="color:#fff;font-size:16px;margin:0;">💬 Você foi mencionado(a) em um chamado</h1>
    </div>
    <div style="padding:24px;">
        <p style="font-size:14px;color:#374151;margin:0 0 16px;">
            Olá, <strong>' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '</strong>!
        </p>
        <p style="font-size:14px;color:#374151;margin:0 0 16px;">
            <strong>' . htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') . '</strong> mencionou você no chamado <strong>#' . $ticketId . ' — ' . htmlspecialchars($ticketTitle, ENT_QUOTES, 'UTF-8') . '</strong>:
        </p>
        <div style="background:#f0f4ff;border-left:4px solid #3B4FA0;padding:12px 16px;border-radius:0 8px 8px 0;margin:0 0 20px;font-size:14px;color:#1e3a5f;line-height:1.6;">
            ' . $msgPreview . '
        </div>
        <a href="' . htmlspecialchars($ticketUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#052228;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">
            Ver Chamado →
        </a>
    </div>
    <div style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#9ca3af;text-align:center;">
        Ferreira & Sá Advocacia — Conecta Hub
    </div>
</div>
</body></html>';

        $data = array(
            'sender' => array('name' => $cfg['name'], 'email' => $cfg['email']),
            'to' => array(array('email' => $user['email'], 'name' => $user['name'])),
            'subject' => '💬 ' . $senderName . ' mencionou você no chamado #' . $ticketId,
            'htmlContent' => $html,
        );

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        // Silenciar erros de e-mail para não bloquear a mensagem
    }
}
