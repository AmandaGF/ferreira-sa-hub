<?php
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!validate_csrf()) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Token inválido')); exit; }

$pdo = db();
$userId = current_user_id();
$action = $_POST['action'] ?? '';

header('Content-Type: application/json; charset=utf-8');

// Self-heal: cor (post-it) + arquivado (oculta sem apagar)
try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN cor VARCHAR(20) DEFAULT 'amarelo'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN arquivado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

if ($action === 'criar_lembrete') {
    $titulo = trim($_POST['titulo'] ?? '');
    $hora = $_POST['hora_inicio'] ?? null;
    $prioridade = $_POST['prioridade'] ?? 'normal';
    $cor = $_POST['cor'] ?? 'amarelo';
    $coresValidas = array('amarelo','rosa','verde','azul','laranja','roxo');
    if (!in_array($cor, $coresValidas, true)) $cor = 'amarelo';
    if (!$titulo) { echo json_encode(array('error' => 'Título obrigatório')); exit; }
    $pdo->prepare("INSERT INTO eventos_dia (usuario_id, tipo, titulo, data_evento, hora_inicio, prioridade, cor, criado_por) VALUES (?,'lembrete',?,CURDATE(),?,?,?,?)")
        ->execute(array($userId, $titulo, $hora ?: null, $prioridade, $cor, $userId));
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

if ($action === 'arquivar_lembrete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE eventos_dia SET arquivado = 1 WHERE id = ? AND usuario_id = ?")->execute(array($id, $userId));
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'mudar_cor_lembrete') {
    $id = (int)($_POST['id'] ?? 0);
    $cor = $_POST['cor'] ?? 'amarelo';
    $coresValidas = array('amarelo','rosa','verde','azul','laranja','roxo');
    if (!in_array($cor, $coresValidas, true)) $cor = 'amarelo';
    $pdo->prepare("UPDATE eventos_dia SET cor = ? WHERE id = ? AND usuario_id = ?")->execute(array($cor, $id, $userId));
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
