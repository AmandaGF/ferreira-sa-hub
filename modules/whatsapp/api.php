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
$mutantes = array('enviar_mensagem', 'enviar_arquivo', 'assumir_atendimento', 'atribuir', 'resolver',
                  'ativar_bot', 'desativar_bot', 'marcar_lida', 'arquivar',
                  'sincronizar_conversa', 'importar_todos');
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
                   pl.name AS lead_name,
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
    $stmt = $pdo->prepare("SELECT co.*, cl.name AS client_name, pl.name AS lead_name,
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

// ── VERIFICAR STATUS DA INSTÂNCIA ────────────────────────
if ($action === 'verificar_status') {
    $ddd = $_GET['ddd'] ?? '21';
    if (!in_array($ddd, array('21','24'), true)) { echo json_encode(array('error'=>'DDD inválido')); exit; }
    $conectado = zapi_verificar_status($ddd);
    echo json_encode(array('ok' => true, 'conectado' => $conectado));
    exit;
}

// ── ENVIAR ARQUIVO (imagem ou documento) ─────────────────
if ($action === 'enviar_arquivo') {
    $convId  = (int)($_POST['conversa_id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }
    if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(array('error' => 'Falha no upload'));
        exit;
    }

    $conv = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $tmp = $_FILES['arquivo']['tmp_name'];
    $nome = $_FILES['arquivo']['name'];
    $mime = $_FILES['arquivo']['type'] ?: mime_content_type($tmp);
    $tam  = (int)$_FILES['arquivo']['size'];

    // Limite 16 MB (WhatsApp aceita até ~100MB em docs, mas começamos conservador)
    if ($tam > 16 * 1024 * 1024) { echo json_encode(array('error' => 'Arquivo maior que 16 MB')); exit; }

    // Guardar o arquivo localmente em /files/whatsapp/ (para servir ao Z-API via URL)
    $destDir = APP_ROOT . '/files/whatsapp';
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $nomeSanitizado = preg_replace('/[^A-Za-z0-9._-]/', '_', $nome);
    $storedName = uniqid('wa_', true) . '_' . $nomeSanitizado;
    $dest = $destDir . '/' . $storedName;
    if (!move_uploaded_file($tmp, $dest)) {
        echo json_encode(array('error' => 'Falha ao salvar arquivo no servidor'));
        exit;
    }
    @chmod($dest, 0644);
    $publicUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ferreiraesa.com.br') . '/conecta/files/whatsapp/' . rawurlencode($storedName);

    // Detectar tipo
    $isImage = (strpos($mime, 'image/') === 0);
    $tipo    = $isImage ? 'imagem' : 'documento';

    // Enviar via Z-API
    if ($isImage) {
        $resp = zapi_send_image($conv['canal'], $conv['telefone'], $publicUrl, $caption);
    } else {
        $resp = zapi_send_document($conv['canal'], $conv['telefone'], $publicUrl, $nome, $caption);
    }
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($resp['http_code'] ?? '?') . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }

    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    $pdo->prepare(
        "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo,
            arquivo_url, arquivo_nome, arquivo_mime, arquivo_tamanho, enviado_por_id, status)
         VALUES (?, ?, 'enviada', ?, ?, ?, ?, ?, ?, ?, 'enviada')"
    )->execute(array($convId, $zapiId, $tipo, $caption ?: '[' . $tipo . ']', $publicUrl, $nome, $mime, $tam, $userId));

    $preview = $caption ?: ('[' . $tipo . '] ' . $nome);
    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                   status = IF(status = 'aguardando', 'em_atendimento', status),
                   atendente_id = COALESCE(atendente_id, ?)
                   WHERE id = ?")
        ->execute(array(mb_substr($preview, 0, 500), $userId, $convId));

    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId, 'url' => $publicUrl));
    exit;
}

// ── SINCRONIZAR HISTÓRICO DE UMA CONVERSA ────────────────
if ($action === 'sincronizar_conversa') {
    $convId = (int)($_POST['conversa_id'] ?? $_GET['conversa_id'] ?? 0);
    $limit  = (int)($_POST['limite'] ?? $_GET['limite'] ?? 50);
    if ($limit > 200) $limit = 200;
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    $res = zapi_sincronizar_historico_conversa($convId, $limit);
    if (isset($res['erro'])) { echo json_encode(array('error' => $res['erro'])); exit; }
    audit_log('zapi_sync_conv', 'zapi_conversas', $convId, "Importadas: {$res['importadas']}/{$res['total_recebido']}");
    echo json_encode(array('ok' => true, 'importadas' => $res['importadas'], 'total' => $res['total_recebido']));
    exit;
}

// ── IMPORTAR TODOS OS CHATS DA INSTÂNCIA (admin/gestão) ──
if ($action === 'importar_todos') {
    if (!has_min_role('gestao')) { echo json_encode(array('error' => 'Acesso restrito')); exit; }
    $ddd   = $_POST['ddd']   ?? '21';
    $limit = (int)($_POST['limite'] ?? 30);   // msgs por chat
    $max   = (int)($_POST['max_chats'] ?? 50); // total de chats a importar

    $chats = zapi_fetch_chats($ddd, 1, $max);
    if ($chats === null) { echo json_encode(array('error' => 'Falha ao listar chats da Z-API')); exit; }

    $totalConv = 0; $totalMsg = 0;
    foreach ($chats as $chat) {
        $tel  = $chat['phone'] ?? '';
        $nome = $chat['name'] ?? null;
        if (!$tel) continue;
        // Pular grupos (phone tem "-" ou contém "@g.us")
        if (strpos($tel, '-') !== false || strpos($tel, '@g.us') !== false) continue;
        $conv = zapi_buscar_ou_criar_conversa($tel, $ddd, $nome);
        if (!$conv) continue;
        $r = zapi_sincronizar_historico_conversa($conv['id'], $limit);
        $totalMsg += $r['importadas'] ?? 0;
        $totalConv++;
    }
    audit_log('zapi_import_all', 'zapi_instancias', 0, "Conv={$totalConv} Msgs={$totalMsg}");
    echo json_encode(array('ok' => true, 'conversas' => $totalConv, 'mensagens' => $totalMsg));
    exit;
}

// ── DEBUG: resposta crua do Z-API chat-messages (gestão+) ────────
if ($action === 'debug_zapi_fetch' && has_min_role('gestao')) {
    $convId = (int)($_GET['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }
    $stmt = $pdo->prepare("SELECT co.*, i.ddd FROM zapi_conversas co JOIN zapi_instancias i ON i.id = co.instancia_id WHERE co.id = ?");
    $stmt->execute(array($convId));
    $c = $stmt->fetch();
    if (!$c) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }
    $debug = null;
    $raw = zapi_fetch_chat_messages($c['ddd'], $c['telefone'], 5, $debug);
    echo json_encode(array('ok' => true, 'ddd' => $c['ddd'], 'telefone' => $c['telefone'], 'debug' => $debug, 'raw' => $raw), JSON_PRETTY_PRINT);
    exit;
}

// ── DEBUG: última mensagem recebida (pra ver estrutura do payload) ─
if ($action === 'debug_ultima_midia' && has_min_role('gestao')) {
    $row = $pdo->query("SELECT * FROM zapi_mensagens WHERE direcao='recebida' AND tipo IN ('imagem','video','documento','audio') ORDER BY id DESC LIMIT 1")->fetch();
    echo json_encode(array('ok' => true, 'msg' => $row));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
