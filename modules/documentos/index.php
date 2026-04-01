<?php
/**
 * Ferreira & Sá Hub — Documentos (Geração Automática)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Documentos';
$pdo = db();

// Cliente pré-selecionado (vindo do CRM)
$preClientId = (int)($_GET['client_id'] ?? 0);

$clients = $pdo->query("SELECT id, name, cpf, phone, email FROM clients ORDER BY name ASC")->fetchAll();

// Tipos de documento
$docTypes = array(
    'procuracao' => array('label' => 'Procuração', 'icon' => '📜', 'color' => '#052228', 'desc' => 'Ad Judicia Et Extra — tipo de ação e outorgante'),
    'contrato' => array('label' => 'Contrato de Honorários', 'icon' => '📝', 'color' => '#059669', 'desc' => 'Fixo ou risco, com todas as cláusulas'),
    'substabelecimento' => array('label' => 'Substabelecimento', 'icon' => '🔄', 'color' => '#6366f1', 'desc' => 'Com ou sem reserva de poderes'),
    'hipossuficiencia' => array('label' => 'Decl. Hipossuficiência', 'icon' => '📄', 'color' => '#d97706', 'desc' => 'Art. 98 CPC + Lei 1.060/50'),
    'isencao_ir' => array('label' => 'Decl. Isenção de IR', 'icon' => '🏦', 'color' => '#6a3c2c', 'desc' => 'IN RFB 1548/2015 + Lei 7.115/83'),
    'residencia' => array('label' => 'Decl. de Residência', 'icon' => '🏠', 'color' => '#0d9488', 'desc' => 'Comprovação de endereço — Lei 7.115/83'),
    'acordo' => array('label' => 'Termo de Acordo', 'icon' => '🤝', 'color' => '#8b5cf6', 'desc' => 'Acordo extrajudicial entre as partes'),
    'juntada' => array('label' => 'Pet. Juntada de Docs', 'icon' => '📎', 'color' => '#059669', 'desc' => 'Juntada de documentos ao processo'),
    'ciencia' => array('label' => 'Petição de Ciência', 'icon' => '👁️', 'color' => '#4f46e5', 'desc' => 'Ciência de decisão/despacho/intimação'),
);

// Histórico de documentos gerados
$docHistory = array();
try {
    $histSql = "SELECT dh.*, c.name as client_name, u.name as user_name
                FROM document_history dh
                LEFT JOIN clients c ON c.id = dh.client_id
                LEFT JOIN users u ON u.id = dh.generated_by
                ORDER BY dh.created_at DESC LIMIT 30";
    $docHistory = $pdo->query($histSql)->fetchAll();
} catch (Exception $e) { /* tabela pode não existir */ }

// Tipos de ação (para procuração e contrato)
$tiposAcao = array(
    'alimentos' => 'Processo de Fixação ou Execução de Pensão Alimentícia',
    'divorcio' => 'Ação de Divórcio',
    'guarda_convivencia' => 'Ação de Guarda e/ou Regulamentação de Convivência',
    'familia' => 'Demanda de Direito de Família',
    'consumidor' => 'Ação de Direito do Consumidor',
    'indenizacao' => 'Ação de Indenização por Danos Morais e/ou Materiais',
    'trabalhista' => 'Reclamação Trabalhista',
    'inventario' => 'Inventário e Partilha de Bens',
    'outro' => 'Outro (especificar no editor)',
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.doc-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:.75rem; margin-bottom:1.5rem; }
.doc-card {
    background:var(--bg-card); border-radius:var(--radius-lg); border:2px solid var(--border);
    padding:1.25rem; cursor:pointer; transition:all var(--transition); text-align:center;
}
.doc-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
.doc-card.selected { border-color:var(--rose); box-shadow:0 0 0 3px rgba(215,171,144,.3); }
.doc-icon { font-size:2rem; margin-bottom:.5rem; }
.doc-label { font-size:.88rem; font-weight:700; color:var(--petrol-900); }
.doc-desc { font-size:.72rem; color:var(--text-muted); margin-top:.2rem; }

.acao-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:.5rem; margin-top:.75rem; }
.acao-option {
    display:flex; align-items:center; gap:.5rem; padding:.6rem .85rem;
    border:1.5px solid var(--border); border-radius:var(--radius); cursor:pointer;
    font-size:.82rem; transition:all var(--transition); background:var(--bg-card);
}
.acao-option:hover { border-color:var(--petrol-300); }
.acao-option.selected { border-color:var(--petrol-900); background:var(--petrol-100); font-weight:600; }
.acao-option input { display:none; }

.section-box { display:none; margin-bottom:1.5rem; }
.section-box.visible { display:block; }
</style>

<div class="card">
    <div class="card-header"><h3>Gerar Documento</h3></div>
    <div class="card-body">
        <form method="GET" action="<?= module_url('documentos', 'gerar.php') ?>" id="docForm">

            <!-- 1. Tipo de documento -->
            <p class="form-label" style="font-size:.88rem;margin-bottom:.75rem;">1. Tipo de documento</p>
            <div class="doc-grid">
                <?php foreach ($docTypes as $key => $doc): ?>
                <label class="doc-card" id="doc-<?= $key ?>" onclick="selectDoc('<?= $key ?>')">
                    <input type="radio" name="tipo" value="<?= $key ?>" required style="display:none;">
                    <div class="doc-icon"><?= $doc['icon'] ?></div>
                    <div class="doc-label"><?= $doc['label'] ?></div>
                    <div class="doc-desc"><?= $doc['desc'] ?></div>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- 2. Tipo de ação (aparece para procuração e contrato) -->
            <div class="section-box" id="acaoSection">
                <p class="form-label" style="font-size:.88rem;margin-bottom:.5rem;">2. Tipo de ação</p>
                <div class="acao-grid">
                    <?php foreach ($tiposAcao as $key => $label): ?>
                    <label class="acao-option" id="acao-<?= $key ?>" onclick="selectAcao('<?= $key ?>')">
                        <input type="radio" name="tipo_acao" value="<?= $key ?>" style="width:auto;padding:0;">
                        <span><?= e($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <!-- Procuração: nome próprio ou menor -->
                <div id="outorganteSection" style="margin-top:1rem;display:none;">
                    <p class="form-label" style="font-size:.82rem;margin-bottom:.5rem;">Outorgante:</p>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <label style="flex:1;min-width:200px;display:flex;align-items:center;gap:.5rem;padding:.6rem .85rem;border:1.5px solid var(--border);border-radius:var(--radius);cursor:pointer;font-size:.82rem;">
                            <input type="radio" name="outorgante" value="proprio" checked style="width:auto;">
                            👤 Em nome próprio (cliente)
                        </label>
                        <label style="flex:1;min-width:200px;display:flex;align-items:center;gap:.5rem;padding:.6rem .85rem;border:1.5px solid var(--border);border-radius:var(--radius);cursor:pointer;font-size:.82rem;">
                            <input type="radio" name="outorgante" value="menor" style="width:auto;">
                            👶 Em nome do(s) menor(es)
                        </label>
                        <label style="flex:1;min-width:200px;display:flex;align-items:center;gap:.5rem;padding:.6rem .85rem;border:1.5px solid var(--border);border-radius:var(--radius);cursor:pointer;font-size:.82rem;">
                            <input type="radio" name="outorgante" value="defesa" style="width:auto;">
                            🛡️ Defesa (pai/mãe - execução)
                        </label>
                    </div>
                </div>
            </div>

            <!-- 3. Cliente -->
            <div class="section-box visible">
                <p class="form-label" style="font-size:.88rem;margin-bottom:.5rem;margin-top:1rem;">
                    <span id="stepNum">2</span>. Selecione o cliente
                </p>
                <div class="form-row">
                    <div class="form-group" style="margin:0;">
                        <select name="client_id" class="form-select" required id="clientSelect">
                            <option value="">— Selecionar cliente —</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $preClientId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['cpf'] ? ' — ' . e($c['cpf']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <input type="text" id="searchClient" class="form-input" placeholder="Buscar cliente..." oninput="filterClients(this.value)">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;margin-top:1.5rem;">
                Gerar Documento →
            </button>
        </form>
    </div>
</div>

<!-- Histórico de documentos gerados -->
<?php if (!empty($docHistory)): ?>
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header"><h3>Documentos Gerados Recentemente</h3></div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Cliente</th>
                    <th>Gerado por</th>
                    <th>Data</th>
                    <th style="width:80px;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docHistory as $dh): ?>
                <tr>
                    <td>
                        <span class="font-bold" style="color:var(--petrol-900);"><?= e($dh['doc_label']) ?></span>
                        <?php if ($dh['tipo_acao']): ?>
                            <br><span class="text-sm text-muted"><?= e($dh['tipo_acao']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm"><?= e($dh['client_name'] ?: '—') ?></td>
                    <td class="text-sm"><?= e($dh['user_name'] ? explode(' ', $dh['user_name'])[0] : '—') ?></td>
                    <td class="text-sm text-muted"><?= data_hora_br($dh['created_at']) ?></td>
                    <td>
                        <?php
                        $regenParams = array('tipo' => $dh['doc_type'], 'client_id' => $dh['client_id']);
                        if ($dh['tipo_acao']) $regenParams['tipo_acao'] = $dh['tipo_acao'];
                        ?>
                        <a href="<?= module_url('documentos', 'gerar.php?' . http_build_query($regenParams)) ?>" class="btn btn-outline btn-sm" title="Gerar novamente">🔄</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function selectDoc(tipo) {
    document.querySelectorAll('.doc-card').forEach(function(c) { c.classList.remove('selected'); });
    var card = document.getElementById('doc-' + tipo);
    if (card) card.classList.add('selected');
    var radio = card.querySelector('input');
    if (radio) radio.checked = true;

    var acaoSection = document.getElementById('acaoSection');
    var outorganteSection = document.getElementById('outorganteSection');
    var stepNum = document.getElementById('stepNum');

    if (tipo === 'procuracao' || tipo === 'contrato' || tipo === 'substabelecimento') {
        acaoSection.classList.add('visible');
        stepNum.textContent = '3';
        if (tipo === 'procuracao') {
            outorganteSection.style.display = 'block';
        } else {
            outorganteSection.style.display = 'none';
        }
    } else {
        acaoSection.classList.remove('visible');
        outorganteSection.style.display = 'none';
        stepNum.textContent = '2';
    }
}

function selectAcao(key) {
    document.querySelectorAll('.acao-option').forEach(function(o) { o.classList.remove('selected'); });
    var opt = document.getElementById('acao-' + key);
    if (opt) { opt.classList.add('selected'); opt.querySelector('input').checked = true; }
}

function filterClients(q) {
    q = q.toLowerCase();
    var select = document.getElementById('clientSelect');
    for (var i = 0; i < select.options.length; i++) {
        var opt = select.options[i];
        if (i === 0) continue;
        opt.style.display = (!q || opt.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
    }
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
