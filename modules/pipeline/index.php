<?php
/**
 * Ferreira & Sá Hub — Pipeline Comercial/CX (Kanban)
 * Fluxo: Cadastro → Elaboração → Link Enviados → Contrato Assinado →
 *        Agendado/Docs → Reunião/Cobrança → Doc Faltante → Pasta Apta
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_view_pipeline()) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'Pipeline Comercial/CX';
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
    'cancelado'           => array('label' => 'Cancelado',                  'color' => '#6b7280', 'icon' => '❌', 'resp' => 'Admin'),
    'suspenso'            => array('label' => 'Suspenso',                   'color' => '#9ca3af', 'icon' => '⏸️', 'resp' => 'Admin'),
);

// Buscar leads (exceto finalizados)
$leads = $pdo->query(
    "SELECT pl.*, u.name as assigned_name, c.name as client_name,
     DATEDIFF(NOW(), pl.created_at) as days_in_pipeline
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     LEFT JOIN clients c ON c.id = pl.client_id
     WHERE pl.stage NOT IN ('finalizado','perdido')
     ORDER BY pl.updated_at DESC"
)->fetchAll();

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

<!-- Ações + Toggle -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem;">
    <h3 style="font-size:.95rem;font-weight:700;color:var(--petrol-900);">Pipeline Comercial/CX</h3>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <div style="display:flex;border:1.5px solid var(--border);border-radius:8px;overflow:hidden;">
            <button onclick="toggleView('kanban')" id="btnKanban" style="padding:4px 12px;font-size:.72rem;font-weight:700;border:none;cursor:pointer;background:var(--petrol-900);color:#fff;">Kanban</button>
            <button onclick="toggleView('tabela')" id="btnTabela" style="padding:4px 12px;font-size:.72rem;font-weight:700;border:none;cursor:pointer;background:var(--bg-card);color:var(--text);">Tabela</button>
        </div>
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

<!-- Visão Tabela -->
<div id="viewTabela" style="display:none;">
<?php
// Flatten leads + paginação
$allLeadsFlat = array();
foreach ($byStage as $stageKey => $stageLeads) {
    foreach ($stageLeads as $l) {
        $l['_stage_key'] = $stageKey;
        $allLeadsFlat[] = $l;
    }
}
$tabelaPage = max(1, (int)($_GET['tp'] ?? 1));
$perPage = 25;
$totalPages = max(1, ceil(count($allLeadsFlat) / $perPage));
if ($tabelaPage > $totalPages) $tabelaPage = $totalPages;
$offset = ($tabelaPage - 1) * $perPage;
$pageLeads = array_slice($allLeadsFlat, $offset, $perPage);
?>
<div style="margin-bottom:.5rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <select id="filterStage" onchange="filterPipelineTable()" style="font-size:.72rem;padding:.3rem .5rem;border:1.5px solid var(--border);border-radius:var(--radius);background:var(--bg-card);">
        <option value="">Todas as etapas</option>
        <?php foreach ($stages as $sk => $sv): ?>
            <option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
        <?php endforeach; ?>
    </select>
    <select id="filterResp" onchange="filterPipelineTable()" style="font-size:.72rem;padding:.3rem .5rem;border:1.5px solid var(--border);border-radius:var(--radius);background:var(--bg-card);">
        <option value="">Todos responsáveis</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= e($u['name']) ?>"><?= e(explode(' ', $u['name'])[0]) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="filterType" onchange="filterPipelineTable()" style="font-size:.72rem;padding:.3rem .5rem;border:1.5px solid var(--border);border-radius:var(--radius);background:var(--bg-card);">
        <option value="">Todos os tipos</option>
        <?php
        $tipos = array();
        foreach ($allLeadsFlat as $l) { if ($l['case_type'] && !in_array($l['case_type'], $tipos)) $tipos[] = $l['case_type']; }
        sort($tipos);
        foreach ($tipos as $t): ?>
            <option value="<?= e($t) ?>"><?= e($t) ?></option>
        <?php endforeach; ?>
    </select>
    <span style="margin-left:auto;font-size:.72rem;color:var(--text-muted);"><?= count($allLeadsFlat) ?> leads</span>
    <button onclick="exportTableCSV('pipelineTableBody','pipeline')" style="padding:4px 12px;background:#059669;color:#fff;border:none;border-radius:6px;font-size:.7rem;font-weight:600;cursor:pointer;">CSV</button>
</div>
<div style="overflow-x:auto;border:1px solid #bbb;border-radius:6px;">
<table style="width:100%;border-collapse:collapse;font-size:.75rem;font-family:'Segoe UI',Arial,sans-serif;" id="pipelineTableBody">
<thead>
<tr style="background:linear-gradient(180deg,#f0f0f0,#e0e0e0);">
    <th style="border:1px solid #bbb;padding:6px 8px;cursor:pointer;white-space:nowrap;font-size:.7rem;" onclick="sortTbl('pipelineTableBody',0)">#</th>
    <th style="border:1px solid #bbb;padding:6px 8px;cursor:pointer;white-space:nowrap;font-size:.7rem;" onclick="sortTbl('pipelineTableBody',1)">Nome</th>
    <th style="border:1px solid #bbb;padding:6px 8px;cursor:pointer;white-space:nowrap;font-size:.7rem;" onclick="sortTbl('pipelineTableBody',2)">Tipo Ação</th>
    <th style="border:1px solid #bbb;padding:6px 8px;cursor:pointer;white-space:nowrap;font-size:.7rem;" onclick="sortTbl('pipelineTableBody',3)">Responsável</th>
    <th style="border:1px solid #bbb;padding:6px 8px;cursor:pointer;white-space:nowrap;font-size:.7rem;" onclick="sortTbl('pipelineTableBody',4)">Etapa</th>
    <th style="border:1px solid #bbb;padding:6px 8px;cursor:pointer;white-space:nowrap;font-size:.7rem;" onclick="sortTbl('pipelineTableBody',5)">Dias</th>
    <th style="border:1px solid #bbb;padding:6px 8px;cursor:pointer;white-space:nowrap;font-size:.7rem;" onclick="sortTbl('pipelineTableBody',6)">Cadastro</th>
    <th style="border:1px solid #bbb;padding:6px 8px;white-space:nowrap;font-size:.7rem;">Mover</th>
</tr>
</thead>
<tbody>
<?php $n = $offset + 1; foreach ($pageLeads as $lead):
    $sk = $lead['_stage_key'];
    $stageInfo = $stages[$sk];
?>
<tr data-stage="<?= $sk ?>" data-resp="<?= e($lead['assigned_name'] ?? '') ?>" data-type="<?= e($lead['case_type'] ?? '') ?>" style="cursor:pointer;" onclick="if(!event.target.closest('select,form'))window.location='<?= module_url('pipeline', 'lead_ver.php?id=' . $lead['id']) ?>'">
    <td style="border:1px solid #d0d0d0;padding:4px 8px;background:#f0f0f0;text-align:center;font-size:.68rem;color:#666;"><?= $n++ ?></td>
    <td style="border:1px solid #d0d0d0;padding:4px 8px;font-weight:600;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($lead['name']) ?></td>
    <td style="border:1px solid #d0d0d0;padding:4px 8px;white-space:nowrap;"><?= e($lead['case_type'] ?? '') ?></td>
    <td style="border:1px solid #d0d0d0;padding:4px 8px;white-space:nowrap;color:var(--rose-dark);font-weight:600;"><?= e($lead['assigned_name'] ? explode(' ', $lead['assigned_name'])[0] : '—') ?></td>
    <td style="border:1px solid #d0d0d0;padding:4px 8px;white-space:nowrap;"><span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:.65rem;font-weight:700;color:#fff;background:<?= $stageInfo['color'] ?>;"><?= $stageInfo['icon'] ?> <?= $stageInfo['label'] ?></span></td>
    <td style="border:1px solid #d0d0d0;padding:4px 8px;text-align:center;"><?= $lead['days_in_pipeline'] ?>d</td>
    <td style="border:1px solid #d0d0d0;padding:4px 8px;white-space:nowrap;"><?= date('d/m/Y', strtotime($lead['created_at'])) ?></td>
    <td style="border:1px solid #d0d0d0;padding:4px 8px;" onclick="event.stopPropagation();">
        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" data-lead-name="<?= e($lead['name']) ?>" data-case-type="<?= e($lead['case_type'] ?: '') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="move">
            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
            <input type="hidden" name="folder_name" value="">
            <select name="to_stage" onchange="handleStageMove(this)" style="font-size:.65rem;padding:2px 4px;border:1px solid var(--border);border-radius:4px;background:var(--bg-card);cursor:pointer;">
                <option value="">Mover →</option>
                <?php foreach ($stages as $ssk => $ssv): ?>
                    <?php if ($ssk !== $sk && $ssk !== 'doc_faltante'): ?>
                        <option value="<?= $ssk ?>"><?= $ssv['icon'] ?> <?= $ssv['label'] ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
                <option value="perdido">❌ Perdido</option>
            </select>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($pageLeads)): ?>
<tr><td colspan="8" style="text-align:center;color:#999;padding:30px;border:1px solid #d0d0d0;">Nenhum lead encontrado.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:4px;margin-top:.75rem;">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="?tp=<?= $p ?>" style="padding:4px 10px;border:1px solid var(--border);border-radius:6px;font-size:.72rem;text-decoration:none;<?= $p === $tabelaPage ? 'background:var(--petrol-900);color:#fff;' : 'color:var(--text);' ?>"><?= $p ?></a>
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
