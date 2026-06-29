<?php
/**
 * Audiencistas — correspondentes de audiência.
 *
 *  📋 Audiências a contratar (área principal): cadastra a audiência que precisamos
 *     (tipo, comarca/data, processo, orientações), sobe o arquivo do processo,
 *     designa a audiencista, fala no WhatsApp (wa.me) e envia o arquivo (Z-API).
 *  👩‍⚖️ Audiencistas (cadastro): áreas de abrangência, tipos, valor médio, depósito.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('audiencistas');
require_once __DIR__ . '/../../core/functions_zapi.php';

$pdo = db();
$pageTitle = 'Audiencistas';
$AUD_CANAL = '24'; // canal Z-API que envia arquivo/contata a audiencista

// ── Self-heal ────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audiencistas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(150) NOT NULL, telefone VARCHAR(30) NULL,
        email VARCHAR(190) NULL, areas TEXT NULL, tipos TEXT NULL, valor_medio_cents INT NULL,
        dados_deposito TEXT NULL, observacoes TEXT NULL, ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audiencias (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tipo VARCHAR(80) NOT NULL, data_hora DATETIME NULL,
        comarca VARCHAR(160) NULL, client_id INT UNSIGNED NULL, case_id INT UNSIGNED NULL,
        processo_numero VARCHAR(40) NULL, orientacoes TEXT NULL, audiencista_id INT UNSIGNED NULL,
        valor_cents INT NULL, arquivo_nome VARCHAR(255) NULL, arquivo_path VARCHAR(255) NULL,
        arquivo_mime VARCHAR(80) NULL, arquivo_enviado_em DATETIME NULL,
        status ENUM('aberta','designada','realizada','cancelada') NOT NULL DEFAULT 'aberta',
        created_by INT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Self-heal v2 (24/06/2026): substabelecimento, pagamento, avaliacao, integracao agenda.
// ALTERs idempotentes em try/catch individual — falham silenciosos se coluna ja existe.
$_audAlters = array(
    "ALTER TABLE audiencistas ADD COLUMN oab VARCHAR(30) NULL",
    "ALTER TABLE audiencias ADD COLUMN substab_nome VARCHAR(255) NULL",
    "ALTER TABLE audiencias ADD COLUMN substab_path VARCHAR(255) NULL",
    "ALTER TABLE audiencias ADD COLUMN substab_mime VARCHAR(80) NULL",
    "ALTER TABLE audiencias ADD COLUMN substab_enviado_em DATETIME NULL",
    "ALTER TABLE audiencias ADD COLUMN pago_em DATETIME NULL",
    "ALTER TABLE audiencias ADD COLUMN pago_valor_cents INT NULL",
    "ALTER TABLE audiencias ADD COLUMN pago_forma VARCHAR(40) NULL",
    "ALTER TABLE audiencias ADD COLUMN pago_comprovante_path VARCHAR(255) NULL",
    "ALTER TABLE audiencias ADD COLUMN pago_comprovante_nome VARCHAR(255) NULL",
    "ALTER TABLE audiencias ADD COLUMN pago_comprovante_mime VARCHAR(80) NULL",
    "ALTER TABLE audiencias ADD COLUMN avaliacao_nota TINYINT NULL",
    "ALTER TABLE audiencias ADD COLUMN avaliacao_comentario TEXT NULL",
    "ALTER TABLE audiencias ADD COLUMN avaliacao_em DATETIME NULL",
    "ALTER TABLE audiencias ADD COLUMN avaliacao_por INT UNSIGNED NULL",
    "ALTER TABLE audiencias ADD COLUMN agenda_evento_id INT UNSIGNED NULL",
    "ALTER TABLE audiencias ADD COLUMN modalidade VARCHAR(20) NULL",
    "ALTER TABLE audiencias ADD COLUMN local VARCHAR(250) NULL",
    "ALTER TABLE audiencias ADD COLUMN tipo_processo VARCHAR(80) NULL",
);
foreach ($_audAlters as $_sql) { try { $pdo->exec($_sql); } catch (Exception $e) {} }

$TIPOS = array('AIJ (Instrução e Julgamento)', 'Audiência inicial', 'Conciliação', 'Mediação / CEJUSC',
               'Audiência una', 'Justificação', 'Custódia', 'Juizado Especial', 'Outra');
$STATUS = array('aberta' => 'Aberta', 'designada' => 'Designada', 'realizada' => 'Realizada', 'cancelada' => 'Cancelada');

function aud_wa_link($telefone, $msg = '')
{
    $d = preg_replace('/\D/', '', (string)$telefone);
    if ($d === '') return '';
    if (substr($d, 0, 2) !== '55') $d = '55' . $d;
    return 'https://wa.me/' . $d . ($msg !== '' ? '?text=' . rawurlencode($msg) : '');
}

/**
 * Link pra abrir conversa DENTRO do Hub (módulo WhatsApp, canal 24).
 * Usa o deep-link ?telefone=&canal=24 que o /whatsapp/ resolve sozinho
 * (acha a conversa existente ou prepara pra criar). Amanda prefere não sair
 * do Hub pra falar com audiencista — antes abria wa.me e ia pro WhatsApp Web.
 */
function aud_hub_wa_link($telefone, $msg = '')
{
    $d = preg_replace('/\D/', '', (string)$telefone);
    if ($d === '') return '';
    if (substr($d, 0, 2) !== '55') $d = '55' . $d;
    $q = 'canal=24&telefone=' . $d;
    if ($msg !== '') $q .= '&texto=' . rawurlencode($msg);
    return url('modules/whatsapp/') . '?' . $q;
}
function aud_money($cents) { return $cents !== null && $cents !== '' ? 'R$ ' . number_format($cents / 100, 2, ',', '.') : '—'; }

/**
 * Upload generico p/ pasta /files/audiencias com prefixo. Retorna [nome_original,
 * stored, mime] ou null se nada subiu / erro silenciado pelo callsite.
 */
function aud_upload_file($field, $prefix, $maxMB = 25)
{
    if (empty($_FILES[$field]) || (int)$_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $tmp = $_FILES[$field]['tmp_name']; $nome = $_FILES[$field]['name'];
    $mime = $_FILES[$field]['type'] ?: (function_exists('mime_content_type') ? mime_content_type($tmp) : 'application/octet-stream');
    $tam = (int)$_FILES[$field]['size'];
    $allowed = array('application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                     'image/png','image/jpeg','image/jpg','application/zip','application/x-zip-compressed');
    if ($tam > $maxMB * 1024 * 1024) return array('erro' => 'Arquivo maior que ' . $maxMB . 'MB.');
    if (!in_array($mime, $allowed, true)) return array('erro' => 'Formato nao permitido (PDF, DOC, imagem ou ZIP).');
    $dir = APP_ROOT . '/files/audiencias'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $nome);
    $stored = $prefix . '_' . uniqid('', true) . '_' . $safe;
    if (!move_uploaded_file($tmp, $dir . '/' . $stored)) return array('erro' => 'Falha ao mover upload.');
    @chmod($dir . '/' . $stored, 0644);
    return array('nome' => $nome, 'path' => $stored, 'mime' => $mime);
}

/**
 * Notifica o Luiz Eduardo (user_id=6) da nova solicitação de audiencista:
 *  1) notify() in-Hub (sino)
 *  2) tarefa em case_tasks vinculada ao caso (pra ele acompanhar)
 *  3) e-mail via Brevo (HTML simples)
 */
function aud_notificar_luiz($pdo, $audId)
{
    $LUIZ_ID    = 6;
    $LUIZ_EMAIL = 'luizeduardo.sa.adv@gmail.com';

    $st = $pdo->prepare("SELECT au.*, cl.name AS client_name, c.title AS case_title, c.case_number
                         FROM audiencias au
                         LEFT JOIN clients cl ON cl.id = au.client_id
                         LEFT JOIN cases c ON c.id = au.case_id
                         WHERE au.id = ?");
    $st->execute(array($audId));
    $a = $st->fetch();
    if (!$a) return;

    $titulo = '👩‍⚖️ Nova solicitação de audiencista';
    $resumo = $a['tipo']
            . ($a['data_hora'] ? ' em ' . date('d/m/Y H:i', strtotime($a['data_hora'])) : '')
            . ($a['comarca'] ? ' — ' . $a['comarca'] : '')
            . ($a['client_name'] ? ' (cliente: ' . $a['client_name'] . ')' : '')
            . ($a['modalidade'] ? ' [' . $a['modalidade'] . ']' : '');
    $link = url('modules/audiencistas/');

    // 1) Notificação in-Hub
    if (function_exists('notify')) {
        try { notify($LUIZ_ID, $titulo, $resumo, 'info', $link, '👩‍⚖️'); } catch (Exception $e) {}
    }

    // 2) Tarefa em case_tasks vinculada ao caso (se houver case_id)
    if (!empty($a['case_id'])) {
        try {
            $pdo->prepare("INSERT INTO case_tasks (case_id, title, status, assigned_to, sort_order, created_at)
                           VALUES (?, ?, 'pendente', ?, 0, NOW())")
                ->execute(array((int)$a['case_id'], '👩‍⚖️ Acompanhar solicitação de audiencista: ' . $resumo, $LUIZ_ID));
        } catch (Exception $e) {}
    }

    // 3) E-mail Brevo
    try {
        $cfg = array('key' => '', 'email' => 'contato@ferreiraesa.com.br', 'name' => 'Ferreira & Sá Advocacia');
        foreach ($pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll() as $r) {
            if ($r['chave'] === 'brevo_api_key')      $cfg['key']   = $r['valor'];
            if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_name')  $cfg['name']  = $r['valor'];
        }
        if (!$cfg['key']) return;
        $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $linhas = '<tr><td><b>Tipo:</b></td><td>' . $esc($a['tipo']) . '</td></tr>'
                . ($a['data_hora'] ? '<tr><td><b>Data/Hora:</b></td><td>' . $esc(date('d/m/Y H:i', strtotime($a['data_hora']))) . '</td></tr>' : '')
                . ($a['modalidade'] ? '<tr><td><b>Modalidade:</b></td><td>' . $esc(ucfirst($a['modalidade'])) . '</td></tr>' : '')
                . ($a['comarca']    ? '<tr><td><b>Comarca:</b></td><td>'    . $esc($a['comarca']) . '</td></tr>' : '')
                . ($a['local']      ? '<tr><td><b>Local:</b></td><td>'      . $esc($a['local'])   . '</td></tr>' : '')
                . ($a['tipo_processo']  ? '<tr><td><b>Tipo de processo:</b></td><td>' . $esc($a['tipo_processo']) . '</td></tr>' : '')
                . ($a['client_name'] ? '<tr><td><b>Cliente:</b></td><td>'   . $esc($a['client_name']) . '</td></tr>' : '')
                . ($a['case_title']  ? '<tr><td><b>Caso:</b></td><td>'      . $esc($a['case_title']) . ($a['case_number'] ? ' (' . $esc($a['case_number']) . ')' : '') . '</td></tr>' : '')
                . ($a['orientacoes'] ? '<tr><td valign="top"><b>Orientações:</b></td><td>' . nl2br($esc($a['orientacoes'])) . '</td></tr>' : '');
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;background:#fff;border-radius:10px;padding:24px;border:1px solid #e5e7eb;">'
              . '<h2 style="margin:0 0 4px;color:#0f3d3e;">👩‍⚖️ Nova solicitação de audiencista</h2>'
              . '<p style="color:#6b7280;font-size:13px;margin:0 0 16px;">Foi aberta uma nova solicitação para você acompanhar.</p>'
              . '<table style="font-size:14px;color:#111;border-collapse:collapse;width:100%;">' . $linhas . '</table>'
              . '<p style="margin-top:18px;"><a href="' . $esc($link) . '" style="background:#0f3d3e;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;">Abrir Audiencistas</a></p>'
              . '</div>';
        $data = array(
            'sender'      => array('name' => $cfg['name'], 'email' => $cfg['email']),
            'to'          => array(array('email' => $LUIZ_EMAIL, 'name' => 'Luiz Eduardo')),
            'subject'     => $titulo . ' — ' . $a['tipo'],
            'htmlContent' => $html,
        );
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array('api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {}
}

/**
 * Registra andamento PRIVADO (visivel_cliente=0) na pasta do caso informando
 * quem foi designada pra audiência. Não aparece na Central VIP — só internamente.
 * Idempotente por (case_id, audiencista_id) — não duplica se já tem andamento dessa
 * dupla nas últimas 24h (evita criar várias linhas se a tela é salva 2x sem mudar).
 */
function aud_registrar_andamento_designacao($pdo, $audId)
{
    $st = $pdo->prepare("SELECT au.*, ad.nome AS aud_nome, ad.oab AS aud_oab
                         FROM audiencias au LEFT JOIN audiencistas ad ON ad.id=au.audiencista_id
                         WHERE au.id=?");
    $st->execute(array($audId));
    $a = $st->fetch();
    if (!$a || empty($a['case_id']) || empty($a['audiencista_id'])) return false;

    // dedup: já existe andamento dessa dupla case+audiencista nas últimas 24h?
    try {
        $dup = $pdo->prepare("SELECT id FROM case_andamentos
                              WHERE case_id = ? AND tipo = 'audiencia'
                                AND descricao LIKE ?
                                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                              LIMIT 1");
        $dup->execute(array((int)$a['case_id'], '%audiencista_id=' . (int)$a['audiencista_id'] . '%'));
        if ($dup->fetchColumn()) return false;
    } catch (Exception $e) {}

    $linha = '👩‍⚖️ Audiência *' . $a['tipo'] . '*'
           . ($a['data_hora'] ? ' em ' . date('d/m/Y H:i', strtotime($a['data_hora'])) : '')
           . ($a['comarca'] ? ' (' . $a['comarca'] . ')' : '')
           . ' — designada para: ' . $a['aud_nome']
           . ($a['aud_oab'] ? ' (OAB ' . $a['aud_oab'] . ')' : '')
           . "\n[interno · audiencista_id=" . (int)$a['audiencista_id'] . ']';
    try {
        $pdo->prepare("INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, visivel_cliente, created_by, created_at)
                       VALUES (?, CURDATE(), 'audiencia', ?, 0, ?, NOW())")
            ->execute(array((int)$a['case_id'], $linha, current_user_id()));
        return true;
    } catch (Exception $e) { return false; }
}

/**
 * Cria/atualiza evento na agenda quando uma audiencia tem audiencista designada
 * + data marcada. Retorna o agenda_evento_id (existente ou novo).
 *
 * Fluxo: ao designar (ou redesignar) uma audiencista, o evento e criado/atualizado
 * pra que apareca na agenda da equipe com lembrete. O audiencista nao tem acesso —
 * e so visibilidade interna.
 */
function aud_sync_agenda($pdo, $audId)
{
    $st = $pdo->prepare("SELECT au.*, ad.nome AS aud_nome FROM audiencias au LEFT JOIN audiencistas ad ON ad.id=au.audiencista_id WHERE au.id=?");
    $st->execute(array($audId));
    $a = $st->fetch();
    if (!$a || empty($a['data_hora']) || empty($a['audiencista_id'])) return null;

    $titulo = '👩‍⚖️ Audiência (' . $a['tipo'] . ')'
            . ($a['aud_nome'] ? ' — corresp.: ' . $a['aud_nome'] : '');
    $desc = ($a['comarca'] ? '📍 ' . $a['comarca'] . "\n" : '')
          . ($a['processo_numero'] ? '📄 ' . $a['processo_numero'] . "\n" : '')
          . ($a['orientacoes'] ? "\nOrientações:\n" . $a['orientacoes'] : '');
    $fim = date('Y-m-d H:i:s', strtotime($a['data_hora']) + 3600); // 1h default

    if (!empty($a['agenda_evento_id'])) {
        try {
            $pdo->prepare("UPDATE agenda_eventos SET titulo=?, descricao=?, data_inicio=?, data_fim=?, case_id=?, client_id=? WHERE id=?")
                ->execute(array($titulo, $desc, $a['data_hora'], $fim, $a['case_id'], $a['client_id'], $a['agenda_evento_id']));
        } catch (Exception $e) {}
        return (int)$a['agenda_evento_id'];
    }
    try {
        $pdo->prepare("INSERT INTO agenda_eventos (tipo, titulo, descricao, data_inicio, data_fim, case_id, client_id, status, created_by)
                       VALUES ('audiencia', ?, ?, ?, ?, ?, ?, 'agendado', ?)")
            ->execute(array($titulo, $desc, $a['data_hora'], $fim, $a['case_id'], $a['client_id'], current_user_id()));
        $eid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE audiencias SET agenda_evento_id=? WHERE id=?")->execute(array($eid, $audId));
        return $eid;
    } catch (Exception $e) { return null; }
}

// ── AJAX: cliente / casos ────────────────────────────────
if (($_GET['ajax'] ?? '') === 'buscar_cliente') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? ''); if (mb_strlen($q) < 2) { echo '[]'; exit; }
    $st = $pdo->prepare("SELECT id, name, cpf FROM clients WHERE name LIKE ? ORDER BY name LIMIT 15");
    $st->execute(array('%' . $q . '%'));
    echo json_encode($st->fetchAll()); exit;
}
if (($_GET['ajax'] ?? '') === 'buscar_casos') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)($_GET['client_id'] ?? 0); if (!$cid) { echo '[]'; exit; }
    $st = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE client_id = ? ORDER BY created_at DESC LIMIT 40");
    $st->execute(array($cid));
    echo json_encode($st->fetchAll()); exit;
}
// Próxima audiência agendada de um case. Usado pra pré-preencher data/hora
// quando Amanda clica em "Solicitar audiencista" na pasta do caso.
// Filtro: SÓ audiencia (e mediacao_cejusc, que tambem e audiencia). Balcao Virtual
// e tarefas/reunioes ficam de fora — voce nao manda audiencista pra balcao virtual.
if (($_GET['ajax'] ?? '') === 'proxima_audiencia') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)($_GET['case_id'] ?? 0); if (!$cid) { echo '{}'; exit; }
    try {
        $st = $pdo->prepare("SELECT data_inicio, titulo, tipo, modalidade, local
                             FROM agenda_eventos
                             WHERE case_id = ?
                               AND tipo IN ('audiencia','mediacao_cejusc')
                               AND data_inicio >= NOW()
                               AND (status IS NULL OR status NOT IN ('cancelado','realizado','concluido'))
                             ORDER BY data_inicio ASC LIMIT 1");
        $st->execute(array($cid));
        $r = $st->fetch();
        echo json_encode($r ?: array());
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── Download de arquivos (autenticado) ───────────────────
// Tipos: processo (default), substab, comprovante (selecionado por ?tipo=)
if (isset($_GET['baixar'])) {
    $id = (int)$_GET['baixar'];
    $tipo = $_GET['tipo'] ?? 'processo';
    $colPath = 'arquivo_path'; $colNome = 'arquivo_nome'; $colMime = 'arquivo_mime'; $defaultName = 'processo';
    if ($tipo === 'substab')      { $colPath = 'substab_path'; $colNome = 'substab_nome'; $colMime = 'substab_mime'; $defaultName = 'substabelecimento'; }
    elseif ($tipo === 'comprov')  { $colPath = 'pago_comprovante_path'; $colNome = 'pago_comprovante_nome'; $colMime = 'pago_comprovante_mime'; $defaultName = 'comprovante'; }
    $st = $pdo->prepare("SELECT $colPath AS p, $colNome AS n, $colMime AS m FROM audiencias WHERE id = ?");
    $st->execute(array($id));
    $row = $st->fetch();
    $path = $row && $row['p'] ? APP_ROOT . '/files/audiencias/' . basename($row['p']) : '';
    if (!$path || !is_file($path)) { http_response_code(404); die('Arquivo não encontrado.'); }
    header('Content-Type: ' . ($row['m'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/', '_', $row['n'] ?: $defaultName) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path); exit;
}

// ── POST handlers ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error', 'Sessão expirada — tente de novo.'); redirect(module_url('audiencistas')); }
    $acao = $_POST['acao'] ?? '';

    // -- salvar/editar audiencista --
    if ($acao === 'salvar_audiencista') {
        $id    = (int)($_POST['id'] ?? 0);
        $nome  = clean_str($_POST['nome'] ?? '', 150);
        $oab   = clean_str($_POST['oab'] ?? '', 30);
        $tel   = clean_str($_POST['telefone'] ?? '', 30);
        $email = clean_str($_POST['email'] ?? '', 190);
        $areas = clean_str($_POST['areas'] ?? '', 1000);
        $tipos = isset($_POST['tipos']) && is_array($_POST['tipos']) ? implode(', ', array_map('strval', $_POST['tipos'])) : '';
        $valor = ($_POST['valor'] ?? '') !== '' ? (int) round(((float)str_replace(',', '.', $_POST['valor'])) * 100) : null;
        $dep   = clean_str($_POST['dados_deposito'] ?? '', 1000);
        $obs   = clean_str($_POST['observacoes'] ?? '', 1000);
        if ($nome === '') { flash_set('error', 'Informe o nome da audiencista.'); redirect(module_url('audiencistas') . '#cad'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE audiencistas SET nome=?, oab=?, telefone=?, email=?, areas=?, tipos=?, valor_medio_cents=?, dados_deposito=?, observacoes=? WHERE id=?")
                ->execute(array($nome, $oab, $tel, $email, $areas, $tipos, $valor, $dep, $obs, $id));
            audit_log('audiencista_editar', 'audiencista', $id, $nome);
            flash_set('success', 'Audiencista atualizada.');
        } else {
            $pdo->prepare("INSERT INTO audiencistas (nome, oab, telefone, email, areas, tipos, valor_medio_cents, dados_deposito, observacoes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute(array($nome, $oab, $tel, $email, $areas, $tipos, $valor, $dep, $obs, current_user_id()));
            audit_log('audiencista_criar', 'audiencista', (int)$pdo->lastInsertId(), $nome);
            flash_set('success', 'Audiencista cadastrada! 🎉');
        }
        redirect(module_url('audiencistas') . '#audiencistas');
    }

    if ($acao === 'toggle_audiencista') {
        $id = (int)($_POST['id'] ?? 0); $novo = !empty($_POST['ativar']) ? 1 : 0;
        $pdo->prepare("UPDATE audiencistas SET ativo=? WHERE id=?")->execute(array($novo, $id));
        flash_set('success', $novo ? 'Audiencista reativada.' : 'Audiencista arquivada.');
        redirect(module_url('audiencistas') . '#audiencistas');
    }

    // -- nova audiência (com upload do processo) --
    if ($acao === 'salvar_audiencia') {
        $tipo = clean_str($_POST['tipo'] ?? '', 80);
        $comarca = clean_str($_POST['comarca'] ?? '', 160);
        $dataHora = trim($_POST['data_hora'] ?? '');
        $dataVal = $dataHora && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $dataHora) ? str_replace('T', ' ', $dataHora) . ':00' : null;
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        $caseId   = (int)($_POST['case_id'] ?? 0) ?: null;
        $procNum  = clean_str($_POST['processo_numero'] ?? '', 40);
        $orient   = clean_str($_POST['orientacoes'] ?? '', 4000);
        $audId    = (int)($_POST['audiencista_id'] ?? 0) ?: null;
        if ($tipo === '') { flash_set('error', 'Escolha o tipo de audiência.'); redirect(module_url('audiencistas') . '#nova'); }

        // upload (opcional)
        $aNome = $aPath = $aMime = null;
        if (!empty($_FILES['arquivo']) && (int)$_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['arquivo']['tmp_name']; $nome = $_FILES['arquivo']['name'];
            $mime = $_FILES['arquivo']['type'] ?: (function_exists('mime_content_type') ? mime_content_type($tmp) : 'application/octet-stream');
            $tam = (int)$_FILES['arquivo']['size'];
            $allowed = array('application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                             'image/png','image/jpeg','image/jpg','application/zip','application/x-zip-compressed');
            if ($tam > 25 * 1024 * 1024) { flash_set('error', 'Arquivo maior que 25MB.'); redirect(module_url('audiencistas') . '#nova'); }
            if (!in_array($mime, $allowed, true)) { flash_set('error', 'Formato não permitido (PDF, DOC, imagem ou ZIP).'); redirect(module_url('audiencistas') . '#nova'); }
            $dir = APP_ROOT . '/files/audiencias'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $nome);
            $stored = 'aud_' . uniqid('', true) . '_' . $safe;
            if (move_uploaded_file($tmp, $dir . '/' . $stored)) { @chmod($dir . '/' . $stored, 0644); $aNome = $nome; $aPath = $stored; $aMime = $mime; }
        }
        $status = $audId ? 'designada' : 'aberta';
        $valor = ($_POST['valor'] ?? '') !== '' ? (int) round(((float)str_replace(',', '.', $_POST['valor'])) * 100) : null;
        $modalidade   = clean_str($_POST['modalidade'] ?? '', 20);
        $local        = clean_str($_POST['local'] ?? '', 250);
        $tipoProcesso = clean_str($_POST['tipo_processo'] ?? '', 80);
        $pdo->prepare("INSERT INTO audiencias (tipo, data_hora, comarca, client_id, case_id, processo_numero, orientacoes, audiencista_id, valor_cents, arquivo_nome, arquivo_path, arquivo_mime, status, created_by, modalidade, local, tipo_processo)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array($tipo, $dataVal, $comarca, $clientId, $caseId, $procNum ?: null, $orient ?: null, $audId, $valor, $aNome, $aPath, $aMime, $status, current_user_id(), $modalidade ?: null, $local ?: null, $tipoProcesso ?: null));
        $novoId = (int)$pdo->lastInsertId();
        audit_log('audiencia_criar', 'audiencia', $novoId, $tipo);

        // Se já veio com audiencista designada, cria evento na agenda + andamento privado.
        if ($audId) {
            aud_sync_agenda($pdo, $novoId);
            aud_registrar_andamento_designacao($pdo, $novoId);
        }

        // Toda solicitação avisa o Luiz Eduardo (notify + tarefa + e-mail Brevo).
        aud_notificar_luiz($pdo, $novoId);

        // Sem audiencista designada → avisa a equipe pra contatar e contratar.
        if (!$audId && function_exists('notify_gestao')) {
            notify_gestao('👩‍⚖️ Solicitação de audiencista',
                $tipo . ($comarca ? ' — ' . $comarca : '') . ($dataVal ? ' em ' . date('d/m/Y H:i', strtotime($dataVal)) : '')
                . '. Contatar uma audiencista pra verificar disponibilidade e contratar.',
                'pendencia', url('modules/audiencistas/'), '👩‍⚖️');
        }

        flash_set('success', 'Audiência registrada! ' . ($audId ? 'Audiencista já designada.' : 'A equipe foi avisada pra contatar uma audiencista.'));
        $vc = (int)($_POST['voltar_caso'] ?? 0);
        redirect($vc ? module_url('operacional', 'caso_ver.php?id=' . $vc) : module_url('audiencistas'));
    }

    // -- designar audiencista a uma audiência --
    if ($acao === 'designar') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        $audId = (int)($_POST['audiencista_id'] ?? 0) ?: null;
        $valor = ($_POST['valor'] ?? '') !== '' ? (int) round(((float)str_replace(',', '.', $_POST['valor'])) * 100) : null;

        // Audiencista anterior pra detectar mudança e logar troca no andamento
        $prev = (int)$pdo->query("SELECT audiencista_id FROM audiencias WHERE id=" . (int)$aid)->fetchColumn();
        $pdo->prepare("UPDATE audiencias SET audiencista_id=?, valor_cents=COALESCE(?,valor_cents), status=IF(status='aberta','designada',status) WHERE id=?")
            ->execute(array($audId, $valor, $aid));
        audit_log('audiencia_designar', 'audiencia', $aid, 'audiencista=' . $audId);
        aud_sync_agenda($pdo, $aid); // cria/atualiza evento na agenda da equipe
        if ($audId && $audId !== $prev) {
            aud_registrar_andamento_designacao($pdo, $aid); // andamento PRIVADO na pasta do caso
        }
        flash_set('success', 'Audiencista designada.');
        redirect(module_url('audiencistas'));
    }

    // -- editar uma audiencia existente (campos principais) --
    if ($acao === 'editar_audiencia') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        if (!$aid) { redirect(module_url('audiencistas')); }
        $tipo = clean_str($_POST['tipo'] ?? '', 80);
        $comarca = clean_str($_POST['comarca'] ?? '', 160);
        $dataHora = trim($_POST['data_hora'] ?? '');
        $dataVal = $dataHora && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $dataHora) ? str_replace('T', ' ', $dataHora) . ':00' : null;
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        $caseId   = (int)($_POST['case_id'] ?? 0) ?: null;
        $procNum  = clean_str($_POST['processo_numero'] ?? '', 40);
        $orient   = clean_str($_POST['orientacoes'] ?? '', 4000);
        $audIdNew = (int)($_POST['audiencista_id'] ?? 0) ?: null;
        if ($tipo === '') { flash_set('error', 'Tipo de audiência é obrigatório.'); redirect(module_url('audiencistas')); }

        $prev = (int)$pdo->query("SELECT audiencista_id FROM audiencias WHERE id=" . $aid)->fetchColumn();
        $valor = ($_POST['valor'] ?? '') !== '' ? (int) round(((float)str_replace(',', '.', $_POST['valor'])) * 100) : null;
        // valor_cents: se veio campo preenchido, usa; senão preserva o atual (não zera)
        $pdo->prepare("UPDATE audiencias SET tipo=?, data_hora=?, comarca=?, client_id=?, case_id=?, processo_numero=?, orientacoes=?, audiencista_id=?, valor_cents=COALESCE(?, valor_cents), status=IF(status='aberta' AND ? IS NOT NULL,'designada',status) WHERE id=?")
            ->execute(array($tipo, $dataVal, $comarca, $clientId, $caseId, $procNum ?: null, $orient ?: null, $audIdNew, $valor, $audIdNew, $aid));
        audit_log('audiencia_editar', 'audiencia', $aid, $tipo);
        aud_sync_agenda($pdo, $aid);
        if ($audIdNew && $audIdNew !== $prev) {
            aud_registrar_andamento_designacao($pdo, $aid);
        }
        flash_set('success', 'Audiência atualizada.');
        redirect(module_url('audiencistas'));
    }

    // -- excluir audiencia --
    if ($acao === 'excluir_audiencia') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        if (!$aid) { redirect(module_url('audiencistas')); }
        // remove evento da agenda vinculado, se houver
        try {
            $eid = (int)$pdo->query("SELECT agenda_evento_id FROM audiencias WHERE id=" . $aid)->fetchColumn();
            if ($eid) $pdo->prepare("DELETE FROM agenda_eventos WHERE id=?")->execute(array($eid));
        } catch (Exception $e) {}
        $pdo->prepare("DELETE FROM audiencias WHERE id=?")->execute(array($aid));
        audit_log('audiencia_excluir', 'audiencia', $aid);
        flash_set('success', 'Audiência removida.');
        redirect(module_url('audiencistas'));
    }

    // -- upload do substabelecimento --
    if ($acao === 'upload_substab') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        $up = aud_upload_file('substab', 'substab', 25);
        if (!$up) { flash_set('error', 'Selecione o arquivo do substabelecimento.'); redirect(module_url('audiencistas')); }
        if (!empty($up['erro'])) { flash_set('error', $up['erro']); redirect(module_url('audiencistas')); }
        $pdo->prepare("UPDATE audiencias SET substab_nome=?, substab_path=?, substab_mime=? WHERE id=?")
            ->execute(array($up['nome'], $up['path'], $up['mime'], $aid));
        audit_log('audiencia_upload_substab', 'audiencia', $aid, $up['nome']);
        flash_set('success', 'Substabelecimento anexado. 📜');
        redirect(module_url('audiencistas'));
    }

    // -- enviar substab pelo WhatsApp da audiencista --
    if ($acao === 'enviar_substab') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        $st = $pdo->prepare("SELECT au.*, ad.nome AS aud_nome, ad.telefone AS aud_tel
                             FROM audiencias au LEFT JOIN audiencistas ad ON ad.id = au.audiencista_id WHERE au.id = ?");
        $st->execute(array($aid));
        $a = $st->fetch();
        if (!$a || empty($a['audiencista_id']) || empty($a['aud_tel'])) {
            flash_set('error', 'Designe uma audiencista (com WhatsApp) antes de enviar.');
        } elseif (empty($a['substab_path'])) {
            flash_set('error', 'Anexe o substabelecimento antes de enviar.');
        } else {
            $pub = url('files/audiencias/' . $a['substab_path']);
            $cap = "📜 Substabelecimento — " . $a['tipo']
                 . ($a['data_hora'] ? ' em ' . date('d/m/Y H:i', strtotime($a['data_hora'])) : '')
                 . ($a['comarca'] ? ' (' . $a['comarca'] . ')' : '')
                 . ($a['orientacoes'] ? "\n\nOrientações: " . $a['orientacoes'] : '');
            $res = zapi_send_document($AUD_CANAL, $a['aud_tel'], $pub, $a['substab_nome'] ?: 'substabelecimento.pdf', $cap);
            if (!empty($res['ok'])) {
                $pdo->prepare("UPDATE audiencias SET substab_enviado_em = NOW() WHERE id = ?")->execute(array($aid));
                audit_log('audiencia_enviar_substab', 'audiencia', $aid, 'audiencista=' . $a['audiencista_id']);
                flash_set('success', 'Substabelecimento enviado! 📨');
            } else {
                flash_set('error', 'Falhou o envio: ' . (isset($res['erro']) ? $res['erro'] : '?'));
            }
        }
        redirect(module_url('audiencistas'));
    }

    // -- marcar como pago --
    if ($acao === 'marcar_pago') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        $valor = ($_POST['valor_pago'] ?? '') !== '' ? (int) round(((float)str_replace(',', '.', $_POST['valor_pago'])) * 100) : null;
        $forma = clean_str($_POST['forma'] ?? '', 40);
        $dt = trim($_POST['pago_em'] ?? '');
        $dtVal = $dt && preg_match('/^\d{4}-\d{2}-\d{2}/', $dt) ? str_replace('T', ' ', $dt) . (strpos($dt, 'T') !== false ? ':00' : ' 12:00:00') : date('Y-m-d H:i:s');

        // upload comprovante (opcional)
        $cpNome = $cpPath = $cpMime = null;
        if (!empty($_FILES['comprovante']) && (int)$_FILES['comprovante']['error'] === UPLOAD_ERR_OK) {
            $up = aud_upload_file('comprovante', 'compr', 10);
            if (!empty($up['erro'])) { flash_set('error', $up['erro']); redirect(module_url('audiencistas')); }
            if ($up) { $cpNome = $up['nome']; $cpPath = $up['path']; $cpMime = $up['mime']; }
        }
        $pdo->prepare("UPDATE audiencias SET pago_em=?, pago_valor_cents=COALESCE(?, valor_cents), pago_forma=?,
                       pago_comprovante_nome=COALESCE(?, pago_comprovante_nome),
                       pago_comprovante_path=COALESCE(?, pago_comprovante_path),
                       pago_comprovante_mime=COALESCE(?, pago_comprovante_mime) WHERE id=?")
            ->execute(array($dtVal, $valor, $forma ?: null, $cpNome, $cpPath, $cpMime, $aid));
        audit_log('audiencia_marcar_pago', 'audiencia', $aid, ($valor ? aud_money($valor) : '') . ($forma ? ' · ' . $forma : ''));
        flash_set('success', 'Pagamento registrado. ✅');
        redirect(!empty($_POST['voltar_detalhe']) ? module_url('audiencistas', 'detalhe.php?id=' . (int)$_POST['voltar_detalhe']) : module_url('audiencistas'));
    }

    // -- desfazer pagamento (engano) --
    if ($acao === 'desfazer_pago') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        $pdo->prepare("UPDATE audiencias SET pago_em=NULL, pago_valor_cents=NULL, pago_forma=NULL,
                       pago_comprovante_nome=NULL, pago_comprovante_path=NULL, pago_comprovante_mime=NULL WHERE id=?")
            ->execute(array($aid));
        audit_log('audiencia_desfazer_pago', 'audiencia', $aid);
        flash_set('success', 'Pagamento desmarcado.');
        redirect(!empty($_POST['voltar_detalhe']) ? module_url('audiencistas', 'detalhe.php?id=' . (int)$_POST['voltar_detalhe']) : module_url('audiencistas'));
    }

    // -- avaliar audiencista pela audiencia realizada --
    if ($acao === 'avaliar') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        $nota = (int)($_POST['nota'] ?? 0);
        if ($nota < 1 || $nota > 5) { flash_set('error', 'Nota deve ser de 1 a 5.'); redirect(module_url('audiencistas')); }
        $coment = clean_str($_POST['comentario'] ?? '', 1000);
        $pdo->prepare("UPDATE audiencias SET avaliacao_nota=?, avaliacao_comentario=?, avaliacao_em=NOW(), avaliacao_por=? WHERE id=?")
            ->execute(array($nota, $coment ?: null, current_user_id(), $aid));
        audit_log('audiencia_avaliar', 'audiencia', $aid, $nota . '★');
        flash_set('success', 'Avaliação salva. ⭐');
        redirect(!empty($_POST['voltar_detalhe']) ? module_url('audiencistas', 'detalhe.php?id=' . (int)$_POST['voltar_detalhe']) : module_url('audiencistas'));
    }

    // -- mudar status --
    if ($acao === 'status') {
        $aid = (int)($_POST['audiencia_id'] ?? 0); $st = $_POST['novo'] ?? '';
        if (isset($STATUS[$st])) {
            $pdo->prepare("UPDATE audiencias SET status=? WHERE id=?")->execute(array($st, $aid));
            audit_log('audiencia_status', 'audiencia', $aid, '→ ' . $st);
            flash_set('success', 'Status atualizado.');
        }
        $vc = (int)($_POST['voltar_caso'] ?? 0);
        redirect($vc ? module_url('operacional', 'caso_ver.php?id=' . $vc) : module_url('audiencistas'));
    }

    // -- enviar arquivo pro WhatsApp da audiencista (Z-API) --
    if ($acao === 'enviar_arquivo') {
        $aid = (int)($_POST['audiencia_id'] ?? 0);
        $st = $pdo->prepare("SELECT au.*, ad.nome AS aud_nome, ad.telefone AS aud_tel
                             FROM audiencias au LEFT JOIN audiencistas ad ON ad.id = au.audiencista_id WHERE au.id = ?");
        $st->execute(array($aid));
        $a = $st->fetch();
        if (!$a || empty($a['audiencista_id']) || empty($a['aud_tel'])) {
            flash_set('error', 'Designe uma audiencista (com WhatsApp) antes de enviar.');
        } elseif (empty($a['arquivo_path'])) {
            flash_set('error', 'Não há arquivo do processo anexado nessa audiência.');
        } else {
            $pub = url('files/audiencias/' . $a['arquivo_path']);
            $cap = "📎 Processo para audiência — " . $a['tipo']
                 . ($a['data_hora'] ? ' em ' . date('d/m/Y H:i', strtotime($a['data_hora'])) : '')
                 . ($a['comarca'] ? ' (' . $a['comarca'] . ')' : '')
                 . ($a['orientacoes'] ? "\n\nOrientações: " . $a['orientacoes'] : '');
            $res = zapi_send_document($AUD_CANAL, $a['aud_tel'], $pub, $a['arquivo_nome'] ?: 'processo.pdf', $cap);
            if (!empty($res['ok'])) {
                $pdo->prepare("UPDATE audiencias SET arquivo_enviado_em = NOW() WHERE id = ?")->execute(array($aid));
                audit_log('audiencia_enviar_arquivo', 'audiencia', $aid, 'audiencista=' . $a['audiencista_id']);
                flash_set('success', 'Arquivo enviado pro WhatsApp da ' . $a['aud_nome'] . '! 📨');
            } else {
                flash_set('error', 'Falhou o envio: ' . (isset($res['erro']) ? $res['erro'] : '?'));
            }
        }
        redirect(module_url('audiencistas'));
    }

    redirect(module_url('audiencistas'));
}

// ── Dados ────────────────────────────────────────────────
$audiencistas = $pdo->query("SELECT * FROM audiencistas ORDER BY ativo DESC, nome ASC")->fetchAll();
$audAtivas = array_filter($audiencistas, function ($a) { return (int)$a['ativo'] === 1; });

$audiencias = $pdo->query("SELECT au.*, ad.nome AS aud_nome, ad.telefone AS aud_tel,
                                  cl.name AS client_name, c.case_number, c.title AS case_title
                           FROM audiencias au
                           LEFT JOIN audiencistas ad ON ad.id = au.audiencista_id
                           LEFT JOIN clients cl ON cl.id = au.client_id
                           LEFT JOIN cases c ON c.id = au.case_id
                           ORDER BY (au.status IN ('realizada','cancelada')) ASC, au.created_at DESC LIMIT 500")->fetchAll();

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.au-tabs { display:flex; gap:.25rem; margin-bottom:1.2rem; border-bottom:2px solid var(--border,#e5e7eb); flex-wrap:wrap; }
.au-tab { background:none; border:none; padding:.65rem 1.1rem; font-size:.9rem; font-weight:600; color:#6b7280; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; }
.au-tab:hover { color:#0f3d3e; background:#f8fafc; }
.au-tab.active { color:#b87333; border-bottom-color:#b87333; }
.au-pane { display:none; }
.au-pane.active { display:block; }
.au-card { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:14px; }
.au-form label { display:block; font-size:.78rem; font-weight:600; color:#444; margin:0 0 4px; }
.au-form .row { margin-bottom:13px; }
.au-input, .au-select, .au-text { width:100%; border:1px solid #ddd; border-radius:8px; padding:8px 10px; font-size:.9rem; font-family:inherit; }
.au-text { min-height:64px; resize:vertical; }
.au-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.au-tipos { display:flex; flex-wrap:wrap; gap:6px 14px; }
.au-tipos label { display:flex; align-items:center; gap:6px; font-weight:500; font-size:.85rem; }
.au-btn { background:#0f3d3e; color:#fff; border:none; border-radius:8px; padding:9px 16px; font-weight:700; cursor:pointer; font-size:.9rem; }
.au-btn.ghost { background:#fff; color:#0f3d3e; border:1px solid #0f3d3e; }
.au-chip { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.72rem; font-weight:700; }
.au-st-aberta { background:#fef3c7; color:#92400e; } .au-st-designada { background:#dbeafe; color:#1e40af; }
.au-st-realizada { background:#dcfce7; color:#15803d; } .au-st-cancelada { background:#fee2e2; color:#b91c1c; }
.au-results { position:relative; }
.au-rbox { position:absolute; z-index:30; left:0; right:0; background:#fff; border:1px solid #ddd; border-radius:0 0 8px 8px; max-height:220px; overflow:auto; display:none; box-shadow:0 6px 14px rgba(0,0,0,.08); }
.au-rbox div { padding:8px 10px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:.86rem; }
.au-rbox div:hover { background:#eef6f4; }
.au-sel { display:inline-flex; align-items:center; gap:8px; background:#e8f3f1; color:#0f3d3e; padding:4px 11px; border-radius:999px; font-weight:600; font-size:.82rem; margin-top:6px; }
.au-sel button { background:none; border:none; color:#0f3d3e; cursor:pointer; }
.au-tag { display:inline-block; background:#eef1f4; color:#445; border-radius:6px; padding:1px 7px; font-size:.72rem; margin:1px; }
.au-empty { text-align:center; padding:40px; color:#999; }
.au-acard { border-left:4px solid #b87333; }
.au-mini { background:#0f3d3e; color:#fff; border:none; border-radius:7px; padding:5px 10px; font-weight:600; cursor:pointer; font-size:.78rem; text-decoration:none; display:inline-block; }
.au-mini.wa { background:#25d366; } .au-mini.gh { background:#fff; color:#0f3d3e; border:1px solid #cfd8d6; }
</style>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
  <div>
    <h1 style="margin:0;">👩‍⚖️ Audiencistas</h1>
    <p style="color:#777;margin:4px 0 0;">Contrate correspondentes pra audiências: cadastre a demanda, anexe o processo, fale e envie pro WhatsApp.</p>
  </div>
</div>

<div class="au-tabs">
  <button type="button" class="au-tab active" data-pane="audiencias" onclick="auTab(this)">📋 Audiências a contratar <span style="opacity:.6;">(<?= count($audiencias) ?>)</span></button>
  <button type="button" class="au-tab" data-pane="audiencistas" onclick="auTab(this)">👩‍⚖️ Audiencistas <span style="opacity:.6;">(<?= count($audAtivas) ?>)</span></button>
</div>

<!-- ===== ABA AUDIÊNCIAS ===== -->
<div class="au-pane active" id="pane-audiencias">
  <details class="au-card" id="nova" style="max-width:820px;" <?= isset($_GET['nova']) ? 'open' : '' ?>>
    <summary style="font-weight:700;color:#0f3d3e;cursor:pointer;" id="auNovaSummary">➕ Nova audiência a contratar</summary>
    <form class="au-form" id="auAudienciaForm" method="post" action="<?= module_url('audiencistas') ?>" enctype="multipart/form-data" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="acao" id="auAudienciaAcao" value="salvar_audiencia">
      <input type="hidden" name="audiencia_id" id="auAudienciaId" value="">
      <input type="hidden" name="client_id" id="auClientId">
      <input type="hidden" name="case_id" id="auCaseId">
      <div class="au-grid2">
        <div class="row"><label>Tipo de audiência *</label>
          <select class="au-select" name="tipo" id="auTipo" required>
            <option value="">Selecione…</option>
            <?php foreach ($TIPOS as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="row"><label>Data e hora</label><input type="datetime-local" class="au-input" name="data_hora" id="auDataHora"></div>
      </div>
      <div class="au-grid2">
        <div class="row"><label>Comarca / Local</label><input type="text" class="au-input" name="comarca" id="auComarca" placeholder="Ex: Niterói/RJ — 2ª Vara Cível"></div>
        <div class="row"><label>Nº do processo</label><input type="text" class="au-input" name="processo_numero" id="auProcNum" placeholder="CNJ (opcional)"></div>
      </div>
      <div class="row"><label>Cliente (opcional — vincula ao processo)</label>
        <div class="au-results"><input type="text" class="au-input" id="auBuscaCli" placeholder="Digite o nome do cliente…" autocomplete="off" onkeyup="auBuscarCli(this.value)"><div class="au-rbox" id="auCliBox"></div></div>
        <div id="auCliSel"></div>
        <div id="auCasoWrap" style="display:none;margin-top:8px;"><label>Processo do cliente</label>
          <select class="au-select" id="auCaseSel" onchange="auOnSelCase(this.value);"><option value="">—</option></select>
          <div id="auProxAud" style="display:none;font-size:.78rem;color:#0c4a6e;background:#e0f2fe;border-radius:6px;padding:5px 9px;margin-top:5px;"></div>
        </div>
      </div>
      <div class="row"><label>Orientações para a audiencista</label>
        <textarea class="au-text" name="orientacoes" id="auOrientacoes" placeholder="Pontos de atenção, teses, o que pedir/evitar, contato do cliente, etc."></textarea>
      </div>
      <div class="au-grid2">
        <div class="row"><label>Designar audiencista (opcional)</label>
          <select class="au-select" name="audiencista_id" id="auAudienciaSel" onchange="auAtualizarValorHint()">
            <option value="">— designar depois —</option>
            <?php foreach ($audAtivas as $aA): ?><option value="<?= (int)$aA['id'] ?>" data-valor="<?= $aA['valor_medio_cents'] !== null ? (int)$aA['valor_medio_cents'] : '' ?>"><?= e($aA['nome']) ?><?= $aA['areas'] ? ' · ' . e(mb_substr($aA['areas'], 0, 40)) : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="row"><label>Valor acordado (R$)</label>
          <input type="text" class="au-input" name="valor" id="auValor" placeholder="Ex: 350,00">
          <div id="auValorHint" style="font-size:.74rem;color:#64748b;margin-top:3px;">deixe vazio pra usar o valor médio da audiencista</div>
        </div>
      </div>
      <div class="row" id="auArquivoWrap"><label>Arquivo do processo (PDF/DOC/ZIP, até 25MB)</label><input type="file" class="au-input" name="arquivo" accept=".pdf,.doc,.docx,.zip,image/*"></div>
      <button type="submit" class="au-btn" id="auAudienciaBtn">➕ Registrar audiência</button>
      <button type="button" class="au-btn ghost" onclick="auResetAudienciaForm()" id="auAudienciaCancel" style="display:none;">Cancelar edição</button>
    </form>
  </details>

  <?php if (!$audiencias): ?>
    <div class="au-empty">Nenhuma audiência cadastrada ainda. Crie a primeira acima. 👆</div>
  <?php else: foreach ($audiencias as $a):
    $proc = $a['case_number'] ?: ($a['processo_numero'] ?: ($a['case_title'] ?: '—'));
    $waMsg = "Olá! Tudo bem? Temos uma audiência (" . $a['tipo'] . ")"
           . ($a['data_hora'] ? ' em ' . date('d/m/Y H:i', strtotime($a['data_hora'])) : '')
           . ($a['comarca'] ? ' na comarca de ' . $a['comarca'] : '') . ". Você teria disponibilidade?";
    $wa = $a['aud_tel'] ? aud_hub_wa_link($a['aud_tel'], $waMsg) : '';
  ?>
  <div class="au-card au-acard">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;">
      <div>
        <div style="font-weight:700;color:#0f3d3e;font-size:1rem;">⚖️ <?= e($a['tipo']) ?>
          <span class="au-chip au-st-<?= e($a['status']) ?>"><?= e($STATUS[$a['status']] ?? $a['status']) ?></span>
          <?php if ($a['substab_path']): ?>
            <span class="au-chip" style="background:<?= $a['substab_enviado_em'] ? '#dcfce7;color:#15803d' : '#fef3c7;color:#92400e' ?>;">📜 substab<?= $a['substab_enviado_em'] ? ' enviado' : '' ?></span>
          <?php endif; ?>
          <?php if ($a['pago_em']): ?>
            <span class="au-chip" style="background:#dcfce7;color:#15803d;">💰 pago <?= aud_money($a['pago_valor_cents']) ?></span>
          <?php endif; ?>
          <?php if ($a['avaliacao_nota']): ?>
            <span class="au-chip" style="background:#fff4e0;color:#b9770e;">⭐ <?= (int)$a['avaliacao_nota'] ?>/5</span>
          <?php endif; ?>
        </div>
        <div style="color:#666;font-size:.85rem;margin-top:3px;">
          <?= $a['data_hora'] ? '📅 ' . date('d/m/Y H:i', strtotime($a['data_hora'])) . ' · ' : '' ?>
          <?= $a['comarca'] ? '📍 ' . e($a['comarca']) . ' · ' : '' ?>
          📄 <?= e($proc) ?><?= $a['client_name'] ? ' · 👤 ' . e($a['client_name']) : '' ?>
        </div>
        <?php if ($a['orientacoes']): ?><div style="margin-top:6px;font-size:.85rem;color:#444;background:#fafafa;border-radius:8px;padding:8px 10px;white-space:pre-wrap;"><?= e($a['orientacoes']) ?></div><?php endif; ?>
      </div>
      <div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap;">
        <form method="post" style="margin:0;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="status"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
          <select class="au-select" name="novo" onchange="this.form.submit()" style="width:auto;font-size:.8rem;padding:5px 8px;">
            <?php foreach ($STATUS as $k => $lbl): ?><option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
          </select>
        </form>
        <button type="button" class="au-mini gh" style="padding:5px 9px;font-size:.74rem;"
          onclick='auEditAudienc(<?= json_encode(array(
            "id" => (int)$a["id"],
            "tipo" => $a["tipo"],
            "data_hora" => $a["data_hora"] ? str_replace(" ", "T", substr($a["data_hora"], 0, 16)) : "",
            "comarca" => $a["comarca"],
            "processo_numero" => $a["processo_numero"],
            "client_id" => (int)$a["client_id"],
            "client_name" => $a["client_name"],
            "case_id" => (int)$a["case_id"],
            "case_title" => $a["case_title"],
            "orientacoes" => $a["orientacoes"],
            "audiencista_id" => (int)$a["audiencista_id"],
            "valor_cents" => $a["valor_cents"],
          ), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>✏️ Editar</button>
        <form method="post" style="margin:0;" onsubmit="return confirm('Excluir esta audiência? Não dá pra desfazer.\n\nPagamentos e avaliações registrados serão perdidos.');">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="acao" value="excluir_audiencia">
          <input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
          <button type="submit" class="au-mini gh" style="padding:5px 9px;font-size:.74rem;color:#b91c1c;border-color:#fecaca;">🗑️ Excluir</button>
        </form>
      </div>
    </div>

    <div style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-top:1px solid #f0f0f0;padding-top:10px;">
      <?php if ($a['audiencista_id']): ?>
        <span style="font-size:.85rem;">👩‍⚖️ <a href="<?= module_url('audiencistas', 'detalhe.php?id=' . (int)$a['audiencista_id']) ?>" style="color:#0f3d3e;font-weight:700;text-decoration:none;"><?= e($a['aud_nome']) ?></a><?= $a['valor_cents'] !== null ? ' · ' . aud_money($a['valor_cents']) : '' ?></span>
        <?php if ($wa): ?><a class="au-mini wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">💬 WhatsApp</a><?php endif; ?>
        <?php if ($a['arquivo_path']): ?>
          <a class="au-mini gh" href="?baixar=<?= (int)$a['id'] ?>" target="_blank" rel="noopener">📄 Ver processo</a>
          <form method="post" style="margin:0;display:inline;" onsubmit="return confirm('Enviar o arquivo do processo no WhatsApp da <?= e(addslashes($a['aud_nome'])) ?>?');">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="enviar_arquivo"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="au-mini">📨 Enviar processo<?= $a['arquivo_enviado_em'] ? ' (reenviar)' : '' ?></button>
          </form>
          <?php if ($a['arquivo_enviado_em']): ?><span style="font-size:.74rem;color:#15803d;">✓ enviado <?= date('d/m H:i', strtotime($a['arquivo_enviado_em'])) ?></span><?php endif; ?>
        <?php else: ?><span style="font-size:.78rem;color:#999;">sem arquivo anexado</span><?php endif; ?>
      <?php else: ?>
        <form method="post" style="margin:0;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="designar"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
          <select class="au-select" name="audiencista_id" required style="width:auto;font-size:.82rem;padding:6px 8px;">
            <option value="">Designar audiencista…</option>
            <?php foreach ($audAtivas as $ad): ?><option value="<?= (int)$ad['id'] ?>"><?= e($ad['nome']) ?></option><?php endforeach; ?>
          </select>
          <input type="text" name="valor" class="au-input" placeholder="Valor R$" style="width:110px;font-size:.82rem;padding:6px 8px;">
          <button type="submit" class="au-mini">Designar</button>
          <?php if ($a['arquivo_path']): ?><a class="au-mini gh" href="?baixar=<?= (int)$a['id'] ?>" target="_blank" rel="noopener">📄 Ver processo</a><?php endif; ?>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($a['audiencista_id']): // só faz sentido quando ja tem audiencista ?>
    <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
      <!-- 📜 Substabelecimento -->
      <details style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;flex:1;min-width:240px;">
        <summary style="font-size:.84rem;font-weight:700;color:#475569;cursor:pointer;">📜 Substabelecimento <?= $a['substab_path'] ? '✓' : '' ?></summary>
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
          <a class="au-mini" style="background:#7c3aed;" href="<?= module_url('audiencistas', 'gerar_substab.php?audiencia_id=' . (int)$a['id']) ?>">✨ Gerar automático</a>
          <?php if ($a['substab_path']): ?>
            <a class="au-mini gh" href="?baixar=<?= (int)$a['id'] ?>&tipo=substab" target="_blank" rel="noopener">📄 Ver substab</a>
            <form method="post" style="margin:0;display:inline;" onsubmit="return confirm('Enviar o substabelecimento no WhatsApp da <?= e(addslashes($a['aud_nome'])) ?>?');">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="enviar_substab"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
              <button type="submit" class="au-mini">📨 Enviar<?= $a['substab_enviado_em'] ? ' (reenviar)' : '' ?></button>
            </form>
            <?php if ($a['substab_enviado_em']): ?><span style="font-size:.74rem;color:#15803d;">✓ enviado <?= date('d/m H:i', strtotime($a['substab_enviado_em'])) ?></span><?php endif; ?>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="upload_substab"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
            <input type="file" name="substab" required accept=".pdf,.doc,.docx,image/*" style="font-size:.78rem;">
            <button type="submit" class="au-mini gh"><?= $a['substab_path'] ? '🔁 Substituir' : '📤 Anexar assinado' ?></button>
          </form>
        </div>
      </details>

      <!-- 💰 Pagamento -->
      <details style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;flex:1;min-width:260px;">
        <summary style="font-size:.84rem;font-weight:700;color:#475569;cursor:pointer;">💰 Pagamento <?= $a['pago_em'] ? '✓' : '' ?></summary>
        <?php if ($a['pago_em']): ?>
          <div style="margin-top:8px;font-size:.82rem;color:#15803d;">
            ✅ Pago em <?= date('d/m/Y', strtotime($a['pago_em'])) ?>
            <?= $a['pago_valor_cents'] !== null ? ' — <strong>' . aud_money($a['pago_valor_cents']) . '</strong>' : '' ?>
            <?= $a['pago_forma'] ? ' via ' . e($a['pago_forma']) : '' ?>
            <?php if ($a['pago_comprovante_path']): ?> · <a href="?baixar=<?= (int)$a['id'] ?>&tipo=comprov" target="_blank" rel="noopener" style="color:#0f3d3e;">📎 comprovante</a><?php endif; ?>
          </div>
          <form method="post" style="margin-top:6px;" onsubmit="return confirm('Desmarcar pagamento desta audiência?');">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="desfazer_pago"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="au-mini gh" style="font-size:.72rem;">↩️ Desfazer pagamento</button>
          </form>
        <?php else: ?>
          <form method="post" enctype="multipart/form-data" style="margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:6px;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="marcar_pago"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
            <input type="date" name="pago_em" value="<?= date('Y-m-d') ?>" class="au-input" style="font-size:.82rem;padding:5px 7px;">
            <input type="text" name="valor_pago" placeholder="Valor pago (R$)" class="au-input" value="<?= $a['valor_cents'] !== null ? number_format($a['valor_cents']/100, 2, '.', '') : '' ?>" style="font-size:.82rem;padding:5px 7px;">
            <select name="forma" class="au-select" style="font-size:.82rem;padding:5px 7px;">
              <option value="">— forma —</option>
              <option value="PIX">PIX</option>
              <option value="Transferência">Transferência</option>
              <option value="Dinheiro">Dinheiro</option>
              <option value="Outro">Outro</option>
            </select>
            <input type="file" name="comprovante" accept=".pdf,image/*" style="font-size:.78rem;">
            <button type="submit" class="au-mini" style="grid-column:1/-1;">✅ Marcar como pago</button>
          </form>
        <?php endif; ?>
      </details>

      <!-- ⭐ Avaliação -->
      <details style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;flex:1;min-width:240px;">
        <summary style="font-size:.84rem;font-weight:700;color:#475569;cursor:pointer;">⭐ Avaliação <?= $a['avaliacao_nota'] ? '✓ ' . (int)$a['avaliacao_nota'] . '/5' : '' ?></summary>
        <form method="post" style="margin-top:8px;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="avaliar"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
          <div style="display:flex;gap:10px;font-size:1.1rem;align-items:center;">
            <?php for ($n = 1; $n <= 5; $n++): ?>
              <label style="cursor:pointer;"><input type="radio" name="nota" value="<?= $n ?>" <?= (int)$a['avaliacao_nota'] === $n ? 'checked' : '' ?> style="vertical-align:middle;"> <?= $n ?>⭐</label>
            <?php endfor; ?>
          </div>
          <textarea name="comentario" placeholder="Comentário (opcional): pontualidade, postura, comunicação…" class="au-text" style="margin-top:6px;min-height:50px;font-size:.84rem;"><?= e($a['avaliacao_comentario'] ?? '') ?></textarea>
          <button type="submit" class="au-mini" style="margin-top:5px;"><?= $a['avaliacao_nota'] ? 'Atualizar avaliação' : '⭐ Salvar avaliação' ?></button>
          <?php if ($a['avaliacao_em']): ?><span style="font-size:.74rem;color:#666;margin-left:6px;">avaliada em <?= date('d/m/Y', strtotime($a['avaliacao_em'])) ?></span><?php endif; ?>
        </form>
      </details>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- ===== ABA AUDIENCISTAS ===== -->
<div class="au-pane" id="pane-audiencistas">
  <details class="au-card" id="cad" style="max-width:820px;">
    <summary style="font-weight:700;color:#0f3d3e;cursor:pointer;" id="cadSummary">➕ Cadastrar audiencista</summary>
    <form class="au-form" method="post" action="<?= module_url('audiencistas') ?>" style="margin-top:12px;" id="auCadForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="acao" value="salvar_audiencista">
      <input type="hidden" name="id" id="auEditId" value="">
      <div class="au-grid2">
        <div class="row"><label>Nome *</label><input type="text" class="au-input" name="nome" id="fNome" required></div>
        <div class="row"><label>OAB</label><input type="text" class="au-input" name="oab" id="fOab" placeholder="Ex: RJ 123.456"></div>
      </div>
      <div class="au-grid2">
        <div class="row"><label>WhatsApp</label><input type="text" class="au-input" name="telefone" id="fTel" placeholder="Ex: 21999998888"></div>
        <div class="row"><label>E-mail</label><input type="email" class="au-input" name="email" id="fEmail"></div>
      </div>
      <div class="au-grid2">
        <div class="row"><label>Valor médio cobrado (R$)</label><input type="number" step="0.01" min="0" class="au-input" name="valor" id="fValor"></div>
        <div class="row"></div>
      </div>
      <div class="row"><label>Áreas de abrangência</label><input type="text" class="au-input" name="areas" id="fAreas" placeholder="Ex: Niterói, São Gonçalo, Região dos Lagos / RJ"></div>
      <div class="row"><label>Tipos de audiência que participa</label>
        <div class="au-tipos">
          <?php foreach ($TIPOS as $t): ?><label><input type="checkbox" class="fTipo" name="tipos[]" value="<?= e($t) ?>"> <?= e($t) ?></label><?php endforeach; ?>
        </div>
      </div>
      <div class="row"><label>Dados para depósito (PIX / banco)</label><textarea class="au-text" name="dados_deposito" id="fDep" placeholder="Ex: PIX (CPF): 000.000.000-00 — Banco X, Ag 0000, CC 00000-0"></textarea></div>
      <div class="row"><label>Observações</label><textarea class="au-text" name="observacoes" id="fObs"></textarea></div>
      <button type="submit" class="au-btn">💾 Salvar audiencista</button>
      <button type="button" class="au-btn ghost" onclick="auResetForm()" id="auCancelEdit" style="display:none;">Cancelar edição</button>
    </form>
  </details>

  <?php if (!$audiencistas): ?>
    <div class="au-empty">Nenhuma audiencista cadastrada. Cadastre a primeira acima. 👆</div>
  <?php else: foreach ($audiencistas as $a):
    $tiposArr = $a['tipos'] ? array_map('trim', explode(',', $a['tipos'])) : array();
    $wa = $a['telefone'] ? aud_hub_wa_link($a['telefone']) : '';
  ?>
  <div class="au-card" style="<?= (int)$a['ativo'] !== 1 ? 'opacity:.55;' : '' ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;">
      <div>
        <div style="font-weight:700;color:#0f3d3e;font-size:1rem;">👩‍⚖️ <?= e($a['nome']) ?>
          <?php if ((int)$a['ativo'] !== 1): ?><span class="au-chip au-st-cancelada">arquivada</span><?php endif; ?></div>
        <div style="color:#666;font-size:.84rem;margin-top:3px;">
          <?= !empty($a['oab']) ? '⚖️ OAB ' . e($a['oab']) . ' · ' : '' ?>
          <?= $a['telefone'] ? '📱 ' . e($a['telefone']) : '' ?><?= $a['email'] ? ' · ✉️ ' . e($a['email']) : '' ?>
          <?= $a['valor_medio_cents'] !== null ? ' · 💰 ' . aud_money($a['valor_medio_cents']) . ' (médio)' : '' ?>
        </div>
        <?php if ($a['areas']): ?><div style="font-size:.84rem;margin-top:5px;">🗺️ <strong>Áreas:</strong> <?= e($a['areas']) ?></div><?php endif; ?>
        <?php if ($tiposArr): ?><div style="margin-top:5px;"><?php foreach ($tiposArr as $t): ?><span class="au-tag"><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if ($a['dados_deposito']): ?><div style="font-size:.82rem;margin-top:5px;color:#444;">🏦 <?= e($a['dados_deposito']) ?></div><?php endif; ?>
        <?php if ($a['observacoes']): ?><div style="font-size:.82rem;margin-top:4px;color:#777;"><?= e($a['observacoes']) ?></div><?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <a class="au-mini" href="<?= module_url('audiencistas', 'detalhe.php?id=' . (int)$a['id']) ?>">📊 Detalhe / Acerto</a>
        <?php if ($wa): ?><a class="au-mini wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">💬 WhatsApp</a><?php endif; ?>
        <button type="button" class="au-mini gh" onclick='auEdit(<?= json_encode(array("id"=>(int)$a["id"],"nome"=>$a["nome"],"oab"=>($a["oab"]??""),"telefone"=>$a["telefone"],"email"=>$a["email"],"areas"=>$a["areas"],"tipos"=>$a["tipos"],"valor"=>($a["valor_medio_cents"]!==null?number_format($a["valor_medio_cents"]/100,2,".",""):""),"dep"=>$a["dados_deposito"],"obs"=>$a["observacoes"]), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>✏️ Editar</button>
        <form method="post" style="margin:0;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="toggle_audiencista"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="ativar" value="<?= (int)$a['ativo'] === 1 ? '0' : '1' ?>"><button type="submit" class="au-mini gh"><?= (int)$a['ativo'] === 1 ? '🗄️ Arquivar' : '♻️ Reativar' ?></button></form>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<script>
var AU_URL = '<?= module_url('audiencistas') ?>';
function auTab(el){
  document.querySelectorAll('.au-tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.au-pane').forEach(function(p){p.classList.remove('active');});
  el.classList.add('active'); document.getElementById('pane-'+el.dataset.pane).classList.add('active');
}
(function(){ var h=(location.hash||'').replace('#',''); if(h==='audiencistas'||h==='cad'){ var b=document.querySelector('.au-tab[data-pane="audiencistas"]'); if(b) auTab(b); } })();

var auT=null;
function auBuscarCli(q){
  clearTimeout(auT); var box=document.getElementById('auCliBox');
  if(q.length<2){box.style.display='none';return;}
  auT=setTimeout(function(){
    fetch(AU_URL+'?ajax=buscar_cliente&q='+encodeURIComponent(q)).then(function(r){return r.json();}).then(function(arr){
      var html=''; arr.forEach(function(c){ html+='<div onclick="auSelCli('+c.id+',&quot;'+(c.name||'').replace(/"/g,'')+'&quot;)">'+(c.name||'')+(c.cpf?' <span style=&quot;color:#999&quot;>· '+c.cpf+'</span>':'')+'</div>'; });
      box.innerHTML=html||'<div style="color:#999;cursor:default;">Nenhum</div>'; box.style.display='block';
    });
  },250);
}
function auSelCli(id,name){
  document.getElementById('auClientId').value=id; document.getElementById('auBuscaCli').value='';
  document.getElementById('auCliBox').style.display='none';
  document.getElementById('auCliSel').innerHTML='<span class="au-sel">👤 '+name+' <button type="button" onclick="auLimparCli()">×</button></span>';
  var w=document.getElementById('auCasoWrap'), sel=document.getElementById('auCaseSel');
  w.style.display='block'; sel.innerHTML='<option value="">Carregando…</option>';
  fetch(AU_URL+'?ajax=buscar_casos&client_id='+id).then(function(r){return r.json();}).then(function(arr){
    var html='<option value="">— sem vincular —</option>'; arr.forEach(function(c){ var l=(c.case_number?c.case_number+' — ':'')+(c.title||('Caso #'+c.id)); html+='<option value="'+c.id+'">'+l.replace(/</g,'')+'</option>'; });
    sel.innerHTML=html;
  });
}
function auLimparCli(){ document.getElementById('auClientId').value=''; document.getElementById('auCaseId').value=''; document.getElementById('auCliSel').innerHTML=''; document.getElementById('auCasoWrap').style.display='none'; document.getElementById('auProxAud').style.display='none'; }

/**
 * Ao escolher o processo do cliente, busca a próxima audiência agendada
 * dele em agenda_eventos e pré-preenche data/hora (se ainda estiver vazia).
 * Mostra um chip com o que foi achado pra Amanda confirmar/sobrescrever.
 */
function auOnSelCase(cid){
  document.getElementById('auCaseId').value=cid;
  var box=document.getElementById('auProxAud');
  if(!cid){ box.style.display='none'; return; }
  box.style.display='block'; box.textContent='Buscando próxima audiência agendada…';
  fetch(AU_URL+'?ajax=proxima_audiencia&case_id='+cid).then(function(r){return r.json();}).then(function(d){
    if(d && d.data_inicio){
      var dt=d.data_inicio.replace(' ','T').substring(0,16);
      var campoData=document.getElementById('auDataHora');
      if(!campoData.value){ campoData.value=dt; box.innerHTML='📅 <strong>Data preenchida da agenda:</strong> '+formatarDT(dt)+' — '+ (d.titulo||''); }
      else { box.innerHTML='ℹ️ Existe audiência na agenda em '+formatarDT(dt)+' — '+ (d.titulo||'') +' <a href="javascript:void(0)" onclick="document.getElementById(\'auDataHora\').value=\''+dt+'\';this.parentElement.innerHTML=\'📅 Usando '+formatarDT(dt)+'\';">usar essa data</a>'; }
    } else {
      box.style.display='none';
    }
  }).catch(function(){ box.style.display='none'; });
}
function formatarDT(s){ if(!s) return ''; var p=s.split('T'); var d=p[0].split('-'); return d[2]+'/'+d[1]+'/'+d[0]+' '+(p[1]||''); }

/** Mostra o valor médio da audiencista selecionada como dica embaixo do campo Valor. */
function auAtualizarValorHint(){
  var sel=document.getElementById('auAudienciaSel'); var hint=document.getElementById('auValorHint');
  if(!sel||!hint) return;
  var v=sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].getAttribute('data-valor') : '';
  if(!v){ hint.textContent='deixe vazio pra usar o valor médio da audiencista'; return; }
  var n=parseInt(v,10)/100;
  hint.innerHTML='💰 Valor médio dessa audiencista: <strong>R$ '+n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})+'</strong> — deixe vazio pra usar esse valor';
}

/** Preenche o form de "Nova audiência" com dados pra edição. */
function auEditAudienc(d){
  document.getElementById('auAudienciaAcao').value='editar_audiencia';
  document.getElementById('auAudienciaId').value=d.id;
  document.getElementById('auTipo').value=d.tipo||'';
  document.getElementById('auDataHora').value=d.data_hora||'';
  document.getElementById('auComarca').value=d.comarca||'';
  document.getElementById('auProcNum').value=d.processo_numero||'';
  document.getElementById('auOrientacoes').value=d.orientacoes||'';
  document.getElementById('auAudienciaSel').value=d.audiencista_id||'';
  document.getElementById('auValor').value=(d.valor_cents!=null && d.valor_cents!=='') ? (parseInt(d.valor_cents,10)/100).toString().replace('.',',') : '';
  auAtualizarValorHint();
  // cliente + processo
  auLimparCli();
  if(d.client_id){
    document.getElementById('auClientId').value=d.client_id;
    document.getElementById('auCliSel').innerHTML='<span class="au-sel">👤 '+(d.client_name||('#'+d.client_id))+' <button type="button" onclick="auLimparCli()">×</button></span>';
    var w=document.getElementById('auCasoWrap'), sel=document.getElementById('auCaseSel');
    w.style.display='block';
    if(d.case_id){
      document.getElementById('auCaseId').value=d.case_id;
      sel.innerHTML='<option value="'+d.case_id+'" selected>'+(d.case_title||('Caso #'+d.case_id))+'</option>';
    }
  }
  // arquivo: oculta input (não dá pra reupload em editar)
  var aw=document.getElementById('auArquivoWrap'); if(aw) aw.style.display='none';
  document.getElementById('auNovaSummary').textContent='✏️ Editando audiência #'+d.id;
  document.getElementById('auAudienciaBtn').textContent='💾 Salvar alterações';
  document.getElementById('auAudienciaCancel').style.display='inline-block';
  document.getElementById('nova').open=true;
  document.getElementById('nova').scrollIntoView({behavior:'smooth',block:'start'});
}
function auResetAudienciaForm(){
  document.getElementById('auAudienciaForm').reset();
  document.getElementById('auAudienciaAcao').value='salvar_audiencia';
  document.getElementById('auAudienciaId').value='';
  auLimparCli();
  var aw=document.getElementById('auArquivoWrap'); if(aw) aw.style.display='';
  document.getElementById('auNovaSummary').textContent='➕ Nova audiência a contratar';
  document.getElementById('auAudienciaBtn').textContent='➕ Registrar audiência';
  document.getElementById('auAudienciaCancel').style.display='none';
}

function auEdit(d){
  document.getElementById('auEditId').value=d.id;
  document.getElementById('fNome').value=d.nome||''; document.getElementById('fOab').value=d.oab||'';
  document.getElementById('fTel').value=d.telefone||'';
  document.getElementById('fEmail').value=d.email||''; document.getElementById('fAreas').value=d.areas||'';
  document.getElementById('fValor').value=d.valor||''; document.getElementById('fDep').value=d.dep||'';
  document.getElementById('fObs').value=d.obs||'';
  var tipos=(d.tipos||'').split(',').map(function(x){return x.trim();});
  document.querySelectorAll('.fTipo').forEach(function(c){ c.checked = tipos.indexOf(c.value)>=0; });
  document.getElementById('cadSummary').textContent='✏️ Editando: '+(d.nome||'');
  document.getElementById('auCancelEdit').style.display='inline-block';
  document.getElementById('cad').open=true;
  document.getElementById('cad').scrollIntoView({behavior:'smooth',block:'center'});
}
function auResetForm(){
  document.getElementById('auCadForm').reset(); document.getElementById('auEditId').value='';
  document.querySelectorAll('.fTipo').forEach(function(c){c.checked=false;});
  document.getElementById('cadSummary').textContent='➕ Cadastrar audiencista';
  document.getElementById('auCancelEdit').style.display='none';
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
