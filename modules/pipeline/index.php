<?php
/**
 * Ferreira & Sá Hub — Kanban Comercial II (Kanban)
 * Fluxo: Cadastro → Elaboração → Link Enviados → Contrato Assinado →
 *        Agendado/Docs → Reunião/Cobrança → Doc Faltante → Pasta Apta
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_view_pipeline()) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'Kanban Comercial II';
$pdo = db();

// Estágios do funil (conforme doc técnico)
$stages = array(
    'cadastro_preenchido' => array('label' => 'Cadastro Preenchido',        'color' => '#6366f1', 'icon' => '📋', 'resp' => 'Auto'),
    'elaboracao_docs'     => array('label' => 'Elaboração Procuração/Contrato', 'color' => '#0ea5e9', 'icon' => '📝', 'resp' => 'Comercial'),
    'link_enviados'       => array('label' => 'Link Enviados',              'color' => '#f59e0b', 'icon' => '📨', 'resp' => 'Comercial'),
    'contrato_assinado'   => array('label' => 'Contrato Assinado',          'color' => '#059669', 'icon' => '✅', 'resp' => 'Comercial'),
    'agendado_docs'       => array('label' => 'Agendado + Docs Solicitados','color' => '#0d9488', 'icon' => '📅', 'resp' => 'CX'),
    'reuniao_cobranca'    => array('label' => 'Reunião / Cobrando Docs',    'color' => '#d97706', 'icon' => '🤝', 'resp' => 'CX'),
    'doc_faltante'        => array('label' => 'Documento Faltante',         'color' => '#dc2626', 'icon' => '⚠️', 'resp' => 'Auto'),
    'pasta_apta'          => array('label' => 'Pasta Apta',                 'color' => '#15803d', 'icon' => '✔️', 'resp' => 'CX'),
    // 'cancelado' removido do Kanban — aparece apenas nos Relatórios
    'suspenso'            => array('label' => 'Suspenso',                   'color' => '#9ca3af', 'icon' => '⏸️', 'resp' => 'Admin'),
);

// Filtros
$searchPipeline = isset($_GET['q']) ? trim($_GET['q']) : '';
$filterMonth = isset($_GET['mes']) ? $_GET['mes'] : '';

// Buscar leads (exceto finalizados)
$pipeWhere = "pl.stage NOT IN ('finalizado','perdido','cancelado')";
$pipeParams = array();
if ($searchPipeline) {
    $pipeWhere .= " AND (pl.name LIKE ? OR pl.phone LIKE ? OR pl.case_type LIKE ?)";
    $s = "%$searchPipeline%";
    $pipeParams = array($s, $s, $s);
}
if ($filterMonth) {
    $pipeWhere .= " AND DATE_FORMAT(pl.created_at, '%Y-%m') = ?";
    $pipeParams[] = $filterMonth;
}

$stmt = $pdo->prepare(
    "SELECT pl.*, u.name as assigned_name, c.name as client_name,
     DATEDIFF(NOW(), pl.created_at) as days_in_pipeline,
     cs.drive_folder_url
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     LEFT JOIN clients c ON c.id = pl.client_id
     LEFT JOIN cases cs ON cs.id = pl.linked_case_id
     WHERE $pipeWhere
     ORDER BY pl.updated_at DESC"
);
$stmt->execute($pipeParams);
$leads = $stmt->fetchAll();

$byStage = array();
foreach (array_keys($stages) as $s) { $byStage[$s] = array(); }
foreach ($leads as $lead) {
    $st = $lead['stage'];
    if (isset($byStage[$st])) {
        $byStage[$st][] = $lead;
    }
}

// KPIs
$totalAtivos = count($leads);
$contratosAssinados = count($byStage['contrato_assinado']) + count($byStage['agendado_docs']) + count($byStage['reuniao_cobranca']) + count($byStage['pasta_apta']);
$pastasAptas = count($byStage['pasta_apta']);
$docsFaltantes = count($byStage['doc_faltante']);

// Documentos pendentes (para banner)
$docsPendentes = array();
try {
    $docsPendentes = $pdo->query(
        "SELECT dp.*, c.name as client_name
         FROM documentos_pendentes dp
         LEFT JOIN clients c ON c.id = dp.client_id
         WHERE dp.status = 'pendente'
         ORDER BY dp.solicitado_em DESC"
    )->fetchAll();
} catch (Exception $e) {}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pipeline-stats { display:flex; gap:1rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.pipeline-stats .stat-card { flex:1; min-width:140px; }
.page-content { max-width:none !important; padding:.75rem !important; overflow-x:auto; }

.kanban-header { padding:.6rem .85rem; border-radius:var(--radius) var(--radius) 0 0; color:#fff; font-weight:700; font-size:.72rem; display:flex; align-items:center; justify-content:space-between; }
.kanban-header .count { background:rgba(255,255,255,.25); padding:.1rem .5rem; border-radius:100px; font-size:.65rem; }
.kanban-header .resp { font-size:.55rem; opacity:.7; font-weight:400; }
.kanban-body { flex:1; background:var(--bg); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius) var(--radius); padding:.4rem; display:flex; flex-direction:column; gap:.4rem; min-height:80px; }
.kanban-body.drag-over { background:rgba(215,171,144,.15); border:2px dashed var(--rose); }

.lead-card { background:var(--bg-card); border-radius:var(--radius); padding:.6rem .7rem; box-shadow:var(--shadow-sm); border-left:4px solid #ccc; cursor:grab; transition:all var(--transition); overflow:hidden; }
.lead-card:hover { box-shadow:var(--shadow-md); transform:translateY(-1px); }
.lead-card.dragging { opacity:.4; cursor:grabbing; }
.lead-name { font-weight:700; font-size:.8rem; color:var(--petrol-900); margin-bottom:.2rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lead-meta { font-size:.65rem; color:var(--text-muted); display:flex; flex-direction:column; gap:.1rem; }
.lead-meta .phone { color:var(--success); }
.lead-days { font-size:.58rem; background:rgba(0,0,0,.05); padding:.1rem .35rem; border-radius:6px; display:inline-block; margin-top:.25rem; }
.lead-doc-alert { background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:.3rem .5rem; font-size:.6rem; color:#dc2626; font-weight:600; margin-top:.3rem; }
.lead-actions { display:flex; gap:.25rem; margin-top:.4rem; align-items:center; }
.lead-actions select { font-size:.62rem; padding:.2rem .2rem; border:1px solid var(--border); border-radius:6px; background:var(--bg-card); cursor:pointer; max-width:100%; flex:1; }
.lead-del { background:none; border:none; cursor:pointer; font-size:.75rem; padding:.15rem; opacity:.4; }
.lead-del:hover { opacity:1; }
</style>

<!-- Banner: Documentos Pendentes (colapsável) -->
<?php if (!empty($docsPendentes)):
    $docsByClientP = array();
    foreach ($docsPendentes as $dp) {
        $key = $dp['client_name'] ?: 'Cliente';
        if (!isset($docsByClientP[$key])) $docsByClientP[$key] = array();
        $docsByClientP[$key][] = $dp;
    }
?>
<div style="background:#fef2f2;border:2px solid #fecaca;border-radius:12px;padding:.75rem 1rem;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;" onclick="var el=document.getElementById('docsExpandP');el.style.display=el.style.display==='none'?'block':'none';this.querySelector('.chevron').textContent=el.style.display==='none'?'▸':'▾';">
        <div style="display:flex;align-items:center;gap:.5rem;">
            <span style="font-size:1rem;">⚠️</span>
            <strong style="font-size:.82rem;color:#dc2626;"><?= count($docsPendentes) ?> doc(s) faltante(s) — CX precisa providenciar</strong>
        </div>
        <span class="chevron" style="font-size:.8rem;color:#dc2626;">▸</span>
    </div>
    <div id="docsExpandP" style="display:none;margin-top:.5rem;">
        <?php foreach ($docsByClientP as $clientName => $docs): ?>
        <div style="padding:.4rem 0;border-top:1px solid #fecaca;">
            <div style="font-size:.78rem;font-weight:700;color:#052228;">👤 <?= e($clientName) ?></div>
            <?php foreach ($docs as $dp): ?>
            <div style="display:flex;align-items:center;gap:.5rem;padding:.15rem 0 .15rem 1.2rem;">
                <span style="font-size:.72rem;color:#dc2626;font-weight:600;">→ <?= e($dp['descricao']) ?></span>
                <span style="font-size:.6rem;color:#6b7280;margin-left:auto;"><?= date('d/m H:i', strtotime($dp['solicitado_em'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="pipeline-stats">
    <div class="stat-card"><div class="stat-icon info">📋</div><div class="stat-info"><div class="stat-value"><?= $totalAtivos ?></div><div class="stat-label">No funil</div></div></div>
    <div class="stat-card"><div class="stat-icon success">✅</div><div class="stat-info"><div class="stat-value"><?= $contratosAssinados ?></div><div class="stat-label">Pós-contrato</div></div></div>
    <div class="stat-card"><div class="stat-icon rose">✔️</div><div class="stat-info"><div class="stat-value"><?= $pastasAptas ?></div><div class="stat-label">Pastas aptas</div></div></div>
    <?php if ($docsFaltantes > 0): ?>
    <div class="stat-card"><div class="stat-icon danger">⚠️</div><div class="stat-info"><div class="stat-value"><?= $docsFaltantes ?></div><div class="stat-label">Doc faltante</div></div></div>
    <?php endif; ?>
</div>

<!-- Toggle + Ações -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;">
        <h3 style="font-size:1rem;font-weight:700;color:var(--petrol-900);margin:0;">Kanban Comercial II</h3>
        <div style="display:flex;border:2px solid var(--petrol-900);border-radius:10px;overflow:hidden;">
            <button onclick="toggleView('kanban')" id="btnKanban" style="padding:7px 18px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:var(--petrol-900);color:#fff;transition:all .2s;">📋 Kanban</button>
            <button onclick="toggleView('tabela')" id="btnTabela" style="padding:7px 18px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:#fff;color:var(--petrol-900);transition:all .2s;">📊 Tabela</button>
        </div>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:.4rem;align-items:center;">
            <input type="text" name="q" value="<?= e($searchPipeline) ?>" placeholder="Buscar nome..." style="font-size:.78rem;padding:5px 10px;border:1.5px solid var(--border);border-radius:8px;width:150px;" onkeydown="if(event.key==='Enter')this.form.submit()">
            <input type="month" name="mes" value="<?= e($filterMonth) ?>" style="font-size:.72rem;padding:5px 8px;border:1.5px solid var(--border);border-radius:8px;" onchange="this.form.submit()">
            <?php if ($searchPipeline || $filterMonth): ?>
                <a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm" style="font-size:.65rem;">Limpar</a>
            <?php endif; ?>
        </form>
        <a href="<?= module_url('planilha', 'importar.php?destino=pipeline') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Importar CSV</a>
        <a href="<?= module_url('pipeline', 'lead_form.php') ?>" class="btn btn-primary btn-sm">+ Novo Lead</a>
        <a href="<?= module_url('pipeline', 'perdidos.php') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Perdidos</a>
    </div>
</div>

<!-- Kanban -->
<div id="viewKanban" style="display:grid;grid-template-columns:repeat(<?= count($stages) ?>,minmax(155px,1fr));gap:.4rem;min-height:400px;overflow-x:auto;">
    <?php foreach ($stages as $stageKey => $stage): ?>
    <div style="display:flex;flex-direction:column;min-width:0;">
        <div class="kanban-header" style="background:<?= $stage['color'] ?>;">
            <span><?= $stage['icon'] ?> <?= $stage['label'] ?></span>
            <span class="count"><?= count($byStage[$stageKey]) ?></span>
        </div>
        <div class="kanban-body" data-stage="<?= $stageKey ?>">
            <?php if (empty($byStage[$stageKey])): ?>
                <div style="text-align:center;padding:1rem .5rem;color:var(--text-muted);font-size:.72rem;">Nenhum</div>
            <?php else: ?>
                <?php foreach ($byStage[$stageKey] as $lead): ?>
                <div class="lead-card" draggable="true" data-lead-id="<?= $lead['id'] ?>" style="border-left-color:<?= $stage['color'] ?>;"
                     onclick="if(!window._dragging&&!event.target.closest('.lead-actions'))window.location='<?= module_url('pipeline', 'lead_ver.php?id=' . $lead['id']) ?>'">
                    <div class="lead-name"><?= e($lead['name']) ?></div>
                    <div class="lead-meta">
                        <?php if ($lead['phone']): ?><span class="phone">📱 <?= e($lead['phone']) ?></span><?php endif; ?>
                        <?php if ($lead['case_type']): ?><span>📁 <?= e($lead['case_type']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($lead['assigned_name']): ?>
                        <div style="font-size:.6rem;color:var(--rose-dark);font-weight:600;margin-top:.15rem;">👤 <?= e(explode(' ', $lead['assigned_name'])[0]) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($lead['drive_folder_url'])): ?>
                        <a href="<?= e($lead['drive_folder_url']) ?>" target="_blank" onclick="event.stopPropagation();" style="font-size:.6rem;color:#0ea5e9;font-weight:600;text-decoration:none;display:block;margin-top:.15rem;">📂 Pasta Drive</a>
                    <?php endif; ?>
                    <?php if ($stageKey === 'doc_faltante' && $lead['doc_faltante_motivo']): ?>
                        <div class="lead-doc-alert">⚠️ <?= e($lead['doc_faltante_motivo']) ?></div>
                    <?php endif; ?>
                    <div class="lead-days"><?= $lead['days_in_pipeline'] ?>d no funil</div>

                    <div class="lead-actions" onclick="event.stopPropagation();">
                        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" data-lead-name="<?= e($lead['name']) ?>" data-case-type="<?= e($lead['case_type'] ?: '') ?>">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <input type="hidden" name="folder_name" value="">
                            <select name="to_stage" onchange="handleStageMove(this)" style="flex:1;">
                                <option value="">Mover →</option>
                                <?php foreach ($stages as $sk => $sv): ?>
                                    <?php if ($sk !== $stageKey && $sk !== 'doc_faltante'): ?>
                                        <option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <option value="perdido">❌ Perdido</option>
                            </select>
                        </form>
                        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <button type="submit" class="lead-del" title="Excluir" data-confirm="Excluir <?= e($lead['name']) ?>?">🗑️</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Visão Tabela (Planilha Comercial Completa) -->
<div id="viewTabela" style="display:none;">
<style>
.tbl-toolbar { display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem;padding:.5rem .75rem;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg); }
.tbl-filter { font-size:.8rem;padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;color:var(--text);min-width:120px; }
.tbl-count { margin-left:auto;font-size:.78rem;color:var(--text-muted);font-weight:600; }
.tbl-csv { padding:6px 16px;background:var(--success);color:#fff;border:none;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer; }
.tbl-wrap { border-radius:var(--radius-lg);overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow-sm); }
.tbl-grid { width:100%;border-collapse:collapse;font-size:.78rem; }
.tbl-grid thead { position:sticky;top:0;z-index:2; }
.tbl-grid th { background:linear-gradient(180deg,var(--petrol-900),var(--petrol-700));color:#fff;padding:8px 10px;text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase;cursor:pointer;user-select:none;white-space:nowrap;border-right:1px solid rgba(255,255,255,.15); }
.tbl-grid th:hover { background:var(--petrol-500); }
.tbl-grid td { padding:5px 8px;border-bottom:1px solid #eee;border-right:1px solid #f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px; }
.tbl-grid tbody tr { transition:background .15s; }
.tbl-grid tbody tr:nth-child(even) { background:#fafbfc; }
.tbl-grid tbody tr:hover { background:rgba(215,171,144,.12); }
.tbl-badge { display:inline-block;padding:2px 8px;border-radius:10px;font-size:.65rem;font-weight:700;color:#fff; }
/* Edição inline */
.tbl-grid td.editable { cursor:text;position:relative; }
.tbl-grid td.editable:hover { background:rgba(215,171,144,.1);outline:1px dashed var(--rose); }
.tbl-grid td.editable:focus-within { background:#fff;outline:2px solid var(--rose); }
.tbl-grid td.editable input, .tbl-grid td.editable select { width:100%;border:none;background:transparent;font:inherit;padding:0;outline:none; }
.tbl-grid td.saved::after { content:'✓';position:absolute;right:4px;top:50%;transform:translateY(-50%);color:var(--success);font-size:.7rem;font-weight:700;animation:fadeout 1.5s forwards; }
@keyframes fadeout { 0%{opacity:1} 70%{opacity:1} 100%{opacity:0} }
/* Linhas coloridas */
.tbl-grid tbody tr[data-stage="cadastro_preenchido"] { border-left:4px solid #6366f1; }
.tbl-grid tbody tr[data-stage="elaboracao_docs"] { border-left:4px solid #0ea5e9; }
.tbl-grid tbody tr[data-stage="link_enviados"] { border-left:4px solid #f59e0b; }
.tbl-grid tbody tr[data-stage="contrato_assinado"] { border-left:4px solid #059669; }
.tbl-grid tbody tr[data-stage="agendado_docs"] { border-left:4px solid #0d9488; }
.tbl-grid tbody tr[data-stage="reuniao_cobranca"] { border-left:4px solid #d97706; }
.tbl-grid tbody tr[data-stage="doc_faltante"] { border-left:4px solid #dc2626;background:rgba(220,38,38,.04) !important; }
.tbl-grid tbody tr[data-stage="pasta_apta"] { border-left:4px solid #15803d;background:rgba(21,128,61,.04) !important; }
.tbl-grid tbody tr[data-stage="cancelado"] { border-left:4px solid #dc2626;background:rgba(220,38,38,.06) !important;text-decoration:line-through;opacity:.6; }
.tbl-grid tbody tr[data-stage="cancelado"] td { color:#991b1b !important; }
.tbl-grid tbody tr[data-stage="cancelado"] .tbl-badge { background:#dc2626 !important; }
.tbl-grid tbody tr[data-stage="suspenso"] { border-left:4px solid #9ca3af;background:rgba(156,163,175,.08) !important;font-style:italic; }
.tbl-grid tbody tr[data-stage="suspenso"] td { color:#6b7280 !important; }
.tbl-grid tbody tr[data-stage="contrato_assinado"] { background:rgba(5,150,105,.06) !important; }
.tbl-grid tbody tr[data-stage="contrato_assinado"] td:first-child { font-weight:800; }
.tbl-pag { display:flex;justify-content:center;gap:4px;margin-top:1rem; }
.tbl-pag a { padding:6px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:.78rem;text-decoration:none;font-weight:600;color:var(--text); }
.tbl-pag a.active { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
</style>
<?php
$allLeadsFlat = array();
foreach ($byStage as $stageKey => $stageLeads) {
    foreach ($stageLeads as $l) { $l['_stage_key'] = $stageKey; $allLeadsFlat[] = $l; }
}
$tabelaPage = max(1, (int)($_GET['tp'] ?? 1));
$perPage = 25;
$totalPages = max(1, ceil(count($allLeadsFlat) / $perPage));
if ($tabelaPage > $totalPages) $tabelaPage = $totalPages;
$pOffset = ($tabelaPage - 1) * $perPage;
$pageLeads = array_slice($allLeadsFlat, $pOffset, $perPage);
$tipos = array();
foreach ($allLeadsFlat as $l) { if ($l['case_type'] && !in_array($l['case_type'], $tipos)) $tipos[] = $l['case_type']; }
sort($tipos);
?>
<div class="tbl-toolbar">
    <select id="filterStage" onchange="filterPipelineTable()" class="tbl-filter">
        <option value="">Etapa</option>
        <?php foreach ($stages as $sk => $sv): ?><option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option><?php endforeach; ?>
    </select>
    <select id="filterResp" onchange="filterPipelineTable()" class="tbl-filter">
        <option value="">Responsável</option>
        <?php foreach ($users as $u): ?><option value="<?= e($u['name']) ?>"><?= e(explode(' ', $u['name'])[0]) ?></option><?php endforeach; ?>
    </select>
    <select id="filterType" onchange="filterPipelineTable()" class="tbl-filter">
        <option value="">Tipo</option>
        <?php foreach ($tipos as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
    </select>
    <span class="tbl-count"><?= count($allLeadsFlat) ?> leads</span>
    <button onclick="exportTableCSV('pipelineTableBody','comercial')" class="tbl-csv">Exportar CSV</button>
</div>
<div class="tbl-wrap" style="max-height:75vh;overflow:auto;">
<table class="tbl-grid" id="pipelineTableBody">
<thead><tr>
    <th style="width:30px;text-align:center;cursor:default;">#</th>
    <th onclick="sortTbl('pipelineTableBody',1)">Nome</th>
    <th onclick="sortTbl('pipelineTableBody',2)">Contato</th>
    <th onclick="sortTbl('pipelineTableBody',3)">Data Fech.</th>
    <th onclick="sortTbl('pipelineTableBody',4)">Tipo de Ação</th>
    <th onclick="sortTbl('pipelineTableBody',5)">Valor</th>
    <th onclick="sortTbl('pipelineTableBody',6)">Vencto 1ª</th>
    <th onclick="sortTbl('pipelineTableBody',7)">Pgto</th>
    <th onclick="sortTbl('pipelineTableBody',8)">Responsável</th>
    <th onclick="sortTbl('pipelineTableBody',9)">Urgência</th>
    <th onclick="sortTbl('pipelineTableBody',10)">Observações</th>
    <th onclick="sortTbl('pipelineTableBody',11)">Nome Pasta</th>
    <th onclick="sortTbl('pipelineTableBody',12)">Estado</th>
    <th onclick="sortTbl('pipelineTableBody',13)">Pendências</th>
    <th style="cursor:default;">Mover</th>
</tr></thead>
<tbody>
<?php $n = $pOffset + 1; foreach ($pageLeads as $lead):
    $sk = $lead['_stage_key']; $si = $stages[$sk];
    $lid = (int)$lead['id'];
?>
<tr data-stage="<?= $sk ?>" data-resp="<?= e($lead['assigned_name'] ?? '') ?>" data-type="<?= e($lead['case_type'] ?? '') ?>">
    <td style="text-align:center;color:#999;font-size:.7rem;">
        <a href="<?= module_url('pipeline', 'lead_ver.php?id=' . $lid) ?>" style="color:#999;text-decoration:none;" title="Ver detalhes"><?= $n++ ?></a>
    </td>
    <td class="editable" style="font-weight:700;color:var(--petrol-900);min-width:160px;"><input value="<?= e($lead['name']) ?>" data-id="<?= $lid ?>" data-field="name" onchange="saveCell(this)"></td>
    <td class="editable" style="min-width:110px;"><input value="<?= e($lead['phone'] ?? '') ?>" data-id="<?= $lid ?>" data-field="phone" onchange="saveCell(this)"></td>
    <td style="font-size:.72rem;"><?= $lead['created_at'] ? date('d/m/Y', strtotime($lead['created_at'])) : '' ?></td>
    <td class="editable" style="min-width:100px;"><input value="<?= e($lead['case_type'] ?? '') ?>" data-id="<?= $lid ?>" data-field="case_type" onchange="saveCell(this)"></td>
    <td class="editable" style="min-width:90px;"><input value="<?= e($lead['valor_acao'] ?? '') ?>" data-id="<?= $lid ?>" data-field="valor_acao" onchange="saveCell(this)"></td>
    <td class="editable" style="min-width:80px;"><input value="<?= e($lead['vencimento_parcela'] ?? '') ?>" data-id="<?= $lid ?>" data-field="vencimento_parcela" onchange="saveCell(this)"></td>
    <td class="editable" style="min-width:70px;"><input value="<?= e($lead['forma_pagamento'] ?? '') ?>" data-id="<?= $lid ?>" data-field="forma_pagamento" onchange="saveCell(this)"></td>
    <td class="editable" style="min-width:80px;">
        <select data-id="<?= $lid ?>" data-field="assigned_to" onchange="saveCell(this)">
            <option value="">—</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= $lead['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option><?php endforeach; ?>
        </select>
    </td>
    <td class="editable" style="min-width:60px;"><input value="<?= e($lead['urgencia'] ?? '') ?>" data-id="<?= $lid ?>" data-field="urgencia" onchange="saveCell(this)"></td>
    <td class="editable" style="min-width:120px;max-width:180px;"><input value="<?= e($lead['observacoes'] ?? $lead['notes'] ?? '') ?>" data-id="<?= $lid ?>" data-field="observacoes" onchange="saveCell(this)" title="<?= e($lead['observacoes'] ?? $lead['notes'] ?? '') ?>"></td>
    <td class="editable" style="min-width:140px;"><input value="<?= e($lead['nome_pasta'] ?? '') ?>" data-id="<?= $lid ?>" data-field="nome_pasta" onchange="saveCell(this)"></td>
    <td>
        <span class="tbl-badge" style="background:<?= $si['color'] ?>;font-size:.6rem;"><?= $si['icon'] ?> <?= $si['label'] ?></span>
    </td>
    <td class="editable" style="min-width:100px;"><input value="<?= e($lead['pendencias'] ?? '') ?>" data-id="<?= $lid ?>" data-field="pendencias" onchange="saveCell(this)"></td>
    <td onclick="event.stopPropagation();" style="min-width:80px;">
        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" data-lead-name="<?= e($lead['name']) ?>" data-case-type="<?= e($lead['case_type'] ?: '') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="move">
            <input type="hidden" name="lead_id" value="<?= $lid ?>">
            <input type="hidden" name="folder_name" value="">
            <select name="to_stage" onchange="handleStageMove(this)" style="font-size:.7rem;padding:2px 4px;border:1px solid var(--border);border-radius:4px;background:#fff;cursor:pointer;">
                <option value="">Mover →</option>
                <?php foreach ($stages as $ssk => $ssv): if ($ssk !== $sk && $ssk !== 'doc_faltante'): ?>
                    <option value="<?= $ssk ?>"><?= $ssv['icon'] ?> <?= $ssv['label'] ?></option>
                <?php endif; endforeach; ?>
                <option value="perdido">❌ Perdido</option>
            </select>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($pageLeads)): ?>
<tr><td colspan="15" style="text-align:center;color:#999;padding:2rem;">Nenhum lead no funil.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($totalPages > 1): ?>
<div class="tbl-pag">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="?tp=<?= $p ?>" class="<?= $p === $tabelaPage ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
</div>

<!-- Modal: Nome da Pasta (ao mover para contrato_assinado) -->
<div id="folderModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.25rem;">📂 Nome da Pasta no Drive</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">Ao assinar contrato, uma pasta será criada no Drive e o Operacional será notificado.</p>
        <input type="text" id="folderNameInput" style="width:100%;padding:.65rem .85rem;font-size:.95rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;" placeholder="Ex: Ana Maria Braga x Pensão">
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="closeFolderModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600;color:#6b7280;">Cancelar</button>
            <button onclick="confirmFolder()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Criar Pasta →</button>
        </div>
    </div>
</div>

<script>
var _pendingForm = null;
var _pendingDragData = null;

function handleStageMove(select) {
    var stage = select.value;
    if (!stage) return;
    var form = select.closest('form');

    if (stage === 'contrato_assinado') {
        var leadName = form.dataset.leadName || '';
        var caseType = form.dataset.caseType || '';
        var sugestao = leadName + (caseType ? ' x ' + caseType : '');
        document.getElementById('folderNameInput').value = sugestao;
        document.getElementById('folderModal').style.display = 'flex';
        document.getElementById('folderNameInput').focus();
        document.getElementById('folderNameInput').select();
        _pendingForm = form;
        _pendingDragData = null;
    } else {
        form.submit();
    }
}

function closeFolderModal() {
    document.getElementById('folderModal').style.display = 'none';
    if (_pendingForm) {
        var sel = _pendingForm.querySelector('select[name="to_stage"]');
        if (sel) sel.value = '';
    }
    _pendingForm = null;
    _pendingDragData = null;
}

function playCelebration() {
    // Criar som de sino programaticamente (sem arquivo externo)
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var notes = [
            {freq:830, start:0, dur:0.3},
            {freq:1050, start:0.15, dur:0.3},
            {freq:1320, start:0.3, dur:0.5},
            {freq:1580, start:0.5, dur:0.7},
        ];
        notes.forEach(function(n) {
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.value = n.freq;
            gain.gain.setValueAtTime(0.4, ctx.currentTime + n.start);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + n.start + n.dur);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(ctx.currentTime + n.start);
            osc.stop(ctx.currentTime + n.start + n.dur);
        });
        // Segundo sino (mais alto, festivo)
        setTimeout(function() {
            var ctx2 = new (window.AudioContext || window.webkitAudioContext)();
            [1050, 1320, 1580, 1760, 2100].forEach(function(freq, i) {
                var osc = ctx2.createOscillator();
                var gain = ctx2.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.3, ctx2.currentTime + i * 0.12);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx2.currentTime + i * 0.12 + 0.6);
                osc.connect(gain);
                gain.connect(ctx2.destination);
                osc.start(ctx2.currentTime + i * 0.12);
                osc.stop(ctx2.currentTime + i * 0.12 + 0.6);
            });
        }, 400);
    } catch(e) {}
}

function confirmFolder() {
    var folderName = document.getElementById('folderNameInput').value.trim();
    if (!folderName) { document.getElementById('folderNameInput').style.borderColor = '#ef4444'; return; }
    document.getElementById('folderModal').style.display = 'none';

    // Tocar sino de celebração!
    playCelebration();

    if (_pendingForm) {
        _pendingForm.querySelector('input[name="folder_name"]').value = folderName;
        _pendingForm.submit();
    } else if (_pendingDragData) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = _pendingDragData.apiUrl;
        form.innerHTML = '<input type="hidden" name="csrf_token" value="' + _pendingDragData.csrfToken + '">' +
            '<input type="hidden" name="action" value="move">' +
            '<input type="hidden" name="lead_id" value="' + _pendingDragData.leadId + '">' +
            '<input type="hidden" name="to_stage" value="contrato_assinado">' +
            '<input type="hidden" name="folder_name" value="' + folderName.replace(/"/g, '&quot;') + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

document.getElementById('folderNameInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') confirmFolder();
    if (e.key === 'Escape') closeFolderModal();
});

// Drag & Drop
(function() {
    var draggedId = null, draggedName = '', draggedType = '';
    window._dragging = false;
    var csrfToken = '<?= generate_csrf_token() ?>';
    var apiUrl = '<?= module_url("pipeline", "api.php") ?>';

    document.querySelectorAll('.lead-card[draggable]').forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            draggedId = this.dataset.leadId;
            var nameEl = this.querySelector('.lead-name');
            draggedName = nameEl ? nameEl.textContent.trim() : '';
            var form = this.querySelector('form');
            draggedType = form ? (form.dataset.caseType || '') : '';
            window._dragging = true;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedId);
        });
        card.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            setTimeout(function() { window._dragging = false; }, 100);
            document.querySelectorAll('.kanban-body').forEach(function(b) { b.classList.remove('drag-over'); });
        });
    });

    document.querySelectorAll('.kanban-body').forEach(function(body) {
        body.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
        body.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
        body.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            var toStage = this.dataset.stage;
            if (!draggedId || !toStage) return;

            if (toStage === 'contrato_assinado') {
                var sugestao = draggedName + (draggedType ? ' x ' + draggedType : '');
                document.getElementById('folderNameInput').value = sugestao;
                document.getElementById('folderModal').style.display = 'flex';
                document.getElementById('folderNameInput').focus();
                _pendingForm = null;
                _pendingDragData = { leadId: draggedId, csrfToken: csrfToken, apiUrl: apiUrl };
            } else {
                var form = document.createElement('form');
                form.method = 'POST'; form.action = apiUrl;
                form.innerHTML = '<input type="hidden" name="csrf_token" value="' + csrfToken + '"><input type="hidden" name="action" value="move"><input type="hidden" name="lead_id" value="' + draggedId + '"><input type="hidden" name="to_stage" value="' + toStage + '">';
                document.body.appendChild(form); form.submit();
            }
        });
    });
})();
</script>

<script>
// Toggle Kanban / Tabela
// Salvar célula inline via AJAX
function saveCell(el) {
    var id = el.dataset.id;
    var field = el.dataset.field;
    var value = el.value;
    var td = el.closest('td');
    var formData = new FormData();
    formData.append('action', 'inline_edit');
    formData.append('lead_id', id);
    formData.append('field', field);
    formData.append('value', value);
    formData.append('csrf_token', '<?= generate_csrf_token() ?>');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= module_url("pipeline", "api.php") ?>');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        if (td) { td.classList.add('saved'); setTimeout(function() { td.classList.remove('saved'); }, 1500); }
    };
    xhr.send(formData);
}

function toggleView(view) {
    var k = document.getElementById('viewKanban');
    var t = document.getElementById('viewTabela');
    var bk = document.getElementById('btnKanban');
    var bt = document.getElementById('btnTabela');
    if (view === 'tabela') {
        k.style.display = 'none'; t.style.display = 'block';
        bk.style.background = 'var(--bg-card)'; bk.style.color = 'var(--text)';
        bt.style.background = 'var(--petrol-900)'; bt.style.color = '#fff';
    } else {
        k.style.display = 'grid'; t.style.display = 'none';
        bt.style.background = 'var(--bg-card)'; bt.style.color = 'var(--text)';
        bk.style.background = 'var(--petrol-900)'; bk.style.color = '#fff';
    }
    try { localStorage.setItem('pipeline_view', view); } catch(e) {}
}
// Restaurar preferência
try { var saved = localStorage.getItem('pipeline_view'); if (saved === 'tabela') toggleView('tabela'); } catch(e) {}

// Filtros da tabela
function filterPipelineTable() {
    var stage = document.getElementById('filterStage').value;
    var resp = document.getElementById('filterResp').value;
    var tipo = document.getElementById('filterType').value;
    var rows = document.querySelectorAll('#pipelineTableBody tbody tr');
    rows.forEach(function(row) {
        if (!row.dataset.stage) return;
        var show = true;
        if (stage && row.dataset.stage !== stage) show = false;
        if (resp && row.dataset.resp !== resp) show = false;
        if (tipo && row.dataset.type !== tipo) show = false;
        row.style.display = show ? '' : 'none';
    });
}

// Ordenar tabela
var _sortDirs = {};
function sortTbl(tableId, colIdx) {
    var table = document.getElementById(tableId);
    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr[data-stage]'));
    var dir = _sortDirs[tableId + '_' + colIdx] === 'asc' ? 'desc' : 'asc';
    _sortDirs[tableId + '_' + colIdx] = dir;
    rows.sort(function(a, b) {
        var av = (a.cells[colIdx] && a.cells[colIdx].textContent.trim()) || '';
        var bv = (b.cells[colIdx] && b.cells[colIdx].textContent.trim()) || '';
        var an = parseFloat(av.replace(/[^\d.-]/g, ''));
        var bn = parseFloat(bv.replace(/[^\d.-]/g, ''));
        if (!isNaN(an) && !isNaN(bn)) return dir === 'asc' ? an - bn : bn - an;
        return dir === 'asc' ? av.localeCompare(bv, 'pt-BR') : bv.localeCompare(av, 'pt-BR');
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
}

// Exportar CSV
function exportTableCSV(tableId, name) {
    var table = document.getElementById(tableId);
    var csv = [];
    table.querySelectorAll('tr').forEach(function(row) {
        if (row.style.display === 'none') return;
        var cols = [];
        row.querySelectorAll('th, td').forEach(function(cell, i) {
            if (i === 7) return;
            cols.push('"' + cell.textContent.replace(/"/g, '""').trim() + '"');
        });
        csv.push(cols.join(';'));
    });
    var blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name + '_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
