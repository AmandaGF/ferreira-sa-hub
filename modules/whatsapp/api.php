<?php
/**
 * Ferreira & Sá Hub — API interna WhatsApp CRM
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_once APP_ROOT . '/core/functions_zapi.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$userId = current_user_id();
$action = $_REQUEST['action'] ?? '';

// CSRF só para ações que mutam
$mutantes = array('enviar_mensagem', 'assumir_atendimento', 'atribuir', 'resolver',
                  'ativar_bot', 'desativar_bot', 'marcar_lida', 'arquivar');
if (in_array($action, $mutantes, true)) {
    if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido')); exit; }
}

// ── LISTAR CONVERSAS ─────────────────────────────────────
if ($action === 'listar_conversas') {
    $canal   = $_GET['canal']   ?? '21';
    $status  = $_GET['status']  ?? '';
    $busca   = trim($_GET['q']  ?? '');
    $where   = array('co.canal = ?');
    $params  = array($canal);

    if ($status && $status !== 'todos') {
        if ($status === 'bot')  $where[] = 'co.bot_ativo = 1';
        elseif ($status === 'nao_lidas') $where[] = 'co.nao_lidas > 0';
        else { $where[] = 'co.status = ?'; $params[] = $status; }
    }
    if ($busca !== '') {
        $where[] = '(co.nome_contato LIKE ? OR co.telefone LIKE ? OR cl.name LIKE ?)';
        $params[] = "%$busca%"; $params[] = "%$busca%"; $params[] = "%$busca%";
    }

    $sql = "SELECT co.id, co.telefone, co.nome_contato, co.status, co.nao_lidas,
                   co.bot_ativo, co.ultima_mensagem, co.ultima_msg_em, co.canal,
                   co.client_id, co.lead_id, co.atendente_id,
                   cl.name AS client_name,
                   pl.nome_completo AS lead_name,
                   u.name AS atendente_name
            FROM zapi_conversas co
            LEFT JOIN clients cl ON cl.id = co.client_id
            LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
            LEFT JOIN users u ON u.id = co.atendente_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(co.ultima_msg_em, co.created_at) DESC
            LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Status das instâncias
    $inst = array();
    foreach ($pdo->query("SELECT ddd, conectado, instancia_id FROM zapi_instancias")->fetchAll() as $i) {
        $inst[$i['ddd']] = array(
            'conectado'    => (int)$i['conectado'],
            'configurado'  => $i['instancia_id'] !== '',
        );
    }

    echo json_encode(array('ok' => true, 'conversas' => $rows, 'instancias' => $inst));
    exit;
}

// ── ABRIR CONVERSA (zera não lidas + retorna mensagens) ──
if ($action === 'abrir_conversa') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT co.*, cl.name AS client_name, pl.nome_completo AS lead_name,
                                  u.name AS atendente_name
                           FROM zapi_conversas co
                           LEFT JOIN clients cl ON cl.id = co.client_id
                           LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
                           LEFT JOIN users u ON u.id = co.atendente_id
                           WHERE co.id = ?");
    $stmt->execute(array($id));
    $conv = $stmt->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    // Zera não lidas
    $pdo->prepare("UPDATE zapi_conversas SET nao_lidas = 0 WHERE id = ?")->execute(array($id));

    // Mensagens (últimas 80)
    $msgs = $pdo->prepare("SELECT m.*, u.name AS enviado_por_name
                           FROM zapi_mensagens m
                           LEFT JOIN users u ON u.id = m.enviado_por_id
                           WHERE m.conversa_id = ?
                           ORDER BY m.id ASC
                           LIMIT 200");
    $msgs->execute(array($id));
    $mensagens = $msgs->fetchAll();

    echo json_encode(array('ok' => true, 'conversa' => $conv, 'mensagens' => $mensagens));
    exit;
}

// ── ENVIAR MENSAGEM ──────────────────────────────────────
if ($action === 'enviar_mensagem') {
    $convId  = (int)($_POST['conversa_id'] ?? 0);
    $texto   = trim($_POST['mensagem'] ?? '');
    if (!$convId || !$texto) { echo json_encode(array('error' => 'Parâmetros inválidos')); exit; }

    $conv = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $resp = zapi_send_text($conv['canal'], $conv['telefone'], $texto);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Falha ao enviar: ' . ($resp['erro'] ?? 'HTTP ' . ($resp['http_code'] ?? '?')) . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }

    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo, enviado_por_id, status)
                   VALUES (?, ?, 'enviada', 'texto', ?, ?, 'enviada')")
        ->execute(array($convId, $zapiId, $texto, $userId));

    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                   status = IF(status = 'aguardando', 'em_atendimento', status),
                   atendente_id = COALESCE(atendente_id, ?)
                   WHERE id = ?")
        ->execute(array(mb_substr($texto, 0, 500), $userId, $convId));

    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId));
    exit;
}

// ── ASSUMIR ATENDIMENTO (e desativar bot) ────────────────
if ($action === 'assumir_atendimento') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET atendente_id = ?, bot_ativo = 0, status = 'em_atendimento' WHERE id = ?")
        ->execute(array($userId, $convId));
    audit_log('zapi_assumir', 'zapi_conversas', $convId);
    echo json_encode(array('ok' => true));
    exit;
}

// ── ATRIBUIR PARA OUTRO USUÁRIO ──────────────────────────
if ($action === 'atribuir') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $alvoId = (int)($_POST['atendente_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET atendente_id = ?, status = 'em_atendimento' WHERE id = ?")
        ->execute(array($alvoId ?: null, $convId));
    audit_log('zapi_atribuir', 'zapi_conversas', $convId, "Atribuido para user={$alvoId}");
    echo json_encode(array('ok' => true));
    exit;
}

// ── RESOLVER / ARQUIVAR ──────────────────────────────────
if ($action === 'resolver') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET status = 'resolvido' WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}
if ($action === 'arquivar') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET status = 'arquivado' WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}

// ── ATIVAR / DESATIVAR BOT ───────────────────────────────
if ($action === 'ativar_bot') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET bot_ativo = 1 WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}
if ($action === 'desativar_bot') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET bot_ativo = 0 WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}

// ── TEMPLATES ────────────────────────────────────────────
if ($action === 'listar_templates') {
    $canal = $_GET['canal'] ?? '21';
    $stmt = $pdo->prepare("SELECT id, nome, conteudo, categoria FROM zapi_templates
                           WHERE ativo = 1 AND (canal = ? OR canal = 'ambos') ORDER BY nome ASC");
    $stmt->execute(array($canal));
    echo json_encode(array('ok' => true, 'templates' => $stmt->fetchAll()));
    exit;
}

// ── LISTAR USUÁRIOS (para atribuir) ──────────────────────
if ($action === 'listar_usuarios') {
    $rows = $pdo->query("SELECT id, name, role FROM users WHERE active = 1 ORDER BY name ASC")->fetchAll();
    echo json_encode(array('ok' => true, 'usuarios' => $rows));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
