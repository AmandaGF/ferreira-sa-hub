<?php
/**
 * Newsletter — API (CRUD + Brevo)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('formularios');

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$userId = current_user_id();

// ── Helpers Brevo ──
function brevo_config() {
    static $cfg = null;
    if ($cfg) return $cfg;
    $cfg = array('key' => '', 'email' => 'contato@ferreiraesa.com.br', 'name' => 'Ferreira & Sá Advocacia');
    try {
        $rows = db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'brevo_api_key') $cfg['key'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_name') $cfg['name'] = $r['valor'];
        }
    } catch (Exception $e) {}
    return $cfg;
}

function brevo_request($method, $endpoint, $data = null) {
    $cfg = brevo_config();
    if (!$cfg['key']) return array('error' => 'API Key do Brevo não configurada');
    $url = 'https://api.brevo.com/v3' . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array('api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $resp = json_decode($body, true);
    if ($code >= 400) {
        $msg = isset($resp['message']) ? $resp['message'] : 'HTTP ' . $code;
        return array('error' => $msg, 'code' => $code);
    }
    return $resp ?: array('ok' => true);
}

// ── GET ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'listar';

    if ($action === 'listar') {
        $rows = $pdo->query("SELECT c.*, u.name as criado_por FROM newsletter_campanhas c LEFT JOIN users u ON u.id = c.created_by ORDER BY c.created_at DESC LIMIT 50")->fetchAll();
        echo json_encode($rows);
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM newsletter_campanhas WHERE id = ?");
        $stmt->execute(array($id));
        echo json_encode($stmt->fetch() ?: array('error' => 'Não encontrada'));
        exit;
    }

    if ($action === 'contar_destinatarios') {
        $segmento = $_GET['segmento'] ?? 'todos';
        $filtro = $_GET['filtro'] ?? '';
        $count = contar_segmento($pdo, $segmento, $filtro);
        echo json_encode(array('total' => $count));
        exit;
    }

    if ($action === 'listar_destinatarios') {
        $segmento = $_GET['segmento'] ?? 'todos';
        $filtro = $_GET['filtro'] ?? '';
        $dest = buscar_destinatarios($pdo, $segmento, $filtro);
        echo json_encode($dest);
        exit;
    }

    if ($action === 'tipos_acao') {
        $rows = $pdo->query("SELECT DISTINCT case_type FROM cases WHERE case_type IS NOT NULL AND case_type != '' ORDER BY case_type")->fetchAll();
        echo json_encode(array_column($rows, 'case_type'));
        exit;
    }

    if ($action === 'testar_brevo') {
        $cfg = brevo_config();
        if (!$cfg['key']) { echo json_encode(array('error' => 'API Key não configurada')); exit; }
        $resp = brevo_request('GET', '/account');
        echo json_encode($resp);
        exit;
    }

    echo json_encode(array('error' => 'Ação inválida'));
    exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido', 'csrf' => generate_csrf_token())); exit; }
$newCsrf = generate_csrf_token();
$action = $_POST['action'] ?? '';

// ── SALVAR (rascunho) ──
if ($action === 'salvar') {
    $id = (int)($_POST['id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $assunto = trim($_POST['assunto'] ?? '');
    $templateTipo = $_POST['template_tipo'] ?? 'informativo';
    $conteudoHtml = $_POST['conteudo_html'] ?? '';
    $segmento = $_POST['segmento'] ?? 'todos';
    $segmentoFiltro = $_POST['segmento_filtro'] ?? '';

    if (!$titulo) { echo json_encode(array('error' => 'Título obrigatório', 'csrf' => $newCsrf)); exit; }
    if (!$assunto) { echo json_encode(array('error' => 'Assunto obrigatório', 'csrf' => $newCsrf)); exit; }

    if ($id) {
        $pdo->prepare("UPDATE newsletter_campanhas SET titulo=?, assunto=?, template_tipo=?, conteudo_html=?, segmento=?, segmento_filtro=? WHERE id=? AND status='rascunho'")
            ->execute(array($titulo, $assunto, $templateTipo, $conteudoHtml, $segmento, $segmentoFiltro, $id));
    } else {
        $pdo->prepare("INSERT INTO newsletter_campanhas (titulo, assunto, template_tipo, conteudo_html, segmento, segmento_filtro, status, created_by) VALUES (?,?,?,?,?,?,'rascunho',?)")
            ->execute(array($titulo, $assunto, $templateTipo, $conteudoHtml, $segmento, $segmentoFiltro, $userId));
        $id = (int)$pdo->lastInsertId();
    }
    audit_log('NEWSLETTER_SALVA', 'newsletter', $id, $titulo);
    echo json_encode(array('ok' => true, 'id' => $id, 'csrf' => $newCsrf));
    exit;
}

// ── ENVIAR TESTE ──
if ($action === 'enviar_teste') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'Salve a campanha primeiro', 'csrf' => $newCsrf)); exit; }

    $stmt = $pdo->prepare("SELECT * FROM newsletter_campanhas WHERE id = ?");
    $stmt->execute(array($id));
    $camp = $stmt->fetch();
    if (!$camp) { echo json_encode(array('error' => 'Campanha não encontrada', 'csrf' => $newCsrf)); exit; }

    $cfg = brevo_config();
    $user = current_user();
    $emailTeste = $user['email'];

    $htmlFinal = montar_html_final($camp['conteudo_html'], 'Teste', $emailTeste);

    $resp = brevo_request('POST', '/smtp/email', array(
        'sender' => array('name' => $cfg['name'], 'email' => $cfg['email']),
        'to' => array(array('email' => $emailTeste, 'name' => $user['name'])),
        'subject' => '[TESTE] ' . $camp['assunto'],
        'htmlContent' => $htmlFinal,
    ));

    if (isset($resp['error'])) {
        echo json_encode(array('error' => 'Brevo: ' . $resp['error'], 'csrf' => $newCsrf));
        exit;
    }
    echo json_encode(array('ok' => true, 'enviado_para' => $emailTeste, 'csrf' => $newCsrf));
    exit;
}

// ── ENVIAR CAMPANHA ──
if ($action === 'enviar') {
    $id = (int)($_POST['id'] ?? 0);
    $agendar = $_POST['agendar'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM newsletter_campanhas WHERE id = ?");
    $stmt->execute(array($id));
    $camp = $stmt->fetch();
    if (!$camp) { echo json_encode(array('error' => 'Campanha não encontrada', 'csrf' => $newCsrf)); exit; }
    if ($camp['status'] !== 'rascunho') { echo json_encode(array('error' => 'Campanha já enviada', 'csrf' => $newCsrf)); exit; }

    $cfg = brevo_config();
    if (!$cfg['key']) { echo json_encode(array('error' => 'API Key do Brevo não configurada', 'csrf' => $newCsrf)); exit; }

    // Buscar destinatários
    $dest = buscar_destinatarios($pdo, $camp['segmento'], $camp['segmento_filtro']);
    if (empty($dest)) { echo json_encode(array('error' => 'Nenhum destinatário encontrado', 'csrf' => $newCsrf)); exit; }

    // Importar contatos no Brevo
    $contacts = array();
    foreach ($dest as $d) {
        $contacts[] = array('email' => $d['email'], 'attributes' => array('NOME' => $d['name']));
    }

    // Criar lista no Brevo para esta campanha
    $listResp = brevo_request('POST', '/contacts/lists', array(
        'name' => 'FSA-Camp-' . $id . '-' . date('YmdHis'),
        'folderId' => 1,
    ));
    $listId = isset($listResp['id']) ? (int)$listResp['id'] : 0;
    if (!$listId) {
        echo json_encode(array('error' => 'Erro ao criar lista no Brevo: ' . ($listResp['error'] ?? 'desconhecido'), 'csrf' => $newCsrf));
        exit;
    }

    // Importar contatos na lista
    $importResp = brevo_request('POST', '/contacts/import', array(
        'listIds' => array($listId),
        'emailBlacklist' => false,
        'updateExistingContacts' => true,
        'jsonBody' => $contacts,
    ));

    $htmlFinal = montar_html_final($camp['conteudo_html'], '[nome]', '[email]');

    // Criar campanha no Brevo
    $campData = array(
        'name' => $camp['titulo'],
        'subject' => $camp['assunto'],
        'sender' => array('name' => $cfg['name'], 'email' => $cfg['email']),
        'htmlContent' => $htmlFinal,
        'recipients' => array('listIds' => array($listId)),
    );
    if ($agendar) {
        $campData['scheduledAt'] = date('c', strtotime($agendar));
    }

    $campResp = brevo_request('POST', '/emailCampaigns', $campData);
    if (isset($campResp['error'])) {
        echo json_encode(array('error' => 'Brevo campanha: ' . $campResp['error'], 'csrf' => $newCsrf));
        exit;
    }
    $brevoCampId = isset($campResp['id']) ? $campResp['id'] : '';

    // Se não agendou, enviar imediatamente
    if (!$agendar && $brevoCampId) {
        brevo_request('POST', '/emailCampaigns/' . $brevoCampId . '/sendNow');
    }

    // Atualizar no banco
    $novoStatus = $agendar ? 'agendado' : 'enviando';
    $pdo->prepare("UPDATE newsletter_campanhas SET status=?, total_destinatarios=?, brevo_campaign_id=?, agendado_para=? WHERE id=?")
        ->execute(array($novoStatus, count($dest), $brevoCampId, $agendar ?: null, $id));

    audit_log('NEWSLETTER_ENVIADA', 'newsletter', $id, count($dest) . ' destinatários');
    echo json_encode(array('ok' => true, 'status' => $novoStatus, 'destinatarios' => count($dest), 'brevo_id' => $brevoCampId, 'csrf' => $newCsrf));
    exit;
}

// ── EXCLUIR ──
if ($action === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM newsletter_campanhas WHERE id = ? AND status = 'rascunho'")->execute(array($id));
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

// ── SALVAR CONFIG BREVO ──
if ($action === 'salvar_config') {
    if (!has_role('admin')) { echo json_encode(array('error' => 'Somente admin', 'csrf' => $newCsrf)); exit; }
    $apiKey = trim($_POST['brevo_api_key'] ?? '');
    $senderEmail = trim($_POST['brevo_sender_email'] ?? '');
    $senderName = trim($_POST['brevo_sender_name'] ?? '');

    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('brevo_api_key', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute(array($apiKey, $apiKey));
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('brevo_sender_email', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute(array($senderEmail, $senderEmail));
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('brevo_sender_name', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute(array($senderName, $senderName));

    audit_log('BREVO_CONFIG', null, null, 'API configurada');
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));

// ══════════════════════════════════════
// HELPERS
// ══════════════════════════════════════

function buscar_destinatarios($pdo, $segmento, $filtro = '') {
    $base = "SELECT cl.id, cl.name, cl.email FROM clients cl WHERE cl.email IS NOT NULL AND cl.email != '' AND cl.id NOT IN (SELECT COALESCE(client_id,0) FROM newsletter_descadastros)";

    if ($segmento === 'tipo_acao' && $filtro) {
        $stmt = $pdo->prepare($base . " AND cl.id IN (SELECT client_id FROM cases WHERE case_type = ?)");
        $stmt->execute(array($filtro));
    } elseif ($segmento === 'status_processo' && $filtro) {
        $stmt = $pdo->prepare($base . " AND cl.id IN (SELECT client_id FROM cases WHERE status = ?)");
        $stmt->execute(array($filtro));
    } elseif ($segmento === 'aniversariantes') {
        $stmt = $pdo->query($base . " AND MONTH(cl.birth_date) = MONTH(NOW())");
    } else {
        $stmt = $pdo->query($base);
    }
    return $stmt->fetchAll();
}

function contar_segmento($pdo, $segmento, $filtro = '') {
    return count(buscar_destinatarios($pdo, $segmento, $filtro));
}

function montar_html_final($conteudo, $nome, $email) {
    $descadastroUrl = 'https://ferreiraesa.com.br/conecta/publico/descadastro.php?email=' . rawurlencode($email);
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:Calibri,sans-serif;margin:0;padding:0;background:#f4f4f4;">';
    $html .= '<div style="max-width:600px;margin:0 auto;background:#fff;">';
    $html .= $conteudo;
    $html .= '<div style="background:#052228;color:#fff;padding:20px;text-align:center;font-size:12px;line-height:1.6;">';
    $html .= '<p style="margin:0 0 8px;">Ferreira &amp; Sa Advocacia Especializada</p>';
    $html .= '<p style="margin:0 0 8px;opacity:.7;">Rua Dr. Aldrovando de Oliveira, 140, Ano Bom, Barra Mansa/RJ</p>';
    $html .= '<p style="margin:0;"><a href="' . $descadastroUrl . '" style="color:#D7AB90;">Cancelar inscricao</a></p>';
    $html .= '</div></div></body></html>';
    return str_replace(array('[nome]', '[NOME]'), $nome, $html);
}
