<?php
/**
 * Ferreira & Sá Hub — API de Notificações
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$userId = current_user_id();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'read_all':
        $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0')
            ->execute(array($userId));
        flash_set('success', 'Todas as notificações foram marcadas como lidas.');
        break;

    case 'read':
        $notifId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($notifId) {
            $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute(array($notifId, $userId));
        }
        break;

    case 'delete':
        $notifId = (int)($_POST['id'] ?? 0);
        if ($notifId) {
            $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')
                ->execute(array($notifId, $userId));
            flash_set('success', 'Notificação excluída.');
        }
        break;
}

redirect(module_url('notificacoes'));
