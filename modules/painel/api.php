<?php
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!validate_csrf()) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Token inválido')); exit; }

$pdo = db();
$userId = current_user_id();
$action = $_POST['action'] ?? '';

header('Content-Type: application/json; charset=utf-8');

// Self-heal: cor (post-it) + arquivado (oculta sem apagar) + vínculos + atribuição
try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN cor VARCHAR(20) DEFAULT 'amarelo'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN arquivado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN client_id INT UNSIGNED NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN case_id INT UNSIGNED NULL"); } catch (Exception $e) {}

$coresValidas = array('amarelo','rosa','verde','azul','laranja','roxo');

if ($action === 'criar_lembrete') {
    $titulo = trim($_POST['titulo'] ?? '');
    $hora = $_POST['hora_inicio'] ?? null;
    $prioridade = $_POST['prioridade'] ?? 'normal';
    $cor = $_POST['cor'] ?? 'amarelo';
    $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
    $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
    // Atribuir pra outro usuário (cria pra ele em vez de pra si mesmo)
    $atribuidoA = (int)($_POST['atribuido_a'] ?? 0);
    $donoId = $atribuidoA > 0 ? $atribuidoA : $userId;
    // Data (default hoje)
    $dataEvento = trim($_POST['data_evento'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataEvento)) $dataEvento = date('Y-m-d');
    if (!in_array($cor, $coresValidas, true)) $cor = 'amarelo';
    if (!$titulo) { echo json_encode(array('error' => 'Título obrigatório')); exit; }

    $pdo->prepare("INSERT INTO eventos_dia (usuario_id, tipo, titulo, data_evento, hora_inicio, prioridade, cor, client_id, case_id, criado_por) VALUES (?,'lembrete',?,?,?,?,?,?,?,?)")
        ->execute(array($donoId, $titulo, $dataEvento, $hora ?: null, $prioridade, $cor, $clientId, $caseId, $userId));
    $lembreteId = (int)$pdo->lastInsertId();

    // Notifica destinatário se atribuído pra outra pessoa
    if ($donoId !== $userId && function_exists('notify')) {
        try {
            $criadorStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $criadorStmt->execute(array($userId));
            $criadorNome = explode(' ', (string)$criadorStmt->fetchColumn())[0] ?: 'colega';
            notify($donoId, 'Novo lembrete de ' . $criadorNome,
                $titulo,
                'info', url('modules/painel/'), '📌');
        } catch (Exception $e) {}
    }

    flash_set('success', 'Lembrete criado!');
    echo json_encode(array('ok' => true, 'id' => $lembreteId));
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) redirect(module_url('painel'));
    exit;
}

if ($action === 'editar_lembrete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'ID inválido')); exit; }
    // Permissão: dono OU criador OU admin
    $chk = $pdo->prepare("SELECT usuario_id, criado_por FROM eventos_dia WHERE id = ? AND tipo = 'lembrete'");
    $chk->execute(array($id));
    $row = $chk->fetch();
    if (!$row) { echo json_encode(array('error' => 'Não encontrado')); exit; }
    $podeEditar = ((int)$row['usuario_id'] === $userId || (int)$row['criado_por'] === $userId || has_min_role('admin'));
    if (!$podeEditar) { echo json_encode(array('error' => 'Sem permissão')); exit; }

    $titulo = trim($_POST['titulo'] ?? '');
    if (!$titulo) { echo json_encode(array('error' => 'Título obrigatório')); exit; }
    $hora = $_POST['hora_inicio'] ?? null;
    $prioridade = $_POST['prioridade'] ?? 'normal';
    $cor = $_POST['cor'] ?? 'amarelo';
    if (!in_array($cor, $coresValidas, true)) $cor = 'amarelo';
    $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
    $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
    $dataEvento = trim($_POST['data_evento'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataEvento)) $dataEvento = date('Y-m-d');

    $pdo->prepare("UPDATE eventos_dia SET titulo=?, hora_inicio=?, prioridade=?, cor=?, client_id=?, case_id=?, data_evento=? WHERE id = ?")
        ->execute(array($titulo, $hora ?: null, $prioridade, $cor, $clientId, $caseId, $dataEvento, $id));
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'desarquivar_lembrete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE eventos_dia SET arquivado = 0 WHERE id = ? AND usuario_id = ?")->execute(array($id, $userId));
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'listar_arquivados') {
    $st = $pdo->prepare("SELECT e.*, c.name AS client_name, cs.title AS case_title
                         FROM eventos_dia e
                         LEFT JOIN clients c ON c.id = e.client_id
                         LEFT JOIN cases cs ON cs.id = e.case_id
                         WHERE e.usuario_id = ? AND e.tipo = 'lembrete' AND e.arquivado = 1
                         ORDER BY e.id DESC LIMIT 100");
    $st->execute(array($userId));
    echo json_encode(array('ok' => true, 'lembretes' => $st->fetchAll(PDO::FETCH_ASSOC)));
    exit;
}

if ($action === 'buscar_clientes_lembrete') {
    $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(array()); exit; }
    $st = $pdo->prepare("SELECT id, name, cpf FROM clients WHERE name LIKE ? ORDER BY name ASC LIMIT 12");
    $st->execute(array('%' . $q . '%'));
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'buscar_casos_lembrete') {
    $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(array()); exit; }
    $st = $pdo->prepare("SELECT cs.id, cs.title, cs.case_number, cl.name AS client_name
                         FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id
                         WHERE cs.title LIKE ? OR cs.case_number LIKE ? OR cl.name LIKE ?
                         ORDER BY cs.title LIMIT 12");
    $st->execute(array('%'.$q.'%','%'.$q.'%','%'.$q.'%'));
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'obter_lembrete') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'ID inválido')); exit; }
    $st = $pdo->prepare("SELECT e.*, c.name AS client_name, cs.title AS case_title, cs.case_number
                         FROM eventos_dia e
                         LEFT JOIN clients c ON c.id = e.client_id
                         LEFT JOIN cases cs ON cs.id = e.case_id
                         WHERE e.id = ? AND e.tipo = 'lembrete' AND (e.usuario_id = ? OR e.criado_por = ?)");
    $st->execute(array($id, $userId, $userId));
    $r = $st->fetch();
    if (!$r) { echo json_encode(array('error' => 'Não encontrado')); exit; }
    echo json_encode(array('ok' => true, 'lembrete' => $r));
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
