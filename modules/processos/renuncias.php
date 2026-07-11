<?php
/**
 * Renúncia / Desistência de processos.
 *
 * Fluxo: usuário escolhe cliente → vincula um processo (case) → marca se é
 * RENÚNCIA ou DESISTÊNCIA → escolhe o motivo → anexa o comprovante de
 * comunicação com o cliente. Ao registrar:
 *   - grava em `renuncias` (+ comprovante em /files/renuncias)
 *   - abre uma TAREFA (case_tasks tipo=juntar_documento) pro operacional juntar
 *     o pedido de renúncia/desistência na pasta do processo
 *   - notifica o responsável / gestão
 *
 * Abas: Registrar · Histórico · Métricas.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('processos_renuncias');

$pdo = db();
$pageTitle = 'Renúncia / Desistência';

// ── Self-heal da tabela ──────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS renuncias (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id INT UNSIGNED NOT NULL, client_id INT UNSIGNED NOT NULL,
        tipo ENUM('renuncia','desistencia') NOT NULL,
        motivo VARCHAR(40) NOT NULL, motivo_outro VARCHAR(300) NULL, observacao TEXT NULL,
        comprovante_nome VARCHAR(255) NULL, comprovante_path VARCHAR(255) NULL, comprovante_mime VARCHAR(80) NULL,
        task_id INT UNSIGNED NULL, created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case (case_id), INDEX idx_tipo (tipo), INDEX idx_motivo (motivo), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$TIPOS = array('renuncia' => 'Renúncia', 'desistencia' => 'Desistência');
$MOTIVOS = array(
    'inadimplencia' => 'Inadimplência prolongada',
    'cliente'       => 'Desistência pelo(a) cliente',
    'demitido'      => 'Demitido — sem educação',
    'sem_resposta'  => 'Ausência de resposta prolongada',
    'outro'         => 'Outro',
);

// ── AJAX: buscar cliente (nome ou CPF) ───────────────────
if (($_GET['ajax'] ?? '') === 'buscar_cliente') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo '[]'; exit; }
    $qd = preg_replace('/\D/', '', $q);
    if (strlen($qd) >= 3) {
        $st = $pdo->prepare("SELECT id, name, cpf, phone FROM clients
            WHERE name LIKE ? OR REPLACE(REPLACE(REPLACE(COALESCE(cpf,''),'.',''),'-',''),'/','') LIKE ?
            ORDER BY name LIMIT 15");
        $st->execute(array('%' . $q . '%', '%' . $qd . '%'));
    } else {
        $st = $pdo->prepare("SELECT id, name, cpf, phone FROM clients WHERE name LIKE ? ORDER BY name LIMIT 15");
        $st->execute(array('%' . $q . '%'));
    }
    echo json_encode($st->fetchAll());
    exit;
}

// ── AJAX: processos (cases) de um cliente ────────────────
if (($_GET['ajax'] ?? '') === 'buscar_casos') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)($_GET['client_id'] ?? 0);
    if (!$cid) { echo '[]'; exit; }
    $st = $pdo->prepare("SELECT id, title, case_number, case_type, status, drive_folder_url
                         FROM cases WHERE client_id = ? ORDER BY created_at DESC LIMIT 40");
    $st->execute(array($cid));
    echo json_encode($st->fetchAll());
    exit;
}

// ── Download do comprovante (autenticado) ────────────────
if (isset($_GET['baixar'])) {
    $id = (int)$_GET['baixar'];
    $st = $pdo->prepare("SELECT comprovante_path, comprovante_nome, comprovante_mime FROM renuncias WHERE id = ?");
    $st->execute(array($id));
    $row = $st->fetch();
    $path = $row && $row['comprovante_path'] ? APP_ROOT . '/files/renuncias/' . basename($row['comprovante_path']) : '';
    if (!$path || !is_file($path)) { http_response_code(404); die('Comprovante não encontrado.'); }
    header('Content-Type: ' . ($row['comprovante_mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/', '_', $row['comprovante_nome'] ?: 'comprovante') . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── POST: registrar renúncia/desistência ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'registrar') {
    if (!validate_csrf()) { flash_set('error', 'Sessão expirada — faça login e tente de novo.'); redirect(module_url('processos', 'renuncias.php')); }
    $clientId    = (int)($_POST['client_id'] ?? 0);
    $caseIds     = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : array();
    $caseIds     = array_values(array_unique(array_filter($caseIds)));
    $tipo        = isset($TIPOS[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : '';
    $motivo      = $_POST['motivo'] ?? '';
    $motivoOutro = clean_str($_POST['motivo_outro'] ?? '', 300);
    $obs         = clean_str($_POST['observacao'] ?? '', 2000);

    $err = '';
    $cases = array();
    if (!$clientId)                       $err = 'Selecione o cliente.';
    elseif (!$caseIds)                    $err = 'Selecione pelo menos um processo.';
    elseif (!$tipo)                       $err = 'Escolha renúncia ou desistência.';
    elseif (!isset($MOTIVOS[$motivo]))    $err = 'Escolha o motivo.';
    elseif ($motivo === 'outro' && $motivoOutro === '') $err = 'Descreva o "outro" motivo.';

    if (!$err) {
        // só os processos que realmente são desse cliente
        $ph = implode(',', array_fill(0, count($caseIds), '?'));
        $ck = $pdo->prepare("SELECT id, title, case_number, drive_folder_url, responsible_user_id
                             FROM cases WHERE client_id = ? AND id IN ($ph)");
        $ck->execute(array_merge(array($clientId), $caseIds));
        $cases = $ck->fetchAll();
        if (!$cases) $err = 'Os processos selecionados não pertencem a esse cliente.';
    }

    // comprovante (obrigatório)
    $cmpNome = $cmpPath = $cmpMime = null;
    if (!$err) {
        if (empty($_FILES['comprovante']) || (int)$_FILES['comprovante']['error'] === UPLOAD_ERR_NO_FILE) {
            $err = 'Anexe o comprovante de comunicação com o cliente.';
        } elseif ((int)$_FILES['comprovante']['error'] !== UPLOAD_ERR_OK) {
            $err = 'Falha no upload do comprovante (tente um arquivo menor).';
        } else {
            $tmp  = $_FILES['comprovante']['tmp_name'];
            $nome = $_FILES['comprovante']['name'];
            $mime = $_FILES['comprovante']['type'] ?: (function_exists('mime_content_type') ? mime_content_type($tmp) : 'application/octet-stream');
            $tam  = (int)$_FILES['comprovante']['size'];
            $allowed = array('image/png','image/jpeg','image/jpg','image/webp','image/gif','application/pdf',
                             'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            if ($tam > 10 * 1024 * 1024)            $err = 'Comprovante maior que 10MB.';
            elseif (!in_array($mime, $allowed, true)) $err = 'Formato não permitido. Use PDF, imagem (print) ou DOC.';
            else {
                $dir = APP_ROOT . '/files/renuncias';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $safe   = preg_replace('/[^A-Za-z0-9._-]/', '_', $nome);
                $stored = 'ren_' . $clientId . '_' . uniqid('', true) . '_' . $safe;
                if (move_uploaded_file($tmp, $dir . '/' . $stored)) {
                    @chmod($dir . '/' . $stored, 0644);
                    $cmpNome = $nome; $cmpPath = $stored; $cmpMime = $mime;
                } else {
                    $err = 'Não consegui salvar o comprovante. Tente de novo.';
                }
            }
        }
    }

    if ($err) { flash_set('error', $err); redirect(module_url('processos', 'renuncias.php') . '#registrar'); }

    // grava cada processo: registro + tarefa + comprovante na pasta do Drive
    $tipoLabel   = $TIPOS[$tipo];
    $motivoLabel = $motivo === 'outro' ? $motivoOutro : $MOTIVOS[$motivo];
    $insRen = $pdo->prepare("INSERT INTO renuncias
        (case_id, client_id, tipo, motivo, motivo_outro, observacao, comprovante_nome, comprovante_path, comprovante_mime, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
    $insTask = $pdo->prepare("INSERT INTO case_tasks
        (case_id, title, tipo, descricao, assigned_to, due_date, prioridade, status, sort_order, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $due    = date('Y-m-d', strtotime('+3 days'));
    $pubUrl = $cmpPath ? url('files/renuncias/' . $cmpPath) : '';
    if ($pubUrl && is_file(APP_ROOT . '/core/google_drive.php')) require_once APP_ROOT . '/core/google_drive.php';
    $n = 0;
    foreach ($cases as $case) {
        $cid = (int)$case['id'];
        $insRen->execute(array($cid, $clientId, $tipo, $motivo, $motivo === 'outro' ? $motivoOutro : null,
                               $obs !== '' ? $obs : null, $cmpNome, $cmpPath, $cmpMime, current_user_id()));
        $renId = (int)$pdo->lastInsertId();

        $procRef   = $case['case_number'] ? $case['case_number'] : $case['title'];
        $taskTitle = 'Peticionar ' . $tipoLabel . ' e juntar na pasta';
        $taskDesc  = 'Processo: ' . $procRef . '. Motivo: ' . $motivoLabel . '.'
                   . ($obs !== '' ? ' Obs: ' . $obs . '.' : '')
                   . ' O comprovante de comunicação com o cliente já está anexado no registro de ' . $tipoLabel
                   . '. Peticionar o pedido de ' . $tipoLabel . ' e juntar na pasta do processo no Drive.';
        $assignedTo = !empty($case['responsible_user_id']) ? (int)$case['responsible_user_id'] : null;
        // Tipo dedicado (nao mais 'juntar_documento') pra ficar visivel na ficha
        // do caso como banner destacado enquanto a renuncia nao for cumprida.
        $insTask->execute(array($cid, $taskTitle, $tipo, $taskDesc, $assignedTo, $due, 'alta', 'a_fazer', 0));
        $taskId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE renuncias SET task_id = ? WHERE id = ?")->execute(array($taskId, $renId));

        // salva o comprovante na pasta do processo no Drive (best-effort)
        if ($pubUrl && !empty($case['drive_folder_url']) && function_exists('upload_file_to_drive')) {
            try {
                $driveName = 'Comprovante_comunicacao_' . $tipoLabel . '_' . date('Ymd') . '_' . preg_replace('/[^\w.\-]/', '_', (string)$cmpNome);
                $up = upload_file_to_drive($case['drive_folder_url'], $driveName, $pubUrl, $cmpMime);
                if (empty($up['success'])) audit_log('renuncia_drive_falhou', 'case', $cid, substr((string)($up['error'] ?? '?'), 0, 180));
            } catch (Exception $e) {}
        }

        if ($assignedTo) {
            notify($assignedTo, '📌 Nova tarefa: ' . $taskTitle, $taskDesc, 'pendencia', url('modules/tarefas/?case_id=' . $cid), '📌');
        }
        audit_log('renuncia_registrada', 'case', $cid, $tipo . ' / ' . $motivo);
        $n++;
    }

    // cliente que renunciou/desistiu perde o acesso à Central VIP (automático)
    $vipOff = false;
    try {
        $uv = $pdo->prepare("UPDATE salavip_usuarios SET ativo = 0 WHERE cliente_id = ?");
        $uv->execute(array($clientId));
        $vipOff = $uv->rowCount() > 0;
        if ($vipOff) audit_log('desativar_salavip', 'client', $clientId, 'auto via renúncia/desistência');
    } catch (Exception $e) {}

    if (function_exists('notify_gestao')) {
        notify_gestao('Pedido de ' . $tipoLabel . ' registrado', $n . ' processo(s) — operacional precisa juntar o pedido na pasta.', 'pendencia', url('modules/tarefas/'), '📌');
    }

    flash_set('success', 'Registro de ' . $tipoLabel . ' salvo para ' . $n . ' processo(s). Comprovante anexado e tarefa aberta pro operacional em cada pasta.'
                       . ($vipOff ? ' A Central VIP do cliente foi desabilitada.' : ''));
    $vc = (int)($_POST['voltar_caso'] ?? 0);
    redirect($vc ? module_url('operacional', 'caso_ver.php?id=' . $vc) : module_url('processos', 'renuncias.php') . '#historico');
}

// ── POST: ligar/desligar Central VIP de um cliente ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_vip') {
    if (!validate_csrf()) { flash_set('error', 'Sessão expirada.'); redirect(module_url('processos', 'renuncias.php') . '#historico'); }
    if (has_min_role('gestao')) {
        $cid  = (int)($_POST['client_id'] ?? 0);
        $novo = !empty($_POST['ativar']) ? 1 : 0;
        $sv = $pdo->prepare("SELECT id FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
        $sv->execute(array($cid));
        if ($sv->fetch()) {
            $pdo->prepare("UPDATE salavip_usuarios SET ativo = ? WHERE cliente_id = ?")->execute(array($novo, $cid));
            audit_log($novo ? 'reativar_salavip' : 'desativar_salavip', 'client', $cid, 'via renúncias');
            flash_set('success', $novo ? 'Central VIP reabilitada — o cliente entra com a senha de antes.' : 'Central VIP desabilitada — o cliente não consegue mais entrar (conta/senha preservadas).');
        } else {
            flash_set('error', 'Esse cliente não tem conta na Central VIP.');
        }
    } else { flash_set('error', 'Só gestão/admin pode mexer na Central VIP.'); }
    redirect(module_url('processos', 'renuncias.php') . '#historico');
}

// ── POST: dar baixa na tarefa do operacional ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'baixar_tarefa') {
    if (!validate_csrf()) { flash_set('error', 'Sessão expirada.'); redirect(module_url('processos', 'renuncias.php') . '#operacional'); }
    $tid = (int)($_POST['task_id'] ?? 0);
    $vc  = (int)($_POST['voltar_caso'] ?? 0);
    $va  = trim($_POST['voltar_aba'] ?? 'operacional'); // 'historico' | 'operacional'
    if (!in_array($va, array('historico','operacional'), true)) $va = 'operacional';
    if ($tid) {
        $pdo->prepare("UPDATE case_tasks SET status = 'concluido', completed_at = NOW() WHERE id = ?")->execute(array($tid));
        audit_log('renuncia_tarefa_baixa', 'task', $tid, 'baixa via renúncias');
        flash_set('success', 'Renúncia/desistência marcada como cumprida! 🎉');
    }
    redirect($vc ? module_url('operacional', 'caso_ver.php?id=' . $vc) : module_url('processos', 'renuncias.php') . '#' . $va);
}

// ── Dados: histórico ─────────────────────────────────────
// Amanda 10/07: JOIN com case_tasks pra trazer o status (concluido=cumprido).
$lista = $pdo->query("SELECT r.*, c.title AS case_title, c.case_number, c.drive_folder_url,
                             cl.name AS client_name, u.name AS reg_por,
                             sv.id AS vip_id, sv.ativo AS vip_ativo,
                             t.status AS task_status, t.completed_at AS task_completed_at
                      FROM renuncias r
                      JOIN cases c ON c.id = r.case_id
                      JOIN clients cl ON cl.id = r.client_id
                      LEFT JOIN users u ON u.id = r.created_by
                      LEFT JOIN salavip_usuarios sv ON sv.cliente_id = r.client_id
                      LEFT JOIN case_tasks t ON t.id = r.task_id
                      ORDER BY r.created_at DESC LIMIT 500")->fetchAll();

// ── Dados: operacional (tarefas de renúncia/desistência ainda abertas) ──
$opTarefas = $pdo->query("SELECT r.id, r.tipo, r.motivo, r.motivo_outro, r.case_id, r.client_id,
                                 r.task_id, c.title AS case_title, c.case_number, c.drive_folder_url,
                                 cl.name AS client_name, t.title AS task_title, t.due_date
                          FROM renuncias r
                          JOIN case_tasks t ON t.id = r.task_id
                          JOIN cases c ON c.id = r.case_id
                          JOIN clients cl ON cl.id = r.client_id
                          WHERE t.status <> 'concluido'
                          ORDER BY r.created_at DESC LIMIT 300")->fetchAll();

// ── Dados: métricas ──────────────────────────────────────
$mTotal = (int)$pdo->query("SELECT COUNT(*) FROM renuncias")->fetchColumn();
$mMes   = (int)$pdo->query("SELECT COUNT(*) FROM renuncias WHERE DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')")->fetchColumn();
$porTipo = array('renuncia' => 0, 'desistencia' => 0);
foreach ($pdo->query("SELECT tipo, COUNT(*) q FROM renuncias GROUP BY tipo")->fetchAll() as $r) $porTipo[$r['tipo']] = (int)$r['q'];
$porMotivo = array();
foreach ($pdo->query("SELECT motivo, COUNT(*) q FROM renuncias GROUP BY motivo")->fetchAll() as $r) $porMotivo[$r['motivo']] = (int)$r['q'];
$porMes = array();
foreach ($pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) q FROM renuncias GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll() as $r) $porMes[$r['ym']] = (int)$r['q'];
$porMes = array_reverse($porMes, true);
$mesMax = $porMes ? max($porMes) : 0;

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.rd-tabs { display:flex; gap:.25rem; margin-bottom:1.2rem; border-bottom:2px solid var(--border,#e5e7eb); flex-wrap:wrap; }
.rd-tab { background:none; border:none; padding:.65rem 1.1rem; font-size:.9rem; font-weight:600; color:var(--text-muted,#6b7280); cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; }
.rd-tab:hover { color:#0f3d3e; background:#f8fafc; }
.rd-tab.active { color:#b87333; border-bottom-color:#b87333; }
.rd-pane { display:none; }
.rd-pane.active { display:block; }
.rd-card { background:#fff; border-radius:12px; padding:18px 20px; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:18px; }
.rd-form label { display:block; font-size:.8rem; font-weight:600; color:#444; margin:0 0 4px; }
.rd-form .row { margin-bottom:16px; }
.rd-input, .rd-select, .rd-text { width:100%; border:1px solid #ddd; border-radius:8px; padding:9px 11px; font-size:.92rem; font-family:inherit; }
.rd-text { min-height:70px; resize:vertical; }
.rd-results { position:relative; }
.rd-results-box { position:absolute; z-index:30; left:0; right:0; background:#fff; border:1px solid #ddd; border-top:none; border-radius:0 0 8px 8px; max-height:240px; overflow:auto; display:none; box-shadow:0 6px 14px rgba(0,0,0,.08); }
.rd-results-box div { padding:9px 11px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:.88rem; }
.rd-results-box div:hover { background:#eef6f4; }
.rd-sel { display:inline-flex; align-items:center; gap:8px; background:#e8f3f1; color:#0f3d3e; padding:5px 12px; border-radius:999px; font-weight:600; font-size:.85rem; margin-top:8px; }
.rd-sel button { background:none; border:none; color:#0f3d3e; cursor:pointer; font-size:1rem; line-height:1; }
.rd-tipos { display:flex; gap:10px; flex-wrap:wrap; }
.rd-tipo { flex:1; min-width:150px; border:2px solid #e3e3e3; border-radius:10px; padding:12px 14px; cursor:pointer; font-weight:700; text-align:center; }
.rd-tipo input { display:none; }
.rd-tipo.on { border-color:#b87333; background:#fdf6ee; color:#9c5d05; }
.rd-motivos label { display:flex; align-items:center; gap:8px; font-weight:500; font-size:.9rem; margin:6px 0; cursor:pointer; }
.rd-btn { background:#0f3d3e; color:#fff; border:none; border-radius:8px; padding:11px 20px; font-weight:700; cursor:pointer; font-size:.95rem; }
.rd-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:18px; }
.rd-stat { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.rd-stat .n { font-size:2rem; font-weight:800; color:#0f3d3e; }
.rd-stat .l { font-size:.78rem; color:#888; text-transform:uppercase; letter-spacing:.4px; }
.rd-table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.rd-table th, .rd-table td { padding:10px 12px; text-align:left; font-size:.85rem; border-bottom:1px solid #f0f0f0; vertical-align:top; }
.rd-table th { background:#fafafa; font-size:.72rem; text-transform:uppercase; color:#888; }
.rd-chip { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.75rem; font-weight:700; }
.rd-chip.renuncia { background:#eef2ff; color:#4338ca; }
.rd-chip.desistencia { background:#fef3c7; color:#92400e; }
.rd-bar-wrap { display:flex; align-items:center; gap:10px; margin:6px 0; }
.rd-bar-lbl { width:210px; font-size:.85rem; color:#444; }
.rd-bar-track { flex:1; background:#f0f0f0; border-radius:6px; height:18px; overflow:hidden; }
.rd-bar-fill { height:100%; background:#0d9488; border-radius:6px; }
.rd-empty { text-align:center; padding:42px; color:#999; }
.rd-mes { display:flex; align-items:flex-end; gap:8px; height:140px; padding-top:10px; }
.rd-mes .col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:4px; }
.rd-mes .b { width:70%; background:#b87333; border-radius:4px 4px 0 0; min-height:2px; }
.rd-mes .cap { font-size:.68rem; color:#888; }
.rd-mes .val { font-size:.72rem; font-weight:700; color:#0f3d3e; }
</style>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
  <div>
    <h1 style="margin:0;">📤 Renúncia / Desistência</h1>
    <p style="color:#777;margin:4px 0 0;">Registre a saída de um processo, anexe o comprovante e abra a tarefa pro operacional juntar o pedido na pasta.</p>
  </div>
  <a href="<?= module_url('processos') ?>" class="btn btn-outline btn-sm">⚖️ Voltar aos Processos</a>
</div>

<div class="rd-tabs">
  <button type="button" class="rd-tab active" data-pane="registrar" onclick="rdTab(this)">➕ Registrar</button>
  <button type="button" class="rd-tab" data-pane="operacional" onclick="rdTab(this)">🛠️ Operacional <span style="opacity:.6;">(<?= count($opTarefas) ?>)</span></button>
  <button type="button" class="rd-tab" data-pane="historico" onclick="rdTab(this)">📜 Histórico <span style="opacity:.6;">(<?= count($lista) ?>)</span></button>
  <button type="button" class="rd-tab" data-pane="metricas" onclick="rdTab(this)">📊 Métricas</button>
</div>

<!-- ABA REGISTRAR -->
<div class="rd-pane active" id="pane-registrar">
  <div class="rd-card" style="max-width:760px;">
    <form class="rd-form" method="post" action="<?= module_url('processos', 'renuncias.php') ?>" enctype="multipart/form-data" onsubmit="return rdValidar(this);">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="acao" value="registrar">
      <input type="hidden" name="client_id" id="rdClientId">

      <div class="row">
        <label>1. Cliente *</label>
        <div class="rd-results">
          <input type="text" class="rd-input" id="rdBuscaCli" placeholder="Digite o nome ou CPF do cliente…" autocomplete="off" onkeyup="rdBuscarCli(this.value)">
          <div class="rd-results-box" id="rdCliBox"></div>
        </div>
        <div id="rdCliSel"></div>
      </div>

      <div class="row" id="rdCasoRow" style="display:none;">
        <label>2. Processos do cliente * <span class="text-muted" style="font-weight:400;">(marque um, alguns ou todos)</span></label>
        <div id="rdCasosBox" style="border:1px solid #e3e3e3;border-radius:8px;padding:8px 11px;max-height:240px;overflow:auto;">
          <div class="text-muted" style="font-size:.85rem;">Escolha o cliente primeiro…</div>
        </div>
      </div>

      <div class="row">
        <label>3. O que vamos fazer? *</label>
        <div class="rd-tipos">
          <label class="rd-tipo" id="rdTipoRen"><input type="radio" name="tipo" value="renuncia" onchange="rdTipo(this)"> Renúncia</label>
          <label class="rd-tipo" id="rdTipoDes"><input type="radio" name="tipo" value="desistencia" onchange="rdTipo(this)"> Desistência</label>
        </div>
      </div>

      <div class="row">
        <label>4. Motivo *</label>
        <div class="rd-motivos">
          <?php foreach ($MOTIVOS as $k => $lbl): ?>
            <label><input type="radio" name="motivo" value="<?= $k ?>" onchange="rdMotivo()"> <?= e($lbl) ?></label>
          <?php endforeach; ?>
        </div>
        <textarea class="rd-text" name="motivo_outro" id="rdMotivoOutro" placeholder="Descreva o motivo…" style="display:none;margin-top:6px;"></textarea>
      </div>

      <div class="row">
        <label>5. Observação (opcional)</label>
        <textarea class="rd-text" name="observacao" placeholder="Algum detalhe que ajude o operacional…"></textarea>
      </div>

      <div class="row">
        <label>6. Comprovante de comunicação com o cliente * <span class="text-muted" style="font-weight:400;">(PDF, print ou DOC — até 10MB)</span></label>
        <input type="file" class="rd-input" name="comprovante" id="rdFile" accept="image/*,application/pdf,.doc,.docx">
      </div>

      <button type="submit" class="rd-btn">📤 Registrar e abrir tarefa</button>
    </form>
  </div>
</div>

<!-- ABA OPERACIONAL -->
<div class="rd-pane" id="pane-operacional">
  <div class="text-sm text-muted" style="margin-bottom:10px;">Tarefas de renúncia/desistência ainda abertas. Elabore a petição (modelo pronto) e dê baixa quando juntar na pasta.</div>
  <?php if (!$opTarefas): ?>
    <div class="rd-empty">🎉 Nada pendente no operacional.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="rd-table">
    <thead><tr><th>Tipo</th><th>Cliente</th><th>Processo</th><th>Motivo</th><th>Pasta</th><th>Ações</th></tr></thead>
    <tbody>
      <?php foreach ($opTarefas as $r):
        $mot = $r['motivo'] === 'outro' ? ($r['motivo_outro'] ?: 'Outro') : ($MOTIVOS[$r['motivo']] ?? $r['motivo']);
        $tpl = $r['tipo'] === 'renuncia' ? 'renuncia_poderes' : 'desistencia_acao';
        $petUrl = url('modules/documentos/gerar.php') . '?tipo=' . $tpl . '&client_id=' . (int)$r['client_id'] . '&case_id=' . (int)$r['case_id'];
      ?>
      <tr>
        <td><span class="rd-chip <?= e($r['tipo']) ?>"><?= e($TIPOS[$r['tipo']] ?? $r['tipo']) ?></span></td>
        <td><?= e($r['client_name']) ?></td>
        <td><?= e($r['case_number'] ?: $r['case_title']) ?></td>
        <td><?= e($mot) ?></td>
        <td><?php if ($r['drive_folder_url']): ?><a href="<?= e($r['drive_folder_url']) ?>" target="_blank" rel="noopener">📁 Drive</a><?php else: ?>—<?php endif; ?></td>
        <td style="white-space:nowrap;">
          <a href="<?= e($petUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm" title="Abre o modelo de <?= e($TIPOS[$r['tipo']]) ?> já com o cliente">📝 Elaborar petição</a>
          <form method="post" action="<?= module_url('processos', 'renuncias.php') ?>" style="display:inline;" onsubmit="return confirm('Dar baixa? A tarefa some daqui.');">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="acao" value="baixar_tarefa">
            <input type="hidden" name="task_id" value="<?= (int)$r['task_id'] ?>">
            <button type="submit" class="btn btn-primary btn-sm">✅ Dar baixa</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- ABA HISTÓRICO -->
<div class="rd-pane" id="pane-historico">
  <?php if (!$lista): ?>
    <div class="rd-empty">Nenhuma renúncia/desistência registrada ainda.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="rd-table">
    <thead><tr><th>Data</th><th>Status</th><th>Tipo</th><th>Cliente</th><th>Processo</th><th>Motivo</th><th>Registrado por</th><th>Comprovante</th><th>Pasta</th><th>Central VIP</th></tr></thead>
    <tbody>
      <?php foreach ($lista as $r):
        $mot = $r['motivo'] === 'outro' ? ($r['motivo_outro'] ?: 'Outro') : ($MOTIVOS[$r['motivo']] ?? $r['motivo']);
        $_cumprido = ($r['task_status'] ?? '') === 'concluido';
        $_dtCumpr  = $_cumprido && !empty($r['task_completed_at']) ? ' em ' . date('d/m/Y', strtotime($r['task_completed_at'])) : '';
      ?>
      <tr>
        <td><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
        <td>
          <?php if ($_cumprido): ?>
            <span class="rd-chip" style="background:#dcfce7;color:#15803d;" title="Cumprido<?= $_dtCumpr ?>">✓ CUMPRIDO</span>
          <?php else: ?>
            <span class="rd-chip" style="background:#fef3c7;color:#92400e;">⏳ PENDENTE</span>
            <?php if (!empty($r['task_id'])): ?>
            <form method="post" style="display:inline;margin-left:4px;" onsubmit="var b=this.querySelector('button');if(b.disabled)return false;b.disabled=true;b.style.opacity='.6';return confirm('Marcar como cumprido?');">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="acao" value="baixar_tarefa">
              <input type="hidden" name="task_id" value="<?= (int)$r['task_id'] ?>">
              <input type="hidden" name="voltar_aba" value="historico">
              <button type="submit" class="btn btn-sm" style="background:#15803d;color:#fff;border:none;padding:2px 8px;font-size:.7rem;font-weight:700;" title="Marcar como cumprido">✓</button>
            </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td><span class="rd-chip <?= e($r['tipo']) ?>"><?= e($TIPOS[$r['tipo']] ?? $r['tipo']) ?></span></td>
        <td><?= e($r['client_name']) ?></td>
        <td><?= e($r['case_number'] ?: $r['case_title']) ?></td>
        <td><?= e($mot) ?><?php if ($r['observacao']): ?><div class="text-muted" style="font-size:.78rem;"><?= e($r['observacao']) ?></div><?php endif; ?></td>
        <td><?= e($r['reg_por'] ?: '—') ?></td>
        <td><?php if ($r['comprovante_path']): ?><a href="?baixar=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">📎 abrir</a><?php else: ?>—<?php endif; ?></td>
        <td><?php if ($r['drive_folder_url']): ?><a href="<?= e($r['drive_folder_url']) ?>" target="_blank" rel="noopener">📁 Drive</a><?php else: ?>—<?php endif; ?></td>
        <td style="white-space:nowrap;">
          <?php if ($r['vip_id']): ?>
            <?php if ($r['vip_ativo']): ?>
              <span class="rd-chip" style="background:#dcfce7;color:#15803d;">Ativa</span>
              <?php if (has_min_role('gestao')): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Desabilitar a Central VIP desse cliente?');">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="toggle_vip">
                <input type="hidden" name="client_id" value="<?= (int)$r['client_id'] ?>"><input type="hidden" name="ativar" value="0">
                <button type="submit" class="btn btn-outline btn-sm" style="padding:2px 8px;">Desabilitar</button>
              </form>
              <?php endif; ?>
            <?php else: ?>
              <span class="rd-chip" style="background:#fee2e2;color:#b91c1c;">Desabilitada</span>
              <?php if (has_min_role('gestao')): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Reabilitar a Central VIP desse cliente?');">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="toggle_vip">
                <input type="hidden" name="client_id" value="<?= (int)$r['client_id'] ?>"><input type="hidden" name="ativar" value="1">
                <button type="submit" class="btn btn-outline btn-sm" style="padding:2px 8px;">Reabilitar</button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted" style="font-size:.78rem;">sem conta</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- ABA MÉTRICAS -->
<div class="rd-pane" id="pane-metricas">
  <div class="rd-stats">
    <div class="rd-stat"><div class="n"><?= $mTotal ?></div><div class="l">Total geral</div></div>
    <div class="rd-stat"><div class="n"><?= $porTipo['renuncia'] ?></div><div class="l">Renúncias</div></div>
    <div class="rd-stat"><div class="n"><?= $porTipo['desistencia'] ?></div><div class="l">Desistências</div></div>
    <div class="rd-stat"><div class="n"><?= $mMes ?></div><div class="l">Este mês</div></div>
  </div>

  <div class="rd-card">
    <div style="font-weight:700;color:#0f3d3e;margin-bottom:10px;">Por motivo</div>
    <?php if (!$mTotal): ?><div class="text-muted">Sem dados ainda.</div><?php else:
      foreach ($MOTIVOS as $k => $lbl): $q = $porMotivo[$k] ?? 0; $pct = $mTotal ? round($q / $mTotal * 100) : 0; ?>
      <div class="rd-bar-wrap">
        <div class="rd-bar-lbl"><?= e($lbl) ?></div>
        <div class="rd-bar-track"><div class="rd-bar-fill" style="width:<?= $pct ?>%;"></div></div>
        <div style="width:60px;font-size:.82rem;font-weight:700;color:#0f3d3e;"><?= $q ?> (<?= $pct ?>%)</div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="rd-card">
    <div style="font-weight:700;color:#0f3d3e;margin-bottom:10px;">Por mês (últimos 12)</div>
    <?php if (!$porMes): ?><div class="text-muted">Sem dados ainda.</div><?php else: ?>
    <div class="rd-mes">
      <?php foreach ($porMes as $ym => $q): $h = $mesMax ? round($q / $mesMax * 110) : 2; $p = explode('-', $ym); ?>
        <div class="col">
          <div class="val"><?= $q ?></div>
          <div class="b" style="height:<?= max(2, $h) ?>px;"></div>
          <div class="cap"><?= e($p[1] . '/' . substr($p[0], 2)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
var RD_URL = '<?= module_url('processos', 'renuncias.php') ?>';

function rdTab(el) {
  document.querySelectorAll('.rd-tab').forEach(function(t){ t.classList.remove('active'); });
  document.querySelectorAll('.rd-pane').forEach(function(p){ p.classList.remove('active'); });
  el.classList.add('active');
  document.getElementById('pane-' + el.dataset.pane).classList.add('active');
}
// abrir aba pelo hash (#historico etc.)
(function(){ var h = (location.hash || '').replace('#',''); if (h) { var b = document.querySelector('.rd-tab[data-pane="'+h+'"]'); if (b) rdTab(b); } })();

var rdTimer = null;
function rdBuscarCli(q) {
  clearTimeout(rdTimer);
  var box = document.getElementById('rdCliBox');
  if (q.length < 2) { box.style.display = 'none'; return; }
  rdTimer = setTimeout(function(){
    fetch(RD_URL + '?ajax=buscar_cliente&q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(arr){
        var html = '';
        arr.forEach(function(c){
          var sub = c.cpf || c.phone || '';
          html += '<div onclick="rdSelCli(' + c.id + ',&quot;' + (c.name||'').replace(/"/g,'') + '&quot;)">' + (c.name||'') + (sub ? ' <span style=&quot;color:#999&quot;>· ' + sub + '</span>' : '') + '</div>';
        });
        box.innerHTML = html || '<div style="color:#999;cursor:default;">Nenhum cliente</div>';
        box.style.display = 'block';
      });
  }, 250);
}
function rdSelCli(id, name) {
  document.getElementById('rdClientId').value = id;
  document.getElementById('rdBuscaCli').value = '';
  document.getElementById('rdCliBox').style.display = 'none';
  document.getElementById('rdCliSel').innerHTML = '<span class="rd-sel">👤 ' + name + ' <button type="button" onclick="rdLimparCli()">×</button></span>';
  rdCarregarCasos(id);
}
function rdLimparCli() {
  document.getElementById('rdClientId').value = '';
  document.getElementById('rdCliSel').innerHTML = '';
  document.getElementById('rdCasoRow').style.display = 'none';
  document.getElementById('rdCasosBox').innerHTML = '';
}
function rdCarregarCasos(clientId) {
  var box = document.getElementById('rdCasosBox');
  box.innerHTML = 'Carregando…';
  document.getElementById('rdCasoRow').style.display = 'block';
  fetch(RD_URL + '?ajax=buscar_casos&client_id=' + clientId)
    .then(function(r){ return r.json(); })
    .then(function(arr){
      if (!arr.length) { box.innerHTML = '<div class="text-muted" style="font-size:.85rem;">Esse cliente não tem processo cadastrado.</div>'; return; }
      var html = '';
      if (arr.length > 1) {
        html += '<label style="display:flex;align-items:center;gap:8px;font-weight:700;border-bottom:1px solid #eee;padding-bottom:6px;margin-bottom:6px;cursor:pointer;">'
              + '<input type="checkbox" id="rdMarcarTodos" onchange="rdToggleTodos(this)"> ✅ Marcar todos (' + arr.length + ')</label>';
      }
      arr.forEach(function(c){
        var lbl = (c.case_number ? c.case_number + ' — ' : '') + (c.title || ('Caso #' + c.id));
        html += '<label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;font-size:.9rem;">'
              + '<input type="checkbox" class="rd-caso-chk" name="case_ids[]" value="' + c.id + '"> '
              + lbl.replace(/</g,'') + '</label>';
      });
      box.innerHTML = html;
    });
}
function rdToggleTodos(master) {
  document.querySelectorAll('.rd-caso-chk').forEach(function(c){ c.checked = master.checked; });
}
function rdTipo(input) {
  document.getElementById('rdTipoRen').classList.toggle('on', input.value === 'renuncia');
  document.getElementById('rdTipoDes').classList.toggle('on', input.value === 'desistencia');
}
function rdMotivo() {
  var outro = document.querySelector('input[name="motivo"]:checked');
  document.getElementById('rdMotivoOutro').style.display = (outro && outro.value === 'outro') ? 'block' : 'none';
}
function rdValidar(f) {
  if (!f.client_id.value) { alert('Escolha o cliente.'); return false; }
  if (!document.querySelectorAll('.rd-caso-chk:checked').length) { alert('Escolha pelo menos um processo.'); return false; }
  if (!f.tipo.value && !document.querySelector('input[name="tipo"]:checked')) { alert('Marque renúncia ou desistência.'); return false; }
  var mot = document.querySelector('input[name="motivo"]:checked');
  if (!mot) { alert('Escolha o motivo.'); return false; }
  if (mot.value === 'outro' && !document.getElementById('rdMotivoOutro').value.trim()) { alert('Descreva o "outro" motivo.'); return false; }
  if (!document.getElementById('rdFile').value) { alert('Anexe o comprovante de comunicação.'); return false; }
  return true;
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
