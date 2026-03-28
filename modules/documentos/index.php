<?php
/**
 * Ferreira & Sá Hub — Documentos (Geração Automática)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Documentos';
$pdo = db();

// Buscar clientes para o seletor
$clients = $pdo->query("SELECT id, name, cpf, phone, email FROM clients ORDER BY name ASC")->fetchAll();

// Tipos de documento
$docTypes = array(
    'procuracao' => array('label' => 'Procuração (nome próprio)', 'icon' => '📜', 'color' => '#052228', 'desc' => 'Cliente como outorgante'),
    'procuracao_menor' => array('label' => 'Procuração (menor)', 'icon' => '👶', 'color' => '#0b2f36', 'desc' => 'Criança como outorgante, representada pelo genitor'),
    'contrato' => array('label' => 'Contrato de Honorários', 'icon' => '📝', 'color' => '#059669', 'desc' => 'Contrato de prestação de serviços'),
    'hipossuficiencia' => array('label' => 'Decl. Hipossuficiência', 'icon' => '📄', 'color' => '#d97706', 'desc' => 'Declaração para gratuidade'),
    'isencao_ir' => array('label' => 'Decl. Isenção de IR', 'icon' => '🏦', 'color' => '#6a3c2c', 'desc' => 'Isenção de Imposto de Renda'),
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.doc-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1rem; margin-bottom:2rem; }
.doc-card {
    background:var(--bg-card); border-radius:var(--radius-lg); border:2px solid var(--border);
    padding:1.5rem; cursor:pointer; transition:all var(--transition); text-align:center;
}
.doc-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
.doc-card.selected { border-color:var(--rose); box-shadow:0 0 0 3px rgba(215,171,144,.3); }
.doc-icon { font-size:2.5rem; margin-bottom:.75rem; }
.doc-label { font-size:1rem; font-weight:700; color:var(--petrol-900); }
.doc-desc { font-size:.78rem; color:var(--text-muted); margin-top:.25rem; }
</style>

<div class="card mb-2">
    <div class="card-header"><h3>Gerar Documento</h3></div>
    <div class="card-body">
        <form method="GET" action="<?= module_url('documentos', 'gerar.php') ?>" id="docForm">

            <!-- 1. Escolher tipo -->
            <p class="form-label" style="font-size:.9rem;margin-bottom:.75rem;">1. Escolha o tipo de documento:</p>
            <div class="doc-grid">
                <?php foreach ($docTypes as $key => $doc): ?>
                <label class="doc-card" onclick="selectDoc('<?= $key ?>')">
                    <input type="radio" name="tipo" value="<?= $key ?>" required style="display:none;">
                    <div class="doc-icon"><?= $doc['icon'] ?></div>
                    <div class="doc-label"><?= $doc['label'] ?></div>
                    <?php if (isset($doc['desc'])): ?><div class="doc-desc"><?= $doc['desc'] ?></div><?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- 2. Escolher cliente -->
            <p class="form-label" style="font-size:.9rem;margin-bottom:.5rem;">2. Selecione o cliente:</p>
            <div class="form-row" style="margin-bottom:1.5rem;">
                <div class="form-group">
                    <select name="client_id" class="form-select" required id="clientSelect">
                        <option value="">— Selecionar cliente —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?><?= $c['cpf'] ? ' — CPF: ' . e($c['cpf']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <input type="text" id="searchClient" class="form-input" placeholder="Buscar cliente..." oninput="filterClients(this.value)">
                </div>
            </div>

            <!-- 3. Gerar -->
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">
                Gerar Documento →
            </button>
        </form>
    </div>
</div>

<script>
function selectDoc(tipo) {
    document.querySelectorAll('.doc-card').forEach(function(c) { c.classList.remove('selected'); });
    var radio = document.querySelector('input[name="tipo"][value="' + tipo + '"]');
    if (radio) {
        radio.checked = true;
        radio.closest('.doc-card').classList.add('selected');
    }
}

function filterClients(q) {
    q = q.toLowerCase();
    var select = document.getElementById('clientSelect');
    for (var i = 0; i < select.options.length; i++) {
        var opt = select.options[i];
        if (i === 0) continue;
        var text = opt.textContent.toLowerCase();
        opt.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
    }
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
