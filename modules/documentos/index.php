<?php
/**
 * Ferreira & Sá Hub — Documentos (Geração Automática)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('documentos');

$pageTitle = 'Documentos';
$pdo = db();

// Cliente e caso pré-selecionados (vindo do processo)
$preClientId = (int)($_GET['client_id'] ?? 0);
$preCaseId = (int)($_GET['case_id'] ?? 0);

$clients = $pdo->query("SELECT id, name, cpf, phone, email FROM clients ORDER BY name ASC")->fetchAll();

// Tipos de documento
$docTypes = array(
    'procuracao' => array('label' => 'Procuração', 'icon' => '📜', 'color' => '#052228', 'desc' => 'Ad Judicia Et Extra — tipo de ação e outorgante'),
    'contrato' => array('label' => 'Contrato de Honorários', 'icon' => '📝', 'color' => '#059669', 'desc' => 'Honorários fixos, risco ou previdenciário (Salário-Maternidade)'),
    'substabelecimento' => array('label' => 'Substabelecimento', 'icon' => '🔄', 'color' => '#6366f1', 'desc' => 'Com ou sem reserva de poderes'),
    'hipossuficiencia' => array('label' => 'Decl. Hipossuficiência', 'icon' => '📄', 'color' => '#d97706', 'desc' => 'Art. 98 CPC + Lei 1.060/50'),
    'isencao_ir' => array('label' => 'Decl. Isenção de IR', 'icon' => '🏦', 'color' => '#6a3c2c', 'desc' => 'IN RFB 1548/2015 + Lei 7.115/83'),
    'residencia' => array('label' => 'Decl. de Residência', 'icon' => '🏠', 'color' => '#0d9488', 'desc' => 'Comprovação de endereço — Lei 7.115/83'),
    'acordo' => array('label' => 'Termo de Acordo', 'icon' => '🤝', 'color' => '#8b5cf6', 'desc' => 'Acordo extrajudicial entre as partes'),
    'juntada' => array('label' => 'Pet. Juntada de Docs', 'icon' => '📎', 'color' => '#059669', 'desc' => 'Juntada de documentos ao processo'),
    'ciencia' => array('label' => 'Petição de Ciência', 'icon' => '👁️', 'color' => '#4f46e5', 'desc' => 'Ciência de decisão/despacho/intimação'),
    'prevjud' => array('label' => 'Pesquisa PREVJUD', 'icon' => '🔍', 'color' => '#052228', 'desc' => 'Pesquisa de vínculo empregatício via PREVJUD'),
    'citacao_whatsapp' => array('label' => 'Citação por WhatsApp', 'icon' => '💬', 'color' => '#25D366', 'desc' => 'Petição requerendo citação do réu via WhatsApp — Art. 246, V, CPC'),
    'habilitacao' => array('label' => 'Petição de Habilitação', 'icon' => '📋', 'color' => '#7c3aed', 'desc' => 'Habilitação nos autos para atuação no processo (procuração em anexo)'),
    'audiencia_remota' => array('label' => 'Audiência Remota/Híbrida', 'icon' => '🖥️', 'color' => '#0ea5e9', 'desc' => 'Requer realização de audiência por videoconferência ou de forma híbrida'),
    'mandado_pagamento' => array('label' => 'Mandado de Pagamento', 'icon' => '💰', 'color' => '#059669', 'desc' => 'Requer expedição de mandado de pagamento eletrônico do depósito judicial'),
    'averbacao_sentenca' => array('label' => 'Averbação Sentença — Divórcio', 'icon' => '💔', 'color' => '#9333ea', 'desc' => 'Ciência da sentença + renúncia ao prazo recursal + requer expedição da Carta de Sentença via Malote Digital ao RCPN — Aviso CGJ 154/2021'),
    'renuncia_poderes' => array('label' => 'Renúncia aos Poderes', 'icon' => '🚪', 'color' => '#dc2626', 'desc' => 'Petição de renúncia ao mandato — Art. 112 CPC + Art. 5º, §3º Estatuto da OAB'),
    'desistencia_acao' => array('label' => 'Desistência da Ação', 'icon' => '🛑', 'color' => '#b91c1c', 'desc' => 'Pedido de desistência por foro íntimo — com ou sem prévia anuência da Ré (Art. 485, VIII e §4º CPC)'),
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

// Tipos de ação (para procuração e contrato), agrupados por categoria
$tiposAcaoGrupos = array(
    'familia' => array(
        'label' => '👨‍👩‍👧 Família',
        'itens' => array(
            'alimentos' => 'Processo de Fixação ou Execução de Pensão Alimentícia',
            'divorcio' => 'Ação de Divórcio',
            'guarda_convivencia' => 'Ação de Guarda e/ou Regulamentação de Convivência',
            'familia' => 'Demanda de Direito de Família',
            'inventario' => 'Inventário e Partilha de Bens',
        ),
    ),
    'previdenciario' => array(
        'label' => '🤰 Previdenciário',
        'itens' => array(
            'salario_maternidade' => 'Salário-Maternidade (30% sobre 4 parcelas)',
        ),
    ),
    'consumidor' => array(
        'label' => '🛍️ Consumidor',
        'itens' => array(
            'consumidor' => 'Ação de Direito do Consumidor',
        ),
    ),
    'civel' => array(
        'label' => '⚖️ Cível',
        'itens' => array(
            'indenizacao' => 'Ação de Indenização por Danos Morais e/ou Materiais',
        ),
    ),
    'trabalho' => array(
        'label' => '💼 Trabalhista',
        'itens' => array(
            'trabalhista' => 'Reclamação Trabalhista',
        ),
    ),
    'outro' => array(
        'label' => '📌 Outro',
        'itens' => array(
            'outro' => 'Outro (especificar no editor)',
        ),
    ),
);

require_once APP_ROOT . '/templates/layout_start.php';
echo voltar_ao_processo_html();
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

/* Categorias de tipos de ação */
.acao-cat { margin-top:1rem; }
.acao-cat:first-child { margin-top:.25rem; }
.acao-cat-titulo {
    font-size:.78rem; font-weight:700; color:var(--petrol-900);
    text-transform:uppercase; letter-spacing:.04em;
    padding:.35rem .6rem; margin-bottom:.4rem;
    background:linear-gradient(90deg, rgba(215,171,144,.18), transparent);
    border-left:3px solid var(--rose); border-radius:4px;
}
</style>

<div class="card">
    <div class="card-header"><h3>Gerar Documento</h3></div>
    <div class="card-body">
        <form method="GET" action="<?= module_url('documentos', 'gerar.php') ?>" id="docForm">
            <?php if ($preCaseId): ?><input type="hidden" name="case_id" value="<?= $preCaseId ?>"><?php endif; ?>

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
                <?php foreach ($tiposAcaoGrupos as $catKey => $cat): ?>
                <div class="acao-cat">
                    <div class="acao-cat-titulo"><?= e($cat['label']) ?></div>
                    <div class="acao-grid">
                        <?php foreach ($cat['itens'] as $key => $label): ?>
                        <label class="acao-option" id="acao-<?= $key ?>" onclick="selectAcao('<?= $key ?>')">
                            <input type="radio" name="tipo_acao" value="<?= $key ?>" style="width:auto;padding:0;">
                            <span><?= e($label) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

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
                    <td class="text-sm">
                        <?php if ($dh['client_id'] && $dh['client_name']): ?>
                            <a href="<?= module_url('clientes', 'ver.php?id=' . (int)$dh['client_id']) ?>" style="color:var(--petrol-900);text-decoration:none;border-bottom:1px dashed var(--petrol-900);" title="Ver perfil do cliente"><?= e($dh['client_name']) ?></a>
                        <?php else: ?>
                            <?= e($dh['client_name'] ?: '—') ?>
                        <?php endif; ?>
                    </td>
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
        outorganteSection.style.display = (tipo === 'procuracao') ? 'block' : 'none';
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
