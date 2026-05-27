<?php
/**
 * Ferreira & Sa Hub -- Central VIP -- GED (Documentos para Clientes)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();

// Self-heal: colunas para link publico compartilhavel (criadas 26/05/2026
// a pedido da Amanda: enviar link em vez de arquivo pesado pelo WhatsApp).
try { $pdo->exec("ALTER TABLE salavip_ged ADD COLUMN share_token VARCHAR(64) NULL UNIQUE AFTER visivel_cliente"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE salavip_ged ADD COLUMN share_token_em DATETIME NULL AFTER share_token"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE salavip_ged ADD COLUMN share_revogado TINYINT(1) DEFAULT 0 AFTER share_token_em"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE salavip_ged ADD COLUMN share_acessos INT DEFAULT 0 AFTER share_revogado"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE salavip_ged ADD COLUMN share_ultimo_acesso DATETIME NULL AFTER share_acessos"); } catch (Throwable $e) {}

// AJAX: buscar processos de um cliente
if (isset($_GET['ajax_cases']) && isset($_GET['client_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)$_GET['client_id'];
    $stmt = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE client_id = ? ORDER BY title");
    $stmt->execute([$cid]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// AJAX: status do cliente na Central VIP (pra avisar antes do upload)
if (isset($_GET['ajax_vip_status']) && isset($_GET['client_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)$_GET['client_id'];
    $stmt = $pdo->prepare("SELECT id, email, ativo, ultimo_acesso FROM salavip_usuarios WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $u = $stmt->fetch();
    if (!$u) {
        echo json_encode(array('status' => 'sem_usuario'));
    } elseif ((int)$u['ativo'] === 0) {
        echo json_encode(array(
            'status' => 'inativo',
            'user_id' => (int)$u['id'],
            'email' => $u['email'],
        ));
    } else {
        echo json_encode(array(
            'status' => 'ativo',
            'user_id' => (int)$u['id'],
            'email' => $u['email'],
            'ultimo_acesso' => $u['ultimo_acesso'],
        ));
    }
    exit;
}

// AJAX: gerar/recuperar link publico de um documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'gerar_link') {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF expirado — recarregue', 'csrf_expired' => true)); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(array('error' => 'id ausente')); exit; }

        $st = $pdo->prepare("SELECT id, share_token, share_revogado, share_acessos, share_ultimo_acesso FROM salavip_ged WHERE id = ?");
        $st->execute(array($id));
        $row = $st->fetch();
        if (!$row) { echo json_encode(array('error' => 'Documento nao encontrado')); exit; }

        $token = $row['share_token'];
        $reativou = false;
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE salavip_ged SET share_token = ?, share_token_em = NOW(), share_revogado = 0 WHERE id = ?")
                ->execute(array($token, $id));
        } elseif (!empty($row['share_revogado'])) {
            // Reativa: mantem o token (continuidade de auditoria) mas desfaz revogacao
            $pdo->prepare("UPDATE salavip_ged SET share_revogado = 0 WHERE id = ?")->execute(array($id));
            $reativou = true;
        }

        audit_log($reativou ? 'salavip_ged_link_reativado' : 'salavip_ged_link_gerado', 'salavip_ged', $id);

        // Monta URL absoluta (BASE_URL pode vir relativo tipo "/conecta")
        $_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $_host   = $_SERVER['HTTP_HOST'] ?? 'ferreiraesa.com.br';
        $_rel    = url('d.php?t=' . $token);
        $publicUrl = (stripos($_rel, 'http') === 0) ? $_rel : ($_scheme . '://' . $_host . '/' . ltrim($_rel, '/'));

        echo json_encode(array(
            'ok'       => true,
            'url'      => $publicUrl,
            'acessos'  => (int)($row['share_acessos'] ?? 0),
            'ultimo'   => $row['share_ultimo_acesso'] ?? null,
            'reativou' => $reativou,
        ));
    } catch (Throwable $e) {
        @error_log('[salavip ged gerar_link] ' . $e->getMessage());
        echo json_encode(array('error' => 'Erro interno: ' . $e->getMessage()));
    }
    exit;
}

// AJAX: revogar link publico
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'revogar_link') {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF expirado — recarregue', 'csrf_expired' => true)); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(array('error' => 'id ausente')); exit; }
        $pdo->prepare("UPDATE salavip_ged SET share_revogado = 1 WHERE id = ?")->execute(array($id));
        audit_log('salavip_ged_link_revogado', 'salavip_ged', $id);
        echo json_encode(array('ok' => true));
    } catch (Throwable $e) {
        @error_log('[salavip ged revogar_link] ' . $e->getMessage());
        echo json_encode(array('error' => 'Erro interno: ' . $e->getMessage()));
    }
    exit;
}

$pageTitle = 'GED — Documentos para Clientes';

$allowedExt = array('pdf', 'jpg', 'jpeg', 'png', 'docx');
$maxSize = 10 * 1024 * 1024; // 10MB
$uploadDir = dirname(APP_ROOT) . '/salavip/uploads/ged/';

$categorias = array(
    'Procuração', 'Contrato', 'Petição', 'Decisão', 'Sentença',
    'Certidão', 'Comprovante', 'Acordo', 'Parecer', 'Outro'
);

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? 'upload';

    // ── Upload ──────────────────────────────────────────
    if ($action === 'upload') {
        $clientId  = (int)($_POST['client_id'] ?? 0);
        $caseId    = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : null;
        $titulo    = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $categoria = $_POST['categoria'] ?? 'Outro';
        $visivel   = isset($_POST['visivel_cliente']) ? 1 : 0;

        if (!$clientId || !$titulo) {
            flash_set('error', 'Cliente e título são obrigatórios.');
            redirect(module_url('salavip', 'ged.php'));
        }

        if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Selecione um arquivo valido.');
            redirect(module_url('salavip', 'ged.php'));
        }

        $file = $_FILES['arquivo'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt)) {
            flash_set('error', 'Formato nao permitido. Aceitos: ' . implode(', ', $allowedExt));
            redirect(module_url('salavip', 'ged.php'));
        }

        if ($file['size'] > $maxSize) {
            flash_set('error', 'Arquivo excede 10MB.');
            redirect(module_url('salavip', 'ged.php'));
        }

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = uniqid('ged_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $filepath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            flash_set('error', 'Erro ao salvar arquivo.');
            redirect(module_url('salavip', 'ged.php'));
        }

        $stmt = $pdo->prepare(
            "INSERT INTO salavip_ged (cliente_id, processo_id, titulo, descricao, categoria, arquivo_path, arquivo_nome, visivel_cliente, compartilhado_por, compartilhado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$clientId, $caseId, $titulo, $descricao, $categoria, $filename, $file['name'], $visivel, current_user_id()]);

        audit_log('salavip_ged_upload', 'salavip_ged', (int)$pdo->lastInsertId(), "Documento: $titulo");
        flash_set('success', 'Documento enviado com sucesso.');
        $fromCase = (int)($_POST['from_case'] ?? 0);
        if ($fromCase) {
            redirect(module_url('operacional', 'caso_ver.php?id=' . $fromCase));
        }
        redirect(module_url('salavip', 'ged.php'));
    }

    // ── Toggle visibilidade ─────────────────────────────
    if ($action === 'toggle_visivel') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE salavip_ged SET visivel_cliente = NOT visivel_cliente WHERE id = ?")->execute([$id]);
        audit_log('salavip_ged_toggle_visivel', 'salavip_ged', $id);
        flash_set('success', 'Visibilidade alterada.');
        $fromCase = (int)($_POST['from_case'] ?? 0);
        if ($fromCase) { redirect(module_url('operacional', 'caso_ver.php?id=' . $fromCase)); }
        redirect(module_url('salavip', 'ged.php'));
    }

    // ── Excluir ─────────────────────────────────────────
    if ($action === 'excluir_ged') {
        $id = (int)($_POST['id'] ?? 0);
        $doc = $pdo->prepare("SELECT arquivo_path, titulo FROM salavip_ged WHERE id = ?");
        $doc->execute([$id]);
        $doc = $doc->fetch();

        if ($doc) {
            $filePath = $uploadDir . $doc['arquivo_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $pdo->prepare("DELETE FROM salavip_ged WHERE id = ?")->execute([$id]);
            audit_log('salavip_ged_excluir', 'salavip_ged', $id, "Documento: " . $doc['titulo']);
            flash_set('success', 'Documento excluido.');
        }
        $fromCase = (int)($_POST['from_case'] ?? 0);
        if ($fromCase) { redirect(module_url('operacional', 'caso_ver.php?id=' . $fromCase)); }
        redirect(module_url('salavip', 'ged.php'));
    }
}

// ── Listar documentos ───────────────────────────────────
$filterClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

$where = '1=1';
$params = array();
if ($filterClient) {
    $where .= ' AND g.cliente_id = ?';
    $params[] = $filterClient;
}

$docs = $pdo->prepare(
    "SELECT g.*, c.name as client_name,
            cs.title as case_title, cs.case_number
     FROM salavip_ged g
     JOIN clients c ON c.id = g.cliente_id
     LEFT JOIN cases cs ON cs.id = g.processo_id
     WHERE $where
     ORDER BY g.compartilhado_em DESC
     LIMIT 50"
);
$docs->execute($params);
$docs = $docs->fetchAll();

// Clientes para select
$clientes = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

$catBadge = array(
    'Procuração' => 'info', 'Contrato' => 'success', 'Petição' => 'warning',
    'Decisão' => 'gestao', 'Sentença' => 'danger', 'Certidão' => 'colaborador',
    'Comprovante' => 'info', 'Acordo' => 'success', 'Parecer' => 'gestao', 'Outro' => 'warning',
    // Fallback sem acento (dados antigos)
    'Procuracao' => 'info', 'Peticao' => 'warning', 'Decisao' => 'gestao',
    'Sentenca' => 'danger', 'Certidao' => 'colaborador',
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ged-form { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
@media(max-width:700px){ .ged-form{grid-template-columns:1fr;} }
.ged-form .full { grid-column:1/-1; }
.ged-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.ged-table th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.ged-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.ged-table tr:hover { background:rgba(215,171,144,.04); }
.ged-toggle { cursor:pointer; font-size:.85rem; }
</style>

<a href="<?= module_url('salavip') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<!-- Upload Form -->
<div class="card mb-2">
    <div class="card-header"><h3>Enviar Documento</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="ged-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="upload">

            <div>
                <label class="form-label">Cliente *</label>
                <select name="client_id" id="ged_client" class="form-control" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($clientes as $cli): ?>
                        <option value="<?= $cli['id'] ?>"><?= e($cli['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- Aviso de status da Central VIP (preenchido por JS) -->
                <div id="ged_vip_status" style="margin-top:.4rem;font-size:.78rem;line-height:1.3;display:none;padding:.5rem .7rem;border-radius:6px;"></div>
            </div>

            <div>
                <label class="form-label">Processo vinculado *</label>
                <select name="case_id" id="ged_case" class="form-control">
                    <option value="">Selecione o cliente primeiro...</option>
                </select>
                <small style="color:var(--text-muted);font-size:.72rem;">Selecione o cliente acima para carregar os processos</small>
            </div>

            <div>
                <label class="form-label">Título *</label>
                <input type="text" name="titulo" class="form-control" required maxlength="255">
            </div>

            <div>
                <label class="form-label">Categoria</label>
                <select name="categoria" class="form-control">
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= $cat ?>"><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="full">
                <label class="form-label">Descrição</label>
                <textarea name="descricao" class="form-control" rows="2"></textarea>
            </div>

            <div>
                <label class="form-label">Arquivo * (PDF, JPG, PNG, DOCX — máx 10MB)</label>
                <input type="file" name="arquivo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx" required>
            </div>

            <div style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem;">
                <label class="form-label" style="margin:0;">Visível ao cliente</label>
                <input type="checkbox" name="visivel_cliente" value="1" checked>
            </div>

            <div class="full" style="text-align:right;">
                <button type="submit" class="btn btn-primary">Enviar Documento</button>
            </div>
        </form>
    </div>
</div>

<!-- Filter -->
<div class="card mb-2">
    <div class="card-header" style="justify-content:space-between;">
        <h3>Documentos Enviados</h3>
        <form method="GET" style="display:flex;gap:.4rem;align-items:center;">
            <select name="client_id" class="form-control" style="font-size:.78rem;width:220px;" onchange="this.form.submit()">
                <option value="">Todos os clientes</option>
                <?php foreach ($clientes as $cli): ?>
                    <option value="<?= $cli['id'] ?>" <?= $filterClient == $cli['id'] ? 'selected' : '' ?>><?= e($cli['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($filterClient): ?>
                <a href="<?= module_url('salavip', 'ged.php') ?>" class="btn btn-outline btn-sm">Limpar</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($docs)): ?>
            <div style="text-align:center;padding:2rem;">
                <p class="text-muted text-sm">Nenhum documento encontrado.</p>
            </div>
        <?php else: ?>
            <table class="ged-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Processo</th>
                        <th>Título</th>
                        <th>Categoria</th>
                        <th>Data</th>
                        <th>Visível</th>
                        <th>Link público</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $doc):
                        $_temToken = !empty($doc['share_token']) && empty($doc['share_revogado']);
                        $_acessos  = (int)($doc['share_acessos'] ?? 0);
                    ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($doc['client_name']) ?></td>
                            <td class="text-sm text-muted"><?= $doc['case_number'] ? e($doc['case_number']) : '—' ?></td>
                            <td><?= e($doc['titulo']) ?></td>
                            <td><span class="badge badge-<?= $catBadge[$doc['categoria']] ?? 'gestao' ?>"><?= e($doc['categoria']) ?></span></td>
                            <td class="text-sm"><?= date('d/m/Y H:i', strtotime($doc['compartilhado_em'])) ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="toggle_visivel">
                                    <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                                    <button type="submit" class="ged-toggle" title="Alternar visibilidade" style="background:none;border:none;">
                                        <?= $doc['visivel_cliente'] ? '&#9989;' : '&#10060;' ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <button type="button" onclick="gedAbrirLink(<?= (int)$doc['id'] ?>, this)"
                                        class="btn btn-sm"
                                        style="font-size:.7rem;padding:.25rem .55rem;<?= $_temToken ? 'background:#0e7490;color:#fff;border:none;' : 'background:#fff;color:#0e7490;border:1px solid #0e7490;' ?>"
                                        title="<?= $_temToken ? 'Link ativo · ' . $_acessos . ' acesso(s)' : 'Gerar link para enviar ao cliente' ?>">
                                    🔗 <?= $_temToken ? ($_acessos > 0 ? $_acessos . ' acesso' . ($_acessos > 1 ? 's' : '') : 'Ativo') : 'Gerar' ?>
                                </button>
                            </td>
                            <td>
                                <div style="display:flex;gap:.3rem;">
                                    <a href="<?= module_url('salavip', 'download.php?id=' . $doc['id']) ?>" target="_blank" class="btn btn-outline btn-sm" title="Download">&#128229;</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este documento?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="excluir_ged">
                                        <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);" title="Excluir">&#128465;</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
// Load cases when client changes + verifica status da Central VIP
document.getElementById('ged_client').addEventListener('change', function(){
    var clientId = this.value;
    var caseSelect = document.getElementById('ged_case');
    var vipBox = document.getElementById('ged_vip_status');
    caseSelect.innerHTML = '<option value="">Carregando processos...</option>';
    vipBox.style.display = 'none';
    if (!clientId) { caseSelect.innerHTML = '<option value="">Selecione o cliente primeiro...</option>'; return; }

    fetch('<?= module_url('salavip', 'ged.php') ?>?ajax_cases=1&client_id=' + clientId)
        .then(function(r){ return r.json(); })
        .then(function(data){
            caseSelect.innerHTML = '<option value="">— Documento geral (sem processo) —</option>';
            if (data && data.length) {
                data.forEach(function(c){
                    var opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = (c.case_number ? c.case_number + ' — ' : '') + (c.title || 'Processo #' + c.id);
                    caseSelect.appendChild(opt);
                });
            }
        })
        .catch(function(){});

    // Verifica se o cliente tem conta ATIVA na Central VIP
    fetch('<?= module_url('salavip', 'ged.php') ?>?ajax_vip_status=1&client_id=' + clientId)
        .then(function(r){ return r.json(); })
        .then(function(d){
            vipBox.style.display = 'block';
            if (d.status === 'ativo') {
                vipBox.style.background = '#d1fae5';
                vipBox.style.border = '1px solid #34d399';
                vipBox.style.color = '#065f46';
                var quando = d.ultimo_acesso ? ' · último acesso ' + d.ultimo_acesso.substring(0, 10).split('-').reverse().join('/') : ' · ainda não logou';
                vipBox.innerHTML = '✅ <strong>Conta da Central VIP ATIVA</strong> ('+ (d.email || 'sem email') +')' + quando + ' — o cliente vai conseguir ver o documento.';
            } else if (d.status === 'inativo') {
                vipBox.style.background = '#fef3c7';
                vipBox.style.border = '1.5px solid #f59e0b';
                vipBox.style.color = '#92400e';
                vipBox.innerHTML = '⚠️ <strong>Conta INATIVA</strong> (' + (d.email || 'sem email') + ') — o cliente recebeu o convite mas <strong>não ativou a conta</strong>. O doc vai ficar invisível até ele ativar. ' +
                    '<a href="<?= module_url('salavip', 'acessos.php') ?>" target="_blank" style="color:#92400e;text-decoration:underline;font-weight:600;">Reenviar convite →</a>';
            } else {
                vipBox.style.background = '#fee2e2';
                vipBox.style.border = '1.5px solid #f87171';
                vipBox.style.color = '#7c2d12';
                vipBox.innerHTML = '❌ <strong>Cliente SEM conta na Central VIP</strong> — você precisa cadastrar o acesso primeiro, senão o doc vai ficar invisível. ' +
                    '<a href="<?= module_url('salavip') ?>" target="_blank" style="color:#7c2d12;text-decoration:underline;font-weight:600;">Cadastrar acesso →</a>';
            }
        })
        .catch(function(){ vipBox.style.display = 'none'; });
});

// ── Link público compartilhável (Amanda: enviar pelo WhatsApp) ──────
var GED_URL = '<?= module_url('salavip', 'ged.php') ?>';

function gedAbrirLink(docId, btn) {
    if (btn) { btn.disabled = true; var _txt = btn.innerHTML; btn.innerHTML = '⏳'; }
    var fd = new FormData();
    fd.append('ajax_action', 'gerar_link');
    fd.append('id', docId);
    fd.append('csrf_token', (window._FSA_CSRF || '<?= e(generate_csrf_token()) ?>'));

    fetch(GED_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (btn) { btn.disabled = false; btn.innerHTML = _txt; }
            if (d.error) { alert('Falha: ' + d.error); return; }
            if (d.csrf_expired && window.fsaMostrarSessaoExpirada) { window.fsaMostrarSessaoExpirada(); return; }
            gedMostrarModalLink(docId, d.url, d.acessos, d.ultimo, d.reativou);
        })
        .catch(function(e){
            if (btn) { btn.disabled = false; btn.innerHTML = _txt; }
            alert('Erro de conexao: ' + e.message);
        });
}

function gedMostrarModalLink(docId, url, acessos, ultimo, reativou) {
    var ultStr = ultimo ? new Date(ultimo.replace(' ', 'T')).toLocaleString('pt-BR') : '—';
    var modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';
    modal.innerHTML = '<div style="background:#fff;max-width:560px;width:100%;border-radius:12px;padding:1.4rem 1.6rem;box-shadow:0 10px 40px rgba(0,0,0,.3);border-top:4px solid #0e7490;">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">'
        + '<h3 style="margin:0;color:#0e7490;">🔗 Link público para o cliente</h3>'
        + '<button id="gedFecharLink" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;">×</button>'
        + '</div>'
        + (reativou ? '<div style="padding:.4rem .7rem;background:#dcfce7;border-left:3px solid #16a34a;border-radius:0 6px 6px 0;font-size:.78rem;color:#166534;margin-bottom:.7rem;">✓ Link reativado.</div>' : '')
        + '<p style="font-size:.83rem;color:#374151;margin:0 0 .7rem;">Envie este link pelo WhatsApp, e-mail ou SMS. Quem abrir consegue ver/baixar o arquivo sem login.</p>'
        + '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.7rem;margin-bottom:.8rem;">'
        + '<input id="gedLinkInput" type="text" readonly value="' + url + '" style="width:100%;font-family:monospace;font-size:.84rem;border:none;background:transparent;padding:.3rem 0;outline:none;color:#0e7490;">'
        + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.8rem;">'
        + '<button id="gedCopiarLink" style="background:#0e7490;color:#fff;border:none;padding:.55rem;border-radius:8px;font-weight:700;cursor:pointer;font-size:.85rem;">📋 Copiar link</button>'
        + '<a id="gedWhatsLink" href="https://wa.me/?text=' + encodeURIComponent('Olá! Segue o documento: ' + url) + '" target="_blank" rel="noopener" style="background:#25d366;color:#fff;border:none;padding:.55rem;border-radius:8px;font-weight:700;text-align:center;text-decoration:none;font-size:.85rem;">💬 Abrir WhatsApp</a>'
        + '</div>'
        + '<div style="display:flex;justify-content:space-between;align-items:center;padding-top:.7rem;border-top:1px solid #e5e7eb;font-size:.78rem;color:#6b7280;">'
        + '<div>👁 <strong>' + (acessos || 0) + '</strong> acesso' + ((acessos === 1) ? '' : 's') + ' · último: ' + ultStr + '</div>'
        + '<button id="gedRevogarLink" data-id="' + docId + '" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:.3rem .6rem;border-radius:6px;font-size:.72rem;cursor:pointer;">🚫 Revogar link</button>'
        + '</div>'
        + '<div style="margin-top:.7rem;padding:.5rem .7rem;background:#fef3c7;border-left:3px solid #f59e0b;border-radius:0 6px 6px 0;font-size:.72rem;color:#92400e;">⚠ Qualquer pessoa com este link consegue abrir o documento. Revogue se compartilhar errado.</div>'
        + '</div>';
    document.body.appendChild(modal);

    var fechar = function(){ modal.remove(); };
    modal.querySelector('#gedFecharLink').addEventListener('click', fechar);
    // Esc fecha; clique fora NAO fecha (evita perder por acidente)
    document.addEventListener('keydown', function _k(ev){ if (ev.key === 'Escape') { fechar(); document.removeEventListener('keydown', _k); } });

    modal.querySelector('#gedCopiarLink').addEventListener('click', function(){
        var inp = modal.querySelector('#gedLinkInput');
        inp.select(); inp.setSelectionRange(0, 99999);
        var ok = false;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function(){
                this.innerHTML = '✓ Copiado!';
                this.style.background = '#16a34a';
                setTimeout(function(){ try { this.innerHTML = '📋 Copiar link'; this.style.background = '#0e7490'; } catch(e){} }.bind(this), 1800);
            }.bind(this)).catch(function(){ try { document.execCommand('copy'); ok = true; } catch(e){} });
        } else {
            try { document.execCommand('copy'); this.innerHTML = '✓ Copiado!'; } catch(e){}
        }
    });

    modal.querySelector('#gedRevogarLink').addEventListener('click', function(){
        if (!confirm('Revogar o link?\n\nQuem tiver o link nao conseguira mais abrir o documento.\nVoce pode gerar um link novo depois.')) return;
        var fd = new FormData();
        fd.append('ajax_action', 'revogar_link');
        fd.append('id', docId);
        fd.append('csrf_token', (window._FSA_CSRF || '<?= e(generate_csrf_token()) ?>'));
        fetch(GED_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.error) { alert('Falha: ' + d.error); return; }
                fechar();
                location.reload();
            })
            .catch(function(e){ alert('Erro: ' + e.message); });
    });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
