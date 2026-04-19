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
                  'sincronizar_conversa', 'importar_todos',
                  'editar_conversa', 'adicionar_etiqueta', 'remover_etiqueta',
                  'deletar_mensagem', 'editar_mensagem');
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

    // Filtro adicional por etiqueta
    $etiquetaId = (int)($_GET['etiqueta'] ?? 0);
    $joinEtq = '';
    if ($etiquetaId) {
        $joinEtq = " INNER JOIN zapi_conversa_etiquetas ce_f ON ce_f.conversa_id = co.id AND ce_f.etiqueta_id = ? ";
        array_unshift($params, $etiquetaId);
    }

    $sql = "SELECT co.id, co.telefone, co.nome_contato, co.status, co.nao_lidas,
                   co.bot_ativo, co.ultima_mensagem, co.ultima_msg_em, co.canal,
                   co.client_id, co.lead_id, co.atendente_id,
                   cl.name AS client_name,
                   pl.name AS lead_name,
                   u.name AS atendente_name,
                   (SELECT GROUP_CONCAT(CONCAT_WS('|', e.id, e.nome, e.cor) SEPARATOR '§')
                    FROM zapi_conversa_etiquetas ce JOIN zapi_etiquetas e ON e.id = ce.etiqueta_id
                    WHERE ce.conversa_id = co.id) AS etiquetas_raw
            FROM zapi_conversas co
            LEFT JOIN clients cl ON cl.id = co.client_id
            LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
            LEFT JOIN users u ON u.id = co.atendente_id
            {$joinEtq}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(co.ultima_msg_em, co.created_at) DESC
            LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Parse etiquetas: "id|nome|cor§id|nome|cor" → array de {id,nome,cor}
    foreach ($rows as &$r) {
        $r['etiquetas'] = array();
        if (!empty($r['etiquetas_raw'])) {
            foreach (explode('§', $r['etiquetas_raw']) as $piece) {
                $p = explode('|', $piece);
                if (count($p) === 3) $r['etiquetas'][] = array('id' => $p[0], 'nome' => $p[1], 'cor' => $p[2]);
            }
        }
        unset($r['etiquetas_raw']);
    }

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

    // Mensagens (últimas 200)
    $msgs = $pdo->prepare("SELECT m.*, u.name AS enviado_por_name
                           FROM zapi_mensagens m
                           LEFT JOIN users u ON u.id = m.enviado_por_id
                           WHERE m.conversa_id = ?
                           ORDER BY m.id ASC
                           LIMIT 200");
    $msgs->execute(array($id));
    $mensagens = $msgs->fetchAll();

    // Etiquetas aplicadas nesta conversa
    $etqStmt = $pdo->prepare("SELECT e.id, e.nome, e.cor FROM zapi_etiquetas e
                              JOIN zapi_conversa_etiquetas ce ON ce.etiqueta_id = e.id
                              WHERE ce.conversa_id = ? ORDER BY e.ordem");
    $etqStmt->execute(array($id));
    $conv['etiquetas'] = $etqStmt->fetchAll();

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

    // Assinatura do atendente (configurável em Automações)
    $assinar = zapi_auto_cfg('zapi_signature_on', '0') === '1';
    $textoEnviar = $texto;
    if ($assinar) {
        $formato = zapi_auto_cfg('zapi_signature_format', '— {{atendente}}');
        $nomeUser = current_user()['name'] ?? '';
        $assinatura = str_replace('{{atendente}}', $nomeUser, $formato);
        $textoEnviar = rtrim($texto) . "\n\n" . $assinatura;
    }

    $resp = zapi_send_text($conv['canal'], $conv['telefone'], $textoEnviar);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Falha ao enviar: ' . ($resp['erro'] ?? 'HTTP ' . ($resp['http_code'] ?? '?')) . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }

    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo, enviado_por_id, status)
                   VALUES (?, ?, 'enviada', 'texto', ?, ?, 'enviada')")
        ->execute(array($convId, $zapiId, $textoEnviar, $userId));

    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                   status = IF(status = 'aguardando', 'em_atendimento', status),
                   atendente_id = COALESCE(atendente_id, ?)
                   WHERE id = ?")
        ->execute(array(mb_substr($textoEnviar, 0, 500), $userId, $convId));

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

// ── EDITAR CONVERSA (nome, anotações) ───────────────────
if ($action === 'editar_conversa') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $nome   = trim($_POST['nome_contato'] ?? '');
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }
    if (mb_strlen($nome) > 150) $nome = mb_substr($nome, 0, 150);
    $pdo->prepare("UPDATE zapi_conversas SET nome_contato = ? WHERE id = ?")->execute(array($nome ?: null, $convId));
    audit_log('zapi_editar_conv', 'zapi_conversas', $convId, "nome={$nome}");
    echo json_encode(array('ok' => true));
    exit;
}

// ── LISTAR ETIQUETAS (com flag de aplicada em conversa) ──
if ($action === 'listar_etiquetas') {
    $convId = (int)($_GET['conversa_id'] ?? 0);
    $sql = "SELECT e.id, e.nome, e.cor, e.ordem,
                   " . ($convId ? "(SELECT 1 FROM zapi_conversa_etiquetas WHERE conversa_id = ? AND etiqueta_id = e.id) AS aplicada" : "0 as aplicada") . "
            FROM zapi_etiquetas e WHERE e.ativo = 1 ORDER BY e.ordem, e.nome";
    $stmt = $pdo->prepare($sql);
    if ($convId) $stmt->execute(array($convId));
    else $stmt->execute();
    echo json_encode(array('ok' => true, 'etiquetas' => $stmt->fetchAll()));
    exit;
}

// ── APLICAR ETIQUETA EM CONVERSA ─────────────────────────
if ($action === 'adicionar_etiqueta') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $etqId  = (int)($_POST['etiqueta_id'] ?? 0);
    if (!$convId || !$etqId) { echo json_encode(array('error' => 'Parâmetros inválidos')); exit; }
    $pdo->prepare("INSERT IGNORE INTO zapi_conversa_etiquetas (conversa_id, etiqueta_id, aplicada_por) VALUES (?, ?, ?)")
        ->execute(array($convId, $etqId, $userId));
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'remover_etiqueta') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $etqId  = (int)($_POST['etiqueta_id'] ?? 0);
    $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ? AND etiqueta_id = ?")
        ->execute(array($convId, $etqId));
    echo json_encode(array('ok' => true));
    exit;
}

// ── DELETAR MENSAGEM (remove do WhatsApp e marca no banco) ──
if ($action === 'deletar_mensagem') {
    $msgId = (int)($_POST['mensagem_id'] ?? 0);
    if (!$msgId) { echo json_encode(array('error' => 'mensagem_id obrigatório')); exit; }
    $m = $pdo->prepare("SELECT m.*, co.telefone, co.canal
                        FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                        WHERE m.id = ?");
    $m->execute(array($msgId));
    $msg = $m->fetch();
    if (!$msg) { echo json_encode(array('error' => 'Mensagem não encontrada')); exit; }
    if ($msg['direcao'] !== 'enviada') { echo json_encode(array('error' => 'Só dá pra apagar mensagens enviadas pelo Hub')); exit; }
    if (!$msg['zapi_message_id']) { echo json_encode(array('error' => 'Mensagem sem ID Z-API — não foi efetivamente enviada')); exit; }

    $r = zapi_delete_message($msg['canal'], $msg['telefone'], $msg['zapi_message_id']);
    if (empty($r['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($r['http_code'] ?? '?') . ' — ' . json_encode($r['data'] ?? '')));
        exit;
    }
    // Marca como apagada no banco (preserva histórico)
    $pdo->prepare("UPDATE zapi_mensagens SET conteudo = '[mensagem apagada]', tipo = 'outro', status = 'deletada', arquivo_url = NULL WHERE id = ?")
        ->execute(array($msgId));
    audit_log('zapi_delete_msg', 'zapi_mensagens', $msgId);
    echo json_encode(array('ok' => true));
    exit;
}

// ── EDITAR MENSAGEM (reenvia via Z-API com flag edit) ──
if ($action === 'editar_mensagem') {
    $msgId = (int)($_POST['mensagem_id'] ?? 0);
    $novo  = trim($_POST['novo_texto'] ?? '');
    if (!$msgId || !$novo) { echo json_encode(array('error' => 'mensagem_id e novo_texto obrigatórios')); exit; }
    $m = $pdo->prepare("SELECT m.*, co.telefone, co.canal
                        FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                        WHERE m.id = ?");
    $m->execute(array($msgId));
    $msg = $m->fetch();
    if (!$msg) { echo json_encode(array('error' => 'Mensagem não encontrada')); exit; }
    if ($msg['direcao'] !== 'enviada') { echo json_encode(array('error' => 'Só dá pra editar mensagens enviadas pelo Hub')); exit; }
    if ($msg['tipo'] !== 'texto') { echo json_encode(array('error' => 'Só dá pra editar texto')); exit; }
    if (!$msg['zapi_message_id']) { echo json_encode(array('error' => 'Mensagem sem ID Z-API')); exit; }

    // WhatsApp só permite editar até 15 min
    $idadeMin = (time() - strtotime($msg['created_at'])) / 60;
    if ($idadeMin > 15) { echo json_encode(array('error' => 'Passou de 15 min — WhatsApp não permite mais editar. Apague e reenvie.')); exit; }

    $r = zapi_edit_message($msg['canal'], $msg['telefone'], $msg['zapi_message_id'], $novo);
    if (empty($r['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($r['http_code'] ?? '?') . ' — ' . json_encode($r['data'] ?? '')));
        exit;
    }
    $pdo->prepare("UPDATE zapi_mensagens SET conteudo = ? WHERE id = ?")->execute(array($novo, $msgId));
    audit_log('zapi_edit_msg', 'zapi_mensagens', $msgId);
    echo json_encode(array('ok' => true));
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
    set_time_limit(300); // 5 min para import grande
    $ddd = $_POST['ddd'] ?? '21';
    $max = (int)($_POST['max_chats'] ?? 200);
    if ($max > 500) $max = 500;

    $pageSize  = 50;     // Z-API pagina em lotes de 50
    $totalPages = (int)ceil($max / $pageSize);
    $totalConv = 0;
    $pulados   = 0;
    $pages     = array();

    for ($page = 1; $page <= $totalPages; $page++) {
        $chats = zapi_fetch_chats($ddd, $page, $pageSize);
        $pages[] = array('page' => $page, 'count' => is_array($chats) ? count($chats) : 0);
        if (!is_array($chats) || empty($chats)) break; // sem mais páginas

        foreach ($chats as $chat) {
            $tel  = $chat['phone'] ?? '';
            $nome = $chat['name'] ?? null;
            if (!$tel) { $pulados++; continue; }
            // Pular grupos
            if (strpos($tel, '-') !== false || strpos($tel, '@g.us') !== false) { $pulados++; continue; }
            $conv = zapi_buscar_ou_criar_conversa($tel, $ddd, $nome);
            if (!$conv) continue;
            $totalConv++;
            if ($totalConv >= $max) break 2; // atingiu o teto
        }
    }
    audit_log('zapi_import_all', 'zapi_instancias', 0, "Conv={$totalConv} Pulados={$pulados}");
    echo json_encode(array(
        'ok' => true,
        'conversas' => $totalConv,
        'pulados' => $pulados,
        'paginas' => $pages,
    ));
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
