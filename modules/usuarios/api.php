<?php
/**
 * Ferreira & Sá Hub — API de Usuários (ações AJAX/POST)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(module_url('usuarios'));
}

if (!validate_csrf()) {
    flash_set('error', 'Token de segurança inválido.');
    redirect(module_url('usuarios'));
}

$action = $_POST['action'] ?? '';
$userId = (int)($_POST['user_id'] ?? 0);
$pdo = db();

switch ($action) {
    case 'toggle_active':
        if ($userId === current_user_id()) {
            flash_set('error', 'Você não pode desativar sua própria conta.');
            break;
        }

        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            flash_set('error', 'Usuário não encontrado.');
            break;
        }

        $newStatus = $user['is_active'] ? 0 : 1;
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$newStatus, $userId]);

        audit_log(
            $newStatus ? 'user_activated' : 'user_deactivated',
            'user',
            $userId
        );

        flash_set('success', $newStatus ? 'Usuário reativado.' : 'Usuário desativado.');
        break;

    case 'reset_password':
        $newPassword = $_POST['new_password'] ?? '';
        if (strlen($newPassword) < 6) {
            flash_set('error', 'Senha deve ter pelo menos 6 caracteres.');
            break;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
        audit_log('password_reset', 'user', $userId, 'Reset por admin');
        flash_set('success', 'Senha redefinida com sucesso.');
        break;

    case 'approve':
        $role = $_POST['role'] ?? 'colaborador';
        $validRoles = array('admin', 'gestao', 'colaborador');
        if (!in_array($role, $validRoles)) $role = 'colaborador';

        if ($userId) {
            $pdo->prepare('UPDATE users SET is_active = 1, role = ? WHERE id = ?')
                ->execute(array($role, $userId));
            audit_log('user_approved', 'user', $userId, "role: $role");
            flash_set('success', 'Usuário aprovado como ' . role_label($role) . '!');
        }
        break;

    case 'reject':
        if ($userId && $userId !== current_user_id()) {
            // Verificar se é pendente (is_active = 0)
            $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
            $stmt->execute(array($userId));
            $u = $stmt->fetch();
            if ($u && !$u['is_active']) {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute(array($userId));
                audit_log('user_rejected', 'user', $userId);
                flash_set('success', 'Solicitação recusada e excluída.');
            }
        }
        break;

    default:
        flash_set('error', 'Ação inválida.');
}

redirect(module_url('usuarios'));
