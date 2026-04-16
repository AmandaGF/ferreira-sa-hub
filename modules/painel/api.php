<?php
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!validate_csrf()) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Token inválido')); exit; }

$pdo = db();
$userId = current_user_id();
$action = $_POST['action'] ?? '';

header('Content-Type: application/json; charset=utf-8');

if ($action === 'criar_lembrete') {
    $titulo = trim($_POST['titulo'] ?? '');
    $hora = $_POST['hora_inicio'] ?? null;
    $prioridade = $_POST['prioridade'] ?? 'normal';
    if (!$titulo) { echo json_encode(array('error' => 'Título obrigatório')); exit; }
    $pdo->prepare("INSERT INTO eventos_dia (usuario_id, tipo, titulo, data_evento, hora_inicio, prioridade, criado_por) VALUES (?,'lembrete',?,CURDATE(),?,?,?)")
        ->execute(array($userId, $titulo, $hora ?: null, $prioridade, $userId));
    flash_set('success', 'Lembrete criado!');
    echo json_encode(array('ok' => true));
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) redirect(module_url('painel'));
    exit;
}

if ($action === 'toggle_lembrete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE eventos_dia SET concluido = NOT concluido WHERE id = ? AND usuario_id = ?")->execute(array($id, $userId));
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'excluir_lembrete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM eventos_dia WHERE id = ? AND usuario_id = ?")->execute(array($id, $userId));
    echo json_encode(array('ok' => true));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
