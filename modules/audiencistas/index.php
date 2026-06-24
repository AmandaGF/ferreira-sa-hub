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
function aud_money($cents) { return $cents !== null && $cents !== '' ? 'R$ ' . number_format($cents / 100, 2, ',', '.') : '—'; }

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

// ── Download do arquivo do processo (autenticado) ────────
if (isset($_GET['baixar'])) {
    $id = (int)$_GET['baixar'];
    $st = $pdo->prepare("SELECT arquivo_path, arquivo_nome, arquivo_mime FROM audiencias WHERE id = ?");
    $st->execute(array($id));
    $row = $st->fetch();
    $path = $row && $row['arquivo_path'] ? APP_ROOT . '/files/audiencias/' . basename($row['arquivo_path']) : '';
    if (!$path || !is_file($path)) { http_response_code(404); die('Arquivo não encontrado.'); }
    header('Content-Type: ' . ($row['arquivo_mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/', '_', $row['arquivo_nome'] ?: 'processo') . '"');
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
        $tel   = clean_str($_POST['telefone'] ?? '', 30);
        $email = clean_str($_POST['email'] ?? '', 190);
        $areas = clean_str($_POST['areas'] ?? '', 1000);
        $tipos = isset($_POST['tipos']) && is_array($_POST['tipos']) ? implode(', ', array_map('strval', $_POST['tipos'])) : '';
        $valor = ($_POST['valor'] ?? '') !== '' ? (int) round(((float)str_replace(',', '.', $_POST['valor'])) * 100) : null;
        $dep   = clean_str($_POST['dados_deposito'] ?? '', 1000);
        $obs   = clean_str($_POST['observacoes'] ?? '', 1000);
        if ($nome === '') { flash_set('error', 'Informe o nome da audiencista.'); redirect(module_url('audiencistas') . '#cad'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE audiencistas SET nome=?, telefone=?, email=?, areas=?, tipos=?, valor_medio_cents=?, dados_deposito=?, observacoes=? WHERE id=?")
                ->execute(array($nome, $tel, $email, $areas, $tipos, $valor, $dep, $obs, $id));
            audit_log('audiencista_editar', 'audiencista', $id, $nome);
            flash_set('success', 'Audiencista atualizada.');
        } else {
            $pdo->prepare("INSERT INTO audiencistas (nome, telefone, email, areas, tipos, valor_medio_cents, dados_deposito, observacoes, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute(array($nome, $tel, $email, $areas, $tipos, $valor, $dep, $obs, current_user_id()));
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
        $pdo->prepare("INSERT INTO audiencias (tipo, data_hora, comarca, client_id, case_id, processo_numero, orientacoes, audiencista_id, arquivo_nome, arquivo_path, arquivo_mime, status, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array($tipo, $dataVal, $comarca, $clientId, $caseId, $procNum ?: null, $orient ?: null, $audId, $aNome, $aPath, $aMime, $status, current_user_id()));
        $novoId = (int)$pdo->lastInsertId();
        audit_log('audiencia_criar', 'audiencia', $novoId, $tipo);

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
        $pdo->prepare("UPDATE audiencias SET audiencista_id=?, valor_cents=COALESCE(?,valor_cents), status=IF(status='aberta','designada',status) WHERE id=?")
            ->execute(array($audId, $valor, $aid));
        audit_log('audiencia_designar', 'audiencia', $aid, 'audiencista=' . $audId);
        flash_set('success', 'Audiencista designada.');
        redirect(module_url('audiencistas'));
    }

    // -- mudar status --
    if ($acao === 'status') {
        $aid = (int)($_POST['audiencia_id'] ?? 0); $st = $_POST['novo'] ?? '';
        if (isset($STATUS[$st])) {
            $pdo->prepare("UPDATE audiencias SET status=? WHERE id=?")->execute(array($st, $aid));
            flash_set('success', 'Status atualizado.');
        }
        redirect(module_url('audiencistas'));
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
    <summary style="font-weight:700;color:#0f3d3e;cursor:pointer;">➕ Nova audiência a contratar</summary>
    <form class="au-form" method="post" action="<?= module_url('audiencistas') ?>" enctype="multipart/form-data" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="acao" value="salvar_audiencia">
      <input type="hidden" name="client_id" id="auClientId">
      <input type="hidden" name="case_id" id="auCaseId">
      <div class="au-grid2">
        <div class="row"><label>Tipo de audiência *</label>
          <select class="au-select" name="tipo" required>
            <option value="">Selecione…</option>
            <?php foreach ($TIPOS as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="row"><label>Data e hora</label><input type="datetime-local" class="au-input" name="data_hora"></div>
      </div>
      <div class="au-grid2">
        <div class="row"><label>Comarca / Local</label><input type="text" class="au-input" name="comarca" placeholder="Ex: Niterói/RJ — 2ª Vara Cível"></div>
        <div class="row"><label>Nº do processo</label><input type="text" class="au-input" name="processo_numero" placeholder="CNJ (opcional)"></div>
      </div>
      <div class="row"><label>Cliente (opcional — vincula ao processo)</label>
        <div class="au-results"><input type="text" class="au-input" id="auBuscaCli" placeholder="Digite o nome do cliente…" autocomplete="off" onkeyup="auBuscarCli(this.value)"><div class="au-rbox" id="auCliBox"></div></div>
        <div id="auCliSel"></div>
        <div id="auCasoWrap" style="display:none;margin-top:8px;"><label>Processo do cliente</label>
          <select class="au-select" id="auCaseSel" onchange="document.getElementById('auCaseId').value=this.value;"><option value="">—</option></select>
        </div>
      </div>
      <div class="row"><label>Orientações para a audiencista</label>
        <textarea class="au-text" name="orientacoes" placeholder="Pontos de atenção, teses, o que pedir/evitar, contato do cliente, etc."></textarea>
      </div>
      <div class="au-grid2">
        <div class="row"><label>Designar audiencista (opcional)</label>
          <select class="au-select" name="audiencista_id">
            <option value="">— designar depois —</option>
            <?php foreach ($audAtivas as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['nome']) ?><?= $a['areas'] ? ' · ' . e(mb_substr($a['areas'], 0, 40)) : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="row"><label>Arquivo do processo (PDF/DOC/ZIP, até 25MB)</label><input type="file" class="au-input" name="arquivo" accept=".pdf,.doc,.docx,.zip,image/*"></div>
      </div>
      <button type="submit" class="au-btn">➕ Registrar audiência</button>
    </form>
  </details>

  <?php if (!$audiencias): ?>
    <div class="au-empty">Nenhuma audiência cadastrada ainda. Crie a primeira acima. 👆</div>
  <?php else: foreach ($audiencias as $a):
    $proc = $a['case_number'] ?: ($a['processo_numero'] ?: ($a['case_title'] ?: '—'));
    $waMsg = "Olá! Tudo bem? Temos uma audiência (" . $a['tipo'] . ")"
           . ($a['data_hora'] ? ' em ' . date('d/m/Y H:i', strtotime($a['data_hora'])) : '')
           . ($a['comarca'] ? ' na comarca de ' . $a['comarca'] : '') . ". Você teria disponibilidade?";
    $wa = $a['aud_tel'] ? aud_wa_link($a['aud_tel'], $waMsg) : '';
  ?>
  <div class="au-card au-acard">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;">
      <div>
        <div style="font-weight:700;color:#0f3d3e;font-size:1rem;">⚖️ <?= e($a['tipo']) ?>
          <span class="au-chip au-st-<?= e($a['status']) ?>"><?= e($STATUS[$a['status']] ?? $a['status']) ?></span>
        </div>
        <div style="color:#666;font-size:.85rem;margin-top:3px;">
          <?= $a['data_hora'] ? '📅 ' . date('d/m/Y H:i', strtotime($a['data_hora'])) . ' · ' : '' ?>
          <?= $a['comarca'] ? '📍 ' . e($a['comarca']) . ' · ' : '' ?>
          📄 <?= e($proc) ?><?= $a['client_name'] ? ' · 👤 ' . e($a['client_name']) : '' ?>
        </div>
        <?php if ($a['orientacoes']): ?><div style="margin-top:6px;font-size:.85rem;color:#444;background:#fafafa;border-radius:8px;padding:8px 10px;white-space:pre-wrap;"><?= e($a['orientacoes']) ?></div><?php endif; ?>
      </div>
      <form method="post" style="margin:0;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="status"><input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
        <select class="au-select" name="novo" onchange="this.form.submit()" style="width:auto;font-size:.8rem;padding:5px 8px;">
          <?php foreach ($STATUS as $k => $lbl): ?><option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>

    <div style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-top:1px solid #f0f0f0;padding-top:10px;">
      <?php if ($a['audiencista_id']): ?>
        <span style="font-size:.85rem;">👩‍⚖️ <strong><?= e($a['aud_nome']) ?></strong><?= $a['valor_cents'] !== null ? ' · ' . aud_money($a['valor_cents']) : '' ?></span>
        <?php if ($wa): ?><a class="au-mini wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">💬 Falar no WhatsApp</a><?php endif; ?>
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
        <div class="row"><label>WhatsApp</label><input type="text" class="au-input" name="telefone" id="fTel" placeholder="Ex: 21999998888"></div>
      </div>
      <div class="au-grid2">
        <div class="row"><label>E-mail</label><input type="email" class="au-input" name="email" id="fEmail"></div>
        <div class="row"><label>Valor médio cobrado (R$)</label><input type="number" step="0.01" min="0" class="au-input" name="valor" id="fValor"></div>
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
    $wa = $a['telefone'] ? aud_wa_link($a['telefone']) : '';
  ?>
  <div class="au-card" style="<?= (int)$a['ativo'] !== 1 ? 'opacity:.55;' : '' ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;">
      <div>
        <div style="font-weight:700;color:#0f3d3e;font-size:1rem;">👩‍⚖️ <?= e($a['nome']) ?>
          <?php if ((int)$a['ativo'] !== 1): ?><span class="au-chip au-st-cancelada">arquivada</span><?php endif; ?></div>
        <div style="color:#666;font-size:.84rem;margin-top:3px;">
          <?= $a['telefone'] ? '📱 ' . e($a['telefone']) : '' ?><?= $a['email'] ? ' · ✉️ ' . e($a['email']) : '' ?>
          <?= $a['valor_medio_cents'] !== null ? ' · 💰 ' . aud_money($a['valor_medio_cents']) . ' (médio)' : '' ?>
        </div>
        <?php if ($a['areas']): ?><div style="font-size:.84rem;margin-top:5px;">🗺️ <strong>Áreas:</strong> <?= e($a['areas']) ?></div><?php endif; ?>
        <?php if ($tiposArr): ?><div style="margin-top:5px;"><?php foreach ($tiposArr as $t): ?><span class="au-tag"><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if ($a['dados_deposito']): ?><div style="font-size:.82rem;margin-top:5px;color:#444;">🏦 <?= e($a['dados_deposito']) ?></div><?php endif; ?>
        <?php if ($a['observacoes']): ?><div style="font-size:.82rem;margin-top:4px;color:#777;"><?= e($a['observacoes']) ?></div><?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php if ($wa): ?><a class="au-mini wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">💬 WhatsApp</a><?php endif; ?>
        <button type="button" class="au-mini gh" onclick='auEdit(<?= json_encode(array("id"=>(int)$a["id"],"nome"=>$a["nome"],"telefone"=>$a["telefone"],"email"=>$a["email"],"areas"=>$a["areas"],"tipos"=>$a["tipos"],"valor"=>($a["valor_medio_cents"]!==null?number_format($a["valor_medio_cents"]/100,2,".",""):""),"dep"=>$a["dados_deposito"],"obs"=>$a["observacoes"]), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>✏️ Editar</button>
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
function auLimparCli(){ document.getElementById('auClientId').value=''; document.getElementById('auCaseId').value=''; document.getElementById('auCliSel').innerHTML=''; document.getElementById('auCasoWrap').style.display='none'; }

function auEdit(d){
  document.getElementById('auEditId').value=d.id;
  document.getElementById('fNome').value=d.nome||''; document.getElementById('fTel').value=d.telefone||'';
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
