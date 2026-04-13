<?php
/**
 * Ferreira & Sá Hub — Cadastro de Novo Processo PREV
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();

// AJAX: busca de clientes
if (isset($_GET['ajax_busca_cliente'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $stmt = $pdo->prepare("SELECT id, name, cpf, phone FROM clients WHERE name LIKE ? ORDER BY name LIMIT 15");
    $stmt->execute(array('%' . $q . '%'));
    echo json_encode($stmt->fetchAll());
    exit;
}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$tiposBeneficio = array('INSS','BPC','LOAS','Aposentadoria por Idade','Aposentadoria por Invalidez','Auxílio-Doença','Auxílio-Acidente','Pensão por Morte','Salário-Maternidade');

// Pré-carregar cliente se vier via ?client_id=
$preClient = null;
if (isset($_GET['client_id']) && (int)$_GET['client_id'] > 0) {
    $stmtPre = $pdo->prepare("SELECT id, name, cpf, phone FROM clients WHERE id = ?");
    $stmtPre->execute(array((int)$_GET['client_id']));
    $preClient = $stmtPre->fetch();
}

$pageTitle = 'Novo Processo PREV';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.prev-form { max-width:680px; margin:0 auto; }
.prev-form .form-group { margin-bottom:1rem; }
.prev-form label { font-size:.78rem; font-weight:700; color:#374151; display:block; margin-bottom:.25rem; }
.prev-form .form-input, .prev-form .form-select { width:100%; padding:.55rem .75rem; font-size:.88rem; border:1.5px solid #e5e7eb; border-radius:8px; font-family:inherit; }
.prev-form .form-input:focus, .prev-form .form-select:focus { border-color:#3B4FA0; outline:none; box-shadow:0 0 0 3px rgba(59,79,160,.1); }
.prev-form .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.prev-form .btn-submit { padding:.6rem 2rem; background:#3B4FA0; color:#fff; border:none; border-radius:10px; font-size:.88rem; font-weight:700; cursor:pointer; font-family:inherit; }
.prev-form .btn-submit:hover { background:#2d3e80; }
.prev-form .autocomplete-results { position:absolute; top:100%; left:0; right:0; background:#fff; border:1.5px solid #e5e7eb; border-top:0; border-radius:0 0 8px 8px; max-height:200px; overflow-y:auto; z-index:100; box-shadow:0 4px 12px rgba(0,0,0,.1); }
.prev-form .autocomplete-item { padding:.5rem .75rem; font-size:.82rem; cursor:pointer; border-bottom:1px solid #f3f4f6; }
.prev-form .autocomplete-item:hover { background:#f0f4ff; }
</style>

<div class="prev-form">
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;">
        <a href="<?= module_url('prev') ?>" style="font-size:1.2rem;text-decoration:none;">←</a>
        <h2 style="font-size:1.1rem;font-weight:800;color:#3B4FA0;margin:0;">🏛️ Novo Processo PREV</h2>
    </div>

    <form method="POST" action="<?= module_url('prev', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create_prev_case">

        <!-- Cliente -->
        <div class="form-group" style="position:relative;">
            <label>Cliente *</label>
            <input type="hidden" name="client_id" id="clientId" value="<?= $preClient ? $preClient['id'] : '' ?>">
            <input type="text" id="clientSearch" class="form-input" placeholder="Digite o nome do cliente..."
                   value="<?= $preClient ? e($preClient['name']) : '' ?>" autocomplete="off">
            <div id="clientResults" class="autocomplete-results" style="display:none;"></div>
        </div>

        <!-- Tipo de Benefício -->
        <div class="form-group">
            <label>Tipo de Benefício *</label>
            <select name="prev_tipo_beneficio" class="form-select" required>
                <option value="">Selecione...</option>
                <?php foreach ($tiposBeneficio as $tb): ?>
                    <option value="<?= e($tb) ?>"><?= e($tb) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Título -->
        <div class="form-group">
            <label>Título do processo *</label>
            <input type="text" name="title" class="form-input" required placeholder="Ex: BPC — João Silva">
        </div>

        <div class="form-grid">
            <!-- Número do Benefício -->
            <div class="form-group">
                <label>Número do Benefício (NB)</label>
                <input type="text" name="prev_numero_beneficio" class="form-input" placeholder="Opcional">
            </div>
            <!-- Número do Processo -->
            <div class="form-group">
                <label>Número do Processo (se houver)</label>
                <input type="text" name="case_number" class="form-input" placeholder="0000000-00.0000.0.00.0000">
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label>Vara / Juízo</label>
                <input type="text" name="court" class="form-input" placeholder="Ex: 1ª Vara Federal">
            </div>
            <div class="form-group">
                <label>UF</label>
                <select name="comarca_uf" id="prevUf" class="form-select">
                    <option value="">—</option>
                    <?php foreach (array('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO') as $uf): ?>
                    <option value="<?= $uf ?>" <?= $uf === 'RJ' ? 'selected' : '' ?>><?= $uf ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Comarca</label>
                <input type="text" name="comarca" id="prevComarca" class="form-input" placeholder="Ex: Volta Redonda" list="prevListaCidades" autocomplete="off">
                <datalist id="prevListaCidades"></datalist>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label>Prioridade</label>
                <select name="priority" class="form-select">
                    <option value="normal">Normal</option>
                    <option value="baixa">Baixa</option>
                    <option value="alta">Alta</option>
                    <option value="urgente">Urgente</option>
                </select>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label>Responsável</label>
                <select name="responsible_user_id" class="form-select">
                    <option value="">— Definir depois —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Pasta Google Drive</label>
                <input type="text" name="drive_folder_url" class="form-input" placeholder="https://drive.google.com/...">
            </div>
        </div>

        <div class="form-group">
            <label>Observações</label>
            <textarea name="notes" class="form-input" rows="3" style="resize:vertical;" placeholder="Informações adicionais..."></textarea>
        </div>

        <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem;">
            <a href="<?= module_url('prev') ?>" class="btn btn-outline" style="padding:.6rem 1.5rem;">Cancelar</a>
            <button type="submit" class="btn-submit">Cadastrar Processo PREV</button>
        </div>
    </form>
</div>

<script>
// Autocomplete de clientes
(function(){
    var input = document.getElementById('clientSearch');
    var hidden = document.getElementById('clientId');
    var results = document.getElementById('clientResults');
    var timer = null;

    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '<?= module_url("prev", "caso_novo.php") ?>?ajax_busca_cliente=1&q=' + encodeURIComponent(q));
            xhr.onload = function() {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (!data.length) { results.style.display = 'none'; return; }
                    var html = '';
                    for (var i = 0; i < data.length; i++) {
                        var c = data[i];
                        html += '<div class="autocomplete-item" data-id="' + c.id + '" data-name="' + esc(c.name) + '">';
                        html += '<strong>' + esc(c.name) + '</strong>';
                        if (c.cpf) html += ' <span style="color:#6b7280;font-size:.72rem;">— ' + esc(c.cpf) + '</span>';
                        if (c.phone) html += ' <span style="color:#6b7280;font-size:.72rem;">📞 ' + esc(c.phone) + '</span>';
                        html += '</div>';
                    }
                    results.innerHTML = html;
                    results.style.display = 'block';

                    results.querySelectorAll('.autocomplete-item').forEach(function(item) {
                        item.addEventListener('click', function() {
                            hidden.value = item.dataset.id;
                            input.value = item.dataset.name;
                            results.style.display = 'none';
                        });
                    });
                } catch(e) {}
            };
            xhr.send();
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!results.contains(e.target) && e.target !== input) results.style.display = 'none';
    });

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
})();
</script>
<script src="<?= url('assets/js/ibge_cidades.js') ?>"></script>
<script>ibgeCidades('prevUf', 'prevComarca', 'prevListaCidades');</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
