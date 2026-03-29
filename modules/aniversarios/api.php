<?php
require_once __DIR__ . '/../../core/middleware.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('aniversarios')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('aniversarios')); }

$action = isset($_POST['action']) ? $_POST['action'] : '';
$pdo = db();

if ($action === 'update_message') {
    $month = (int)($_POST['month'] ?? 0);
    $body = isset($_POST['body']) ? trim($_POST['body']) : '';
    if ($month >= 1 && $month <= 12 && $body) {
        $pdo->prepare("UPDATE birthday_messages SET body = ?, updated_by = ? WHERE month = ?")
            ->execute(array($body, current_user_id(), $month));
        flash_set('success', 'Mensagem atualizada!');
    }
    redirect(module_url('aniversarios', '?mes=' . $month));
}

redirect(module_url('aniversarios'));
