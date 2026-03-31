<?php
/**
 * Ferreira & Sá Hub — Formulário de Lead
 * Suporta: novo lead, editar, puxar de cliente existente, duplicar lead
 */
require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$errors = [];
$lead = null;

$editId = (int)($_GET['id'] ?? 0);
$fromClient = (int)($_GET['client_id'] ?? 0);
$duplicateId = (int)($_GET['duplicate'] ?? 0);

// AJAX: buscar clientes
if (isset($_GET['ajax_busca'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $stmt = $pdo->prepare("SELECT id, name, phone, email, cpf FROM clients WHERE name LIKE ? ORDER BY name LIMIT 10");
    $stmt->execute(array('%' . $q . '%'));
    echo json_encode($stmt->fetchAll());
    exit;
}

// AJAX: buscar leads para duplicar
if (isset($_GET['ajax_leads'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $stmt = $pdo->prepare("SELECT id, name, phone, email, case_type, stage FROM pipeline_leads WHERE name LIKE ? AND stage NOT IN ('finalizado','perdido') ORDER BY name LIMIT 10");
    $stmt->execute(array('%' . $q . '%'));
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
    $stmt->execute([$editId]);
    $lead = $stmt->fetch();
    if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }
    $pageTitle = 'Editar Lead';
} else {
    $pageTitle = 'Novo Lead';
}

// Pré-preencher de cliente existente
$preClient = null;
if ($fromClient) {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute(array($fromClient));
    $preClient = $stmt->fetch();
}

// Pré-preencher de lead existente (duplicar)
$preDuplicate = null;
if ($duplicateId) {
    $stmt = $pdo->prepare("SELECT * FROM pipeline_leads WHERE id = ?");
    $stmt->execute(array($duplicateId));
    $preDuplicate = $stmt->fetch();
}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { $errors[] = 'Token inválido.'; }

    $f = [
        'name'      => clean_str($_POST['name'] ?? '', 150),
        'phone'     => clean_str($_POST['phone'] ?? '', 40),
        'email'     => trim($_POST['email'] ?? ''),
        'source'    => $_POST['source'] ?? 'outro',
        'case_type' => clean_str($_POST['case_type'] ?? '', 60),
        'estimated_value' => (int)str_replace(['.', ','], ['', ''], $_POST['estimated_value'] ?? '0'),
        'assigned_to' => (int)($_POST['assigned_to'] ?? 0) ?: null,
        'notes'     => clean_str($_POST['notes'] ?? '', 2000),
    ];

    if (empty($f['name'])) $errors[] = 'Nome é obrigatório.';
    $validSources = ['calculadora','landing','indicacao','instagram','google','whatsapp','outro'];
    if (!in_array($f['source'], $validSources)) $f['source'] = 'outro';

    if (empty($errors)) {
        if ($editId) {
            $pdo->prepare(
                'UPDATE pipeline_leads SET name=?, phone=?, email=?, source=?, case_type=?,
                 estimated_value_cents=?, assigned_to=?, notes=?, updated_at=NOW() WHERE id=?'
            )->execute([
                $f['name'], $f['phone'] ?: null, $f['email'] ?: null, $f['source'],
                $f['case_type'] ?: null, $f['estimated_value'] ?: null,
                $f['assigned_to'], $f['notes'] ?: null, $editId
            ]);
            audit_log('lead_updated', 'lead', $editId);
            flash_set('success', 'Lead atualizado.');
        } else {
            $clientIdFromPost = (int)($_POST['client_id'] ?? 0);

            $pdo->prepare(
                'INSERT INTO pipeline_leads (client_id, name, phone, email, source, stage, case_type, estimated_value_cents, assigned_to, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $clientIdFromPost ?: null,
                $f['name'], $f['phone'] ?: null, $f['email'] ?: null, $f['source'],
                'cadastro_preenchido', $f['case_type'] ?: null, $f['estimated_value'] ?: null,
                $f['assigned_to'], $f['notes'] ?: null
            ]);
            $newId = (int)$pdo->lastInsertId();

            // Auto-criar client se não vinculou a existente
            if (!$clientIdFromPost && $f['name']) {
                $existsClient = $pdo->prepare("SELECT id FROM clients WHERE name = ? LIMIT 1");
                $existsClient->execute(array($f['name']));
                $existsRow = $existsClient->fetch();
                if ($existsRow) {
                    $clientIdFromPost = (int)$existsRow['id'];
                } else {
                    $pdo->prepare("INSERT INTO clients (name, phone, email, source, client_status, created_at) VALUES (?,?,?,'outro','ativo',NOW())")
                        ->execute(array($f['name'], $f['phone'] ?: null, $f['email'] ?: null));
                    $clientIdFromPost = (int)$pdo->lastInsertId();
                }
                $pdo->prepare("UPDATE pipeline_leads SET client_id = ? WHERE id = ?")->execute(array($clientIdFromPost, $newId));
            }

            $pdo->prepare('INSERT INTO pipeline_history (lead_id, to_stage, changed_by) VALUES (?,?,?)')
                ->execute([$newId, 'cadastro_preenchido', current_user_id()]);

            audit_log('lead_created', 'lead', $newId);
            flash_set('success', 'Lead criado.');
        }
        redirect(module_url('pipeline'));
    }
} else {
    if ($preClient) {
        $f = [
            'name' => $preClient['name'] ?? '', 'phone' => $preClient['phone'] ?? '',
            'email' => $preClient['email'] ?? '', 'source' => 'outro', 'case_type' => '',
            'estimated_value' => '', 'assigned_to' => '', 'notes' => '',
        ];
    } elseif ($preDuplicate) {
        $f = [
            'name' => $preDuplicate['name'] ?? '', 'phone' => $preDuplicate['phone'] ?? '',
            'email' => $preDuplicate['email'] ?? '', 'source' => $preDuplicate['source'] ?? 'outro',
            'case_type' => '', 'estimated_value' => '', 'assigned_to' => $preDuplicate['assigned_to'] ?? '',
            'notes' => 'Nova ação (duplicado de lead #' . $duplicateId . ')',
        ];
    } else {
        $f = [
            'name' => $lead['name'] ?? '', 'phone' => $lead['phone'] ?? '',
            'email' => $lead['email'] ?? '', 'source' => $lead['source'] ?? 'outro',
            'case_type' => $lead['case_type'] ?? '', 'estimated_value' => $lead['estimated_value_cents'] ?? '',
            'assigned_to' => $lead['assigned_to'] ?? '', 'notes' => $lead['notes'] ?? '',
        ];
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width: 640px;">
    <a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><span class="alert-icon">✕</span><div><?= implode('<br>', array_map('e', $errors)) ?></div></div>
    <?php endif; ?>

    <?php if (!$editId): ?>
    <!-- Atalhos: puxar de cliente ou duplicar lead -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="display:flex;gap:1rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;position:relative;">
                <label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.25rem;">Puxar de cliente cadastrado</label>
                <input type="text" id="buscaClient" placeholder="Digite o nome..." autocomplete="off" style="width:100%;padding:.5rem .75rem;font-size:.85rem;border:2px solid var(--rose);border-radius:8px;">
                <div id="buscaClientRes" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:10;background:#fff;border:1.5px solid var(--border);border-radius:0 0 8px 8px;max-height:180px;overflow-y:auto;box-shadow:var(--shadow-md);"></div>
            </div>
            <div style="flex:1;min-width:200px;position:relative;">
                <label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.25rem;">Duplicar lead existente</label>
                <input type="text" id="buscaLead" placeholder="Digite o nome do lead..." autocomplete="off" style="width:100%;padding:.5rem .75rem;font-size:.85rem;border:1.5px solid #0ea5e9;border-radius:8px;">
                <div id="buscaLeadRes" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:10;background:#fff;border:1.5px solid var(--border);border-radius:0 0 8px 8px;max-height:180px;overflow-y:auto;box-shadow:var(--shadow-md);"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="client_id" id="hiddenClientId" value="<?= $fromClient ?: ($preClient['id'] ?? ($preDuplicate['client_id'] ?? '')) ?>">

            <div class="form-group">
                <label class="form-label">Nome do lead *</label>
                <input type="text" name="name" id="fieldName" class="form-input" value="<?= e($f['name']) ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Telefone / WhatsApp</label>
                    <input type="text" name="phone" id="fieldPhone" class="form-input" value="<?= e($f['phone']) ?>" placeholder="(00) 00000-0000">
                </div>
                <div class="form-group">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" id="fieldEmail" class="form-input" value="<?= e($f['email']) ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Origem</label>
                    <select name="source" class="form-select">
                        <?php foreach (array('outro'=>'Outro','indicacao'=>'Indicação','instagram'=>'Instagram','google'=>'Google','whatsapp'=>'WhatsApp','landing'=>'Site/Landing','calculadora'=>'Calculadora') as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= $f['source'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo de ação</label>
                    <input type="text" name="case_type" class="form-input" value="<?= e($f['case_type']) ?>" placeholder="Ex: Alimentos, Divórcio...">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Valor estimado (centavos)</label>
                    <input type="number" name="estimated_value" class="form-input" value="<?= e($f['estimated_value']) ?>" placeholder="0">
                    <span class="form-hint">Em centavos. Ex: 500000 = R$ 5.000,00</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Responsável</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">— Selecionar —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= (int)$f['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Observações</label>
                <textarea name="notes" class="form-textarea" rows="3"><?= e($f['notes']) ?></textarea>
            </div>

            <div class="card-footer" style="border-top:none;padding:1rem 0 0;">
                <a href="<?= module_url('pipeline') ?>" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary"><?= $editId ? 'Salvar' : 'Criar Lead' ?></button>
            </div>
        </form>
    </div></div>
</div>

<?php if (!$editId): ?>
<script>
// Busca de clientes
(function() {
    var input = document.getElementById('buscaClient');
    var results = document.getElementById('buscaClientRes');
    var timer;
    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '<?= module_url("pipeline", "lead_form.php") ?>?ajax_busca=1&q=' + encodeURIComponent(q));
            xhr.onload = function() {
                var data = JSON.parse(xhr.responseText);
                if (!data.length) { results.innerHTML = '<div style="padding:8px 12px;font-size:.82rem;color:var(--text-muted);">Nenhum</div>'; results.style.display = 'block'; return; }
                results.innerHTML = data.map(function(cl) {
                    return '<div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;font-size:.82rem;" onmouseover="this.style.background=\'rgba(215,171,144,.15)\'" onmouseout="this.style.background=\'#fff\'" onclick="selectClient(' + cl.id + ',\'' + (cl.name||'').replace(/'/g,"\\'") + '\',\'' + (cl.phone||'').replace(/'/g,"\\'") + '\',\'' + (cl.email||'').replace(/'/g,"\\'") + '\')"><strong>' + (cl.name||'') + '</strong>' + (cl.phone ? ' — ' + cl.phone : '') + '</div>';
                }).join('');
                results.style.display = 'block';
            };
            xhr.send();
        }, 300);
    });
    document.addEventListener('click', function(e) { if (!input.contains(e.target) && !results.contains(e.target)) results.style.display = 'none'; });
})();

function selectClient(id, name, phone, email) {
    document.getElementById('buscaClientRes').style.display = 'none';
    document.getElementById('hiddenClientId').value = id;
    document.getElementById('fieldName').value = name;
    document.getElementById('fieldPhone').value = phone;
    document.getElementById('fieldEmail').value = email;
    document.getElementById('buscaClient').value = name;
}

// Busca de leads para duplicar
(function() {
    var input = document.getElementById('buscaLead');
    var results = document.getElementById('buscaLeadRes');
    var timer;
    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '<?= module_url("pipeline", "lead_form.php") ?>?ajax_leads=1&q=' + encodeURIComponent(q));
            xhr.onload = function() {
                var data = JSON.parse(xhr.responseText);
                if (!data.length) { results.innerHTML = '<div style="padding:8px 12px;font-size:.82rem;color:var(--text-muted);">Nenhum</div>'; results.style.display = 'block'; return; }
                results.innerHTML = data.map(function(l) {
                    return '<div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;font-size:.82rem;" onmouseover="this.style.background=\'rgba(14,165,233,.1)\'" onmouseout="this.style.background=\'#fff\'" onclick="window.location=\'<?= module_url("pipeline", "lead_form.php") ?>?duplicate=' + l.id + '\'"><strong>' + (l.name||'') + '</strong>' + (l.case_type ? ' — ' + l.case_type : '') + ' <span style="color:var(--text-muted);font-size:.7rem;">' + (l.stage||'') + '</span></div>';
                }).join('');
                results.style.display = 'block';
            };
            xhr.send();
        }, 300);
    });
    document.addEventListener('click', function(e) { if (!input.contains(e.target) && !results.contains(e.target)) results.style.display = 'none'; });
})();
</script>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
