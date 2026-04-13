<?php
/**
 * Ferreira & Sa Hub -- Sala VIP -- GED (Documentos para Clientes)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pdo = db();

// AJAX: buscar processos de um cliente
if (isset($_GET['ajax_cases']) && isset($_GET['client_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)$_GET['client_id'];
    $stmt = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE client_id = ? ORDER BY title");
    $stmt->execute([$cid]);
    echo json_encode($stmt->fetchAll());
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
        redirect(module_url('salavip', 'ged.php'));
    }

    // ── Toggle visibilidade ─────────────────────────────
    if ($action === 'toggle_visivel') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE salavip_ged SET visivel_cliente = NOT visivel_cliente WHERE id = ?")->execute([$id]);
        audit_log('salavip_ged_toggle_visivel', 'salavip_ged', $id);
        flash_set('success', 'Visibilidade alterada.');
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
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $doc): ?>
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
                                <div style="display:flex;gap:.3rem;">
                                    <a href="<?= url('salavip/uploads/ged/' . $doc['arquivo_path']) ?>" target="_blank" class="btn btn-outline btn-sm" title="Download">&#128229;</a>
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
// Load cases when client changes
document.getElementById('ged_client').addEventListener('change', function(){
    var clientId = this.value;
    var caseSelect = document.getElementById('ged_case');
    caseSelect.innerHTML = '<option value="">Carregando processos...</option>';
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
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
