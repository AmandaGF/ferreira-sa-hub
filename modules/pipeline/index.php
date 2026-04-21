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
    'cancelado'           => array('label' => 'Cancelado',                  'color' => '#6b7280', 'icon' => '❌', 'resp' => 'Admin'),
    'suspenso'            => array('label' => 'Suspenso',                   'color' => '#9ca3af', 'icon' => '⏸️', 'resp' => 'Admin'),
);

// Stages que só aparecem na Tabela (histórico), não no Kanban — pra renderizar badge.
$stagesHistorico = array(
    'finalizado' => array('label' => 'Finalizado (no Jurídico)', 'color' => '#1e40af', 'icon' => '🏛️'),
    'perdido'    => array('label' => 'Perdido',                  'color' => '#991b1b', 'icon' => '💔'),
    'arquivado'  => array('label' => 'Arquivado',                'color' => '#374151', 'icon' => '📦'),
);

// Filtros
$searchPipeline = isset($_GET['q']) ? trim($_GET['q']) : '';
$filterMonth = isset($_GET['mes']) ? $_GET['mes'] : '';

// ══════════════════════════════════════════════════════════════════
// DUAS QUERIES SEPARADAS — Kanban e Tabela têm regras diferentes:
//
// KANBAN  = leads ativos + fechados do MÊS ATUAL (vira o mês → sai)
// TABELA  = todos os leads que fecharam alguma vez (converted_at preenchido)
//           + leads ainda em fluxo (pra casos que foram add manualmente pela
//            equipe já em stage pós-contrato — ver filtro de mês)
// ══════════════════════════════════════════════════════════════════

// ─── Query do KANBAN ─────────────────────────────────────────────
// Mostra todos os leads em estágios ativos, COM UMA EXCEÇÃO: leads
// em 'pasta_apta' ou 'cancelado' só ficam no Kanban no mês em que
// entraram nesse estágio. Ao virar o mês, somem (reinicia o ciclo).
// Os demais stages (contrato_assinado, agendado_docs, reuniao_cobranca,
// doc_faltante, etc.) ficam sempre, porque ainda exigem trabalho do
// comercial independente do mês.
$kanbanWhere = "pl.stage NOT IN ('finalizado','perdido','arquivado')
                AND (
                    pl.stage NOT IN ('pasta_apta','cancelado')
                    OR DATE_FORMAT(COALESCE(pl.converted_at, pl.updated_at), '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
                )";
$kanbanParams = array();
if ($searchPipeline) {
    $kanbanWhere .= " AND (pl.name LIKE ? OR pl.phone LIKE ? OR pl.case_type LIKE ?)";
    $s = "%$searchPipeline%";
    $kanbanParams = array($s, $s, $s);
}

$stmt = $pdo->prepare(
    "SELECT pl.*, u.name as assigned_name, c.name as client_name,
     DATEDIFF(NOW(), pl.created_at) as days_in_pipeline,
     cs.drive_folder_url
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     LEFT JOIN clients c ON c.id = pl.client_id
     LEFT JOIN cases cs ON cs.id = pl.linked_case_id
     WHERE $kanbanWhere
     ORDER BY pl.updated_at DESC"
);
$stmt->execute($kanbanParams);
$leads = $stmt->fetchAll();

$byStage = array();
foreach (array_keys($stages) as $s) { $byStage[$s] = array(); }
foreach ($leads as $lead) {
    $st = $lead['stage'];
    if (isset($byStage[$st])) {
        $byStage[$st][] = $lead;
    }
}

// ─── Query da TABELA (planilha de contratos fechados) ────────────
// Mostra: TODOS os leads com converted_at preenchido (histórico completo
// de contratos fechados), independente do stage ou mês. Nunca somem —
// métricas e estatísticas precisam do histórico.
$planilhaWhere = "pl.converted_at IS NOT NULL AND pl.stage NOT IN ('arquivado')";
$planilhaParams = array();
if ($searchPipeline) {
    $planilhaWhere .= " AND (pl.name LIKE ? OR pl.phone LIKE ? OR pl.case_type LIKE ?)";
    $s = "%$searchPipeline%";
    $planilhaParams = array($s, $s, $s);
}
if ($filterMonth) {
    // Filtro de mês opera sobre data real de fechamento (converted_at).
    $planilhaWhere .= " AND DATE_FORMAT(pl.converted_at, '%Y-%m') = ?";
    $planilhaParams[] = $filterMonth;
}
$stmtT = $pdo->prepare(
    "SELECT pl.*, u.name as assigned_name, c.name as client_name,
     c.asaas_customer_id AS asaas_customer_id,
     DATEDIFF(NOW(), pl.created_at) as days_in_pipeline,
     cs.drive_folder_url,
     (SELECT COUNT(*) FROM asaas_cobrancas ac WHERE ac.client_id = c.id) AS asaas_total_cobrancas,
     (SELECT COUNT(*) FROM asaas_cobrancas ac WHERE ac.client_id = c.id AND ac.status NOT IN ('CANCELED','REFUNDED','REFUND_REQUESTED','REFUND_IN_PROGRESS')) AS asaas_cobrancas_ativas
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     LEFT JOIN clients c ON c.id = pl.client_id
     LEFT JOIN cases cs ON cs.id = pl.linked_case_id
     WHERE $planilhaWhere
     ORDER BY pl.converted_at DESC"
);
$stmtT->execute($planilhaParams);
$leadsPlanilha = $stmtT->fetchAll();

// KPIs (baseados no Kanban — leads ativos do ciclo atual)
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

// Detectar divergências entre Pipeline ↔ Operacional (mesma lógica do reconciliador)
$divergencias = array();
try {
    $diverRows = $pdo->query("
        SELECT l.id AS lead_id, l.stage AS lead_stage, l.name AS lead_name,
               c.id AS case_id, c.status AS case_status
        FROM pipeline_leads l
        INNER JOIN cases c ON c.id = l.linked_case_id
        WHERE l.linked_case_id IS NOT NULL AND l.linked_case_id > 0
    ")->fetchAll();
    foreach ($diverRows as $r) {
        $leadCanon = null; $caseCanon = null;
        switch ($r['lead_stage']) {
            case 'cancelado': case 'perdido': $leadCanon = 'cancelado'; break;
            case 'suspenso': $leadCanon = 'suspenso'; break;
        }
        switch ($r['case_status']) {
            case 'cancelado': $caseCanon = 'cancelado'; break;
            case 'doc_faltante': $caseCanon = 'doc_faltante'; break;
            case 'suspenso': $caseCanon = 'suspenso'; break;
        }
        if ($leadCanon !== null && $r['case_status'] !== $leadCanon) {
            $divergencias[] = $r + array('tipo' => 'lead_manda', 'esperado' => "case=$leadCanon");
        } elseif ($caseCanon !== null && $r['lead_stage'] !== $caseCanon) {
            $divergencias[] = $r + array('tipo' => 'case_manda', 'esperado' => "lead=$caseCanon");
        }
    }
} catch (Exception $e) {}

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

<!-- Banner: Divergências Pipeline ↔ Operacional -->
<?php if (!empty($divergencias) && has_role('admin')): ?>
<div style="background:#fff7ed;border:2px solid #fb923c;border-radius:12px;padding:.75rem 1rem;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;" onclick="var el=document.getElementById('divExpand');el.style.display=el.style.display==='none'?'block':'none';this.querySelector('.chevron').textContent=el.style.display==='none'?'▸':'▾';">
        <div style="display:flex;align-items:center;gap:.5rem;">
            <span style="font-size:1rem;">🔄</span>
            <strong style="font-size:.82rem;color:#c2410c;"><?= count($divergencias) ?> divergência(s) entre Pipeline e Operacional</strong>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem;">
            <a href="<?= url('modules/admin/reconciliar_kanbans.php') ?>" style="background:#fb923c;color:#fff;padding:.3rem .7rem;border-radius:6px;font-size:.7rem;font-weight:700;text-decoration:none;" onclick="event.stopPropagation()">Reconciliar →</a>
            <span class="chevron" style="font-size:.8rem;color:#c2410c;">▸</span>
        </div>
    </div>
    <div id="divExpand" style="display:none;margin-top:.5rem;">
        <?php foreach ($divergencias as $d): ?>
        <div style="padding:.3rem 0;border-top:1px solid #fed7aa;font-size:.72rem;color:#7c2d12;">
            <strong><?= e($d['lead_name']) ?></strong> —
            Lead #<?= $d['lead_id'] ?> em <code><?= e($d['lead_stage']) ?></code> ↔
            Caso #<?= $d['case_id'] ?> em <code><?= e($d['case_status']) ?></code>
            <span style="color:#9a3412;">→ esperado: <?= e($d['esperado']) ?></span>
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
<div style="display:flex;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;">
        <h3 style="font-size:1rem;font-weight:700;color:var(--petrol-900);margin:0;">Kanban Comercial</h3>
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
        <a href="<?= module_url('pipeline', 'revisar_financeiro.php') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;color:#f59e0b;border-color:#f59e0b;">💰 Revisar Financeiro</a>
        <a href="<?= module_url('pipeline', 'perdidos.php') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Perdidos</a>
    </div>
</div>

<!-- Kanban -->
<style>
#viewKanban::-webkit-scrollbar { height:10px; }
#viewKanban::-webkit-scrollbar-track { background:#f1f5f9; border-radius:5px; }
#viewKanban::-webkit-scrollbar-thumb { background:var(--petrol-500); border-radius:5px; }
#viewKanban::-webkit-scrollbar-thumb:hover { background:var(--petrol-900); }
#viewKanban .pipe-col { width:240px; min-width:240px; flex-shrink:0; scroll-snap-align:start; display:flex; flex-direction:column; }
@media (max-width: 768px) { #viewKanban .pipe-col { width:220px; min-width:220px; } }
</style>
<div id="viewKanban" style="display:flex;gap:.5rem;min-height:400px;overflow-x:auto;padding-bottom:.5rem;scroll-snap-type:x proximity;">
    <?php foreach ($stages as $stageKey => $stage): ?>
    <div class="pipe-col">
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
.tbl-wrap { border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm); }
/* Tabela — table-layout:fixed respeita as larguras declaradas em <th> */
.tbl-grid { border-collapse:separate;border-spacing:0;font-size:.78rem;table-layout:fixed; }
.tbl-grid thead { position:sticky;top:0;z-index:3; }
.tbl-grid th { background:linear-gradient(180deg,var(--petrol-900),var(--petrol-700));color:#fff;padding:8px 10px;text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase;cursor:pointer;user-select:none;white-space:nowrap;border-right:1px solid rgba(255,255,255,.15);border-bottom:1px solid rgba(255,255,255,.15); }
.tbl-grid th:hover { background:var(--petrol-500); }
.tbl-grid td { padding:5px 8px;border-bottom:1px solid #eee;border-right:1px solid #f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
/* Colunas congeladas (# e Nome) — freeze via translateX sincronizado ao scroll horizontal (JS)
   Abordagem robusta: position:sticky tem muitos quirks em <table>; usamos relative + translateX. */
.tbl-grid td.sticky-col-1, .tbl-grid th.sticky-col-1 { position:relative;z-index:2;box-sizing:border-box;min-width:36px;width:36px;max-width:36px;will-change:transform; }
.tbl-grid td.sticky-col-2, .tbl-grid th.sticky-col-2 { position:relative;z-index:2;box-sizing:border-box;min-width:220px;width:220px;max-width:220px;will-change:transform;box-shadow:2px 0 4px -2px rgba(0,0,0,.18); }
.tbl-grid td.sticky-col-1, .tbl-grid td.sticky-col-2 { background:#fff !important; }
.tbl-grid thead th.sticky-col-1, .tbl-grid thead th.sticky-col-2 { z-index:4;background:var(--petrol-900) !important;color:#fff !important; }
.tbl-grid tbody tr:nth-child(even) td.sticky-col-1, .tbl-grid tbody tr:nth-child(even) td.sticky-col-2 { background:#fafbfc !important; }
.tbl-grid tbody tr:hover td.sticky-col-1, .tbl-grid tbody tr:hover td.sticky-col-2 { background:#f5ebe0 !important; }
/* Células em modo display (div) — respeitam largura com reticências */
.cell-inline-display { display:block;width:100%;padding:3px 6px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;cursor:text;border-radius:3px;line-height:1.4;min-height:20px; }
.cell-inline-display:hover { background:rgba(215,171,144,.15);outline:1px dashed var(--rose); }
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
.tbl-grid td.save-error { background:#fee2e2 !important;border:1px solid #ef4444 !important;position:relative; }
.tbl-grid td.save-error::after { content:'⚠';position:absolute;right:4px;top:50%;transform:translateY(-50%);color:#ef4444;font-size:.75rem;font-weight:700; }
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
// Tabela Comercial = planilha histórica de contratos fechados.
// Vem de $leadsPlanilha (converted_at IS NOT NULL, sem filtro de mês do Kanban).
// Leads nunca somem daqui — histórico preservado pra métricas/estatísticas.
$allLeadsFlat = array();
foreach ($leadsPlanilha as $l) {
    $l['_stage_key'] = $l['stage'];
    $allLeadsFlat[] = $l;
}

// ── Ordenação server-side (antes da paginação) ─────────────────
$sortCol = isset($_GET['sort']) ? $_GET['sort'] : '';
$sortDir = (isset($_GET['dir']) && $_GET['dir'] === 'asc') ? 'asc' : 'desc';
$sortMap = array(
    'name' => 'name', 'phone' => 'phone', 'created_at' => 'created_at',
    'case_type' => 'case_type', 'honorarios' => 'honorarios_cents',
    'exito' => 'exito_percentual', 'vencimento' => 'vencimento_parcela',
    'pgto' => 'forma_pagamento', 'responsavel' => 'assigned_name',
    'urgencia' => 'urgencia', 'observacoes' => 'observacoes',
    'asaas' => 'asaas_customer_id', 'estado' => '_stage_key',
);
if ($sortCol && isset($sortMap[$sortCol])) {
    $f = $sortMap[$sortCol];
    $isNum = in_array($f, array('honorarios_cents','exito_percentual'), true);
    // 'created_at' é o que o cabeçalho "Data Fech." envia — mas a coluna mostra
    // converted_at com fallback, então o sort precisa casar (COALESCE).
    $isDate = ($f === 'created_at');
    $useCoalesceDate = ($sortCol === 'created_at');
    usort($allLeadsFlat, function($a, $b) use ($f, $sortDir, $isNum, $isDate, $useCoalesceDate) {
        if ($useCoalesceDate) {
            $av = !empty($a['converted_at']) ? $a['converted_at'] : (isset($a['created_at']) ? $a['created_at'] : null);
            $bv = !empty($b['converted_at']) ? $b['converted_at'] : (isset($b['created_at']) ? $b['created_at'] : null);
        } else {
            $av = isset($a[$f]) ? $a[$f] : null;
            $bv = isset($b[$f]) ? $b[$f] : null;
        }
        // Vazios sempre por último (independente da direção)
        $aEmpty = ($av === null || $av === '' || $av === '0' || $av === 0);
        $bEmpty = ($bv === null || $bv === '' || $bv === '0' || $bv === 0);
        if ($aEmpty && $bEmpty) return 0;
        if ($aEmpty) return 1;
        if ($bEmpty) return -1;
        if ($isNum) {
            $r = ((float)$av) <=> ((float)$bv);
        } elseif ($isDate) {
            $r = strtotime($av) <=> strtotime($bv);
        } else {
            $r = strcasecmp((string)$av, (string)$bv);
        }
        return ($sortDir === 'asc') ? $r : -$r;
    });
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

// Meses disponíveis no banco — só leads com contrato fechado (converted_at).
$mesesDisponiveis = array();
try {
    $mesesDisponiveis = $pdo->query(
        "SELECT DATE_FORMAT(converted_at, '%Y-%m') AS ym
         FROM pipeline_leads
         WHERE converted_at IS NOT NULL AND stage NOT IN ('arquivado')
         GROUP BY ym
         ORDER BY ym DESC"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
$mesesBR = array('01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez');
?>
<div class="tbl-toolbar">
    <select id="filterMes" onchange="filterPipelineByMes(this.value)" class="tbl-filter" title="Filtrar por mês de cadastro">
        <option value="">📅 Todos os meses</option>
        <?php foreach ($mesesDisponiveis as $ym):
            list($yy, $mm) = explode('-', $ym);
            $label = ($mesesBR[$mm] ?? $mm) . '/' . $yy;
        ?>
            <option value="<?= e($ym) ?>" <?= $filterMonth === $ym ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
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
    <span class="tbl-count"><?= count($allLeadsFlat) ?> leads<?= $filterMonth ? ' em ' . e(($mesesBR[substr($filterMonth,5,2)] ?? '') . '/' . substr($filterMonth,0,4)) : '' ?></span>
    <button onclick="exportTableCSV('pipelineTableBody','comercial')" class="tbl-csv">Exportar CSV</button>
</div>
<div class="tbl-wrap" style="max-height:75vh;overflow:auto;overflow-x:scroll;position:relative;width:100%;">
<table class="tbl-grid" id="pipelineTableBody" style="width:2200px;min-width:2200px;">
<?php
// Helper pra gerar link de sort (toggle asc/desc, preserva demais filtros)
$_sortLink = function($col, $label) use ($sortCol, $sortDir) {
    $nextDir = ($sortCol === $col && $sortDir === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $col;
    $params['dir'] = $nextDir;
    unset($params['tp']); // reset paginação
    $url = '?' . http_build_query($params);
    $arrow = '';
    if ($sortCol === $col) {
        $arrow = $sortDir === 'asc' ? ' ↑' : ' ↓';
    }
    return '<a href="' . htmlspecialchars($url) . '" style="color:inherit;text-decoration:none;display:block;">' . htmlspecialchars($label) . $arrow . '</a>';
};
?>
<thead><tr>
    <th class="sticky-col-1" style="width:36px;text-align:center;cursor:default;">#</th>
    <th class="sticky-col-2" style="width:220px;"><?= $_sortLink('name', 'Nome') ?></th>
    <th style="width:180px;"><?= $_sortLink('phone', 'Contato') ?></th>
    <th style="width:120px;"><?= $_sortLink('created_at', 'Data Fech.') ?></th>
    <th style="width:140px;"><?= $_sortLink('case_type', 'Tipo de Ação') ?></th>
    <th style="width:130px;"><?= $_sortLink('honorarios', 'Honorários (R$)') ?></th>
    <th style="width:70px;cursor:default;" title="Em quantas parcelas (1 = à vista)">Parcelas</th>
    <th style="width:110px;cursor:default;" title="Valor de cada parcela (calculado automaticamente)">Valor Parcela</th>
    <th style="width:80px;"><?= $_sortLink('exito', 'Êxito (%)') ?></th>
    <th style="width:120px;"><?= $_sortLink('vencimento', 'Vencto 1ª') ?></th>
    <th style="width:160px;"><?= $_sortLink('pgto', 'Pgto') ?></th>
    <th style="width:110px;"><?= $_sortLink('responsavel', 'Responsável') ?></th>
    <th style="width:120px;"><?= $_sortLink('asaas', 'Asaas') ?></th>
    <th style="width:90px;"><?= $_sortLink('urgencia', 'Urgência') ?></th>
    <th style="width:180px;"><?= $_sortLink('observacoes', 'Observações') ?></th>
    <th style="width:130px;"><?= $_sortLink('estado', 'Estado') ?></th>
    <th style="cursor:default;width:90px;">Mover</th>
    <?php if (function_exists('can_excluir_lead_pipeline') && can_excluir_lead_pipeline()): ?>
    <th style="cursor:default;width:40px;text-align:center;" title="Excluir lead irregular (só Amanda/Luiz)">🗑️</th>
    <?php endif; ?>
</tr></thead>
<tbody>
<?php $n = $pOffset + 1; foreach ($pageLeads as $lead):
    $sk = $lead['_stage_key'];
    $si = isset($stages[$sk]) ? $stages[$sk] : (isset($stagesHistorico[$sk]) ? $stagesHistorico[$sk] : array('label' => ucfirst($sk), 'color' => '#6b7280', 'icon' => '•'));
    $lid = (int)$lead['id'];
?>
<tr data-stage="<?= $sk ?>" data-resp="<?= e($lead['assigned_name'] ?? '') ?>" data-type="<?= e($lead['case_type'] ?? '') ?>">
    <td class="sticky-col-1" style="text-align:center;color:#999;font-size:.7rem;">
        <a href="<?= module_url('pipeline', 'lead_ver.php?id=' . $lid) ?>" style="color:#999;text-decoration:none;" title="Ver detalhes"><?= $n++ ?></a>
    </td>
    <td class="sticky-col-2" style="font-weight:700;color:var(--petrol-900);"><div class="cell-inline-display" title="<?= e($lead['name']) ?>" data-id="<?= $lid ?>" data-field="name" onclick="cellInlineEdit(this)"><?= e($lead['name']) ?></div></td>
    <td style="width:180px;"><div class="cell-inline-display" title="<?= e($lead['phone'] ?? '') ?>" data-id="<?= $lid ?>" data-field="phone" onclick="cellInlineEdit(this)"><?= e($lead['phone'] ?? '') ?: '<span style="color:#cbd5e1;">—</span>' ?></div></td>
    <?php
        $_dataFechamento = !empty($lead['converted_at']) ? $lead['converted_at'] : $lead['created_at'];
        $_isFallback = empty($lead['converted_at']);
        $_tooltip = $_isFallback ? 'Lead ainda não assinou contrato — mostrando data de cadastro. Editar aqui define a data de fechamento.' : 'Data em que o contrato foi assinado.';
    ?>
    <td class="editable" style="min-width:120px;font-size:.72rem;<?= $_isFallback ? 'opacity:.65;font-style:italic;' : '' ?>" title="<?= e($_tooltip) ?>"><input type="date" value="<?= $_dataFechamento ? date('Y-m-d', strtotime($_dataFechamento)) : '' ?>" data-id="<?= $lid ?>" data-field="converted_at" onchange="saveCell(this)" style="font-family:inherit;<?= $_isFallback ? 'font-style:italic;color:#6b7280;' : '' ?>"></td>
    <td class="editable" style="min-width:100px;"><input value="<?= e($lead['case_type'] ?? '') ?>" data-id="<?= $lid ?>" data-field="case_type" onchange="saveCell(this)"></td>
    <?php
        $_honor = (float)($lead['honorarios_cents'] ?? 0) / 100;
        $_parcelas = max(1, (int)($lead['num_parcelas'] ?? 1));
        $_valorParcela = $_honor > 0 ? ($_honor / $_parcelas) : 0;
    ?>
    <td class="editable" style="min-width:100px;"><input type="text" class="col-honor" value="<?= $lead['honorarios_cents'] ? number_format($lead['honorarios_cents']/100, 2, ',', '.') : e($lead['valor_acao'] ?? '') ?>" data-id="<?= $lid ?>" data-field="valor_acao" onchange="saveHonorariosRow(this)" placeholder="0,00"></td>
    <td class="editable" style="min-width:60px;text-align:center;"><input type="number" class="col-parcelas" value="<?= $_parcelas ?>" data-id="<?= $lid ?>" data-field="num_parcelas" onchange="saveParcelas(this)" min="1" max="60" step="1" style="width:55px;text-align:center;" title="Número de parcelas (1 = à vista)"></td>
    <td class="col-valor-parcela" style="text-align:right;font-weight:600;color:var(--petrol-900);font-size:.75rem;" data-id="<?= $lid ?>" title="Honorários ÷ Parcelas"><?= $_valorParcela > 0 ? 'R$ ' . number_format($_valorParcela, 2, ',', '.') : '<span style="color:#cbd5e1;">—</span>' ?></td>
    <td class="editable" style="min-width:60px;"><input type="number" value="<?= e($lead['exito_percentual'] ?? '') ?>" data-id="<?= $lid ?>" data-field="exito_percentual" onchange="saveCell(this)" placeholder="%" step="1" min="0" max="100" style="width:55px;"></td>
    <td class="editable" style="min-width:110px;">
        <?php
            $_venc = $lead['vencimento_parcela'] ?? '';
            $_vencIso = '';
            if ($_venc) {
                // Aceita vários formatos — YYYY-MM-DD, DD/MM/YYYY, "dia 05", etc.
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_venc)) $_vencIso = $_venc;
                elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $_venc, $m)) $_vencIso = $m[3] . '-' . $m[2] . '-' . $m[1];
                else $_vencIso = '';
            }
        ?>
        <input type="date" value="<?= e($_vencIso) ?>" data-id="<?= $lid ?>" data-field="vencimento_parcela" onchange="saveCell(this)" style="font-family:inherit;">
    </td>
    <td class="editable" style="min-width:140px;">
        <?php
            $_fpValor = mb_strtoupper($lead['forma_pagamento'] ?? '');
            $_formas = array('CARTÃO DE CRÉDITO', 'CRÉDITO RECORRENTE', 'PIX RECORRENTE', 'BOLETO', 'À VISTA', 'RISCO');
            // Match inteligente pra valores antigos — normaliza pras 5 opções da whitelist
            $_fpMapped = $_fpValor; // default
            if ($_fpValor && !in_array($_fpValor, $_formas, true)) {
                $_upV = $_fpValor;
                if (strpos($_upV, 'BOLETO') !== false) $_fpMapped = 'BOLETO';
                elseif (strpos($_upV, 'CARTÃO') !== false || strpos($_upV, 'CARTAO') !== false) {
                    $_fpMapped = (strpos($_upV, 'RECORRENTE') !== false) ? 'CRÉDITO RECORRENTE' : 'CARTÃO DE CRÉDITO';
                }
                elseif (strpos($_upV, 'CRÉDITO') !== false || strpos($_upV, 'CREDITO') !== false) {
                    $_fpMapped = (strpos($_upV, 'RECORRENTE') !== false) ? 'CRÉDITO RECORRENTE' : 'CARTÃO DE CRÉDITO';
                }
                elseif (strpos($_upV, 'PIX') !== false) $_fpMapped = 'PIX RECORRENTE';
                elseif (strpos($_upV, 'VISTA') !== false) $_fpMapped = 'À VISTA';
            }
        ?>
        <select data-id="<?= $lid ?>" data-field="forma_pagamento" onchange="saveCell(this)">
            <option value="">—</option>
            <?php foreach ($_formas as $_fp): ?>
                <option value="<?= e($_fp) ?>" <?= $_fpMapped === $_fp ? 'selected' : '' ?>><?= e($_fp) ?></option>
            <?php endforeach; ?>
            <?php if ($_fpValor && !in_array($_fpMapped, $_formas, true)): ?>
                <option value="<?= e($_fpValor) ?>" selected title="Valor antigo não reconhecido automaticamente — escolha a forma correta acima pra normalizar">⚠ <?= e($_fpValor) ?></option>
            <?php endif; ?>
        </select>
    </td>
    <td class="editable" style="min-width:80px;">
        <select data-id="<?= $lid ?>" data-field="assigned_to" onchange="saveCell(this)">
            <option value="">—</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= $lead['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option><?php endforeach; ?>
        </select>
    </td>
    <td style="text-align:center;min-width:140px;">
        <?php
            $_asaasId = $lead['asaas_customer_id'] ?? '';
            $_totalCob = (int)($lead['asaas_total_cobrancas'] ?? 0);
            $_cobAtivas = (int)($lead['asaas_cobrancas_ativas'] ?? 0);
            if (empty($_asaasId)) {
                // Cliente ainda não cadastrado no Asaas
                echo '<span title="Cliente ainda não cadastrado no Asaas" style="background:#fef2f2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:.66rem;font-weight:700;">✕ NÃO</span>';
            } elseif ($_totalCob > 0 && $_cobAtivas === 0) {
                // Cliente cadastrado, mas TODAS as cobranças foram canceladas/reembolsadas
                echo '<span title="Cliente no Asaas, mas todas as ' . $_totalCob . ' cobrança(s) estão canceladas. Crie uma nova pra reativar." style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:.64rem;font-weight:700;">⊘ CANCELADA</span>';
            } else {
                // Cliente cadastrado com cobrança ativa (ou ainda sem cobrança — só customer)
                echo '<span title="Cliente cadastrado no Asaas (' . e($_asaasId) . ') — ' . $_cobAtivas . ' cobrança(s) ativa(s)" style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:.66rem;font-weight:700;">✓ SIM</span>';
            }
        ?>
        <?php if (function_exists('can_access_financeiro') && can_access_financeiro() && (int)$lead['client_id'] > 0): ?>
            <button type="button" onclick="criarCobrancaAsaas(<?= $lid ?>, <?= e(json_encode($lead['name'])) ?>)"
                    title="Criar cobrança no Asaas com os dados desta linha (valor, 1º vencimento, forma de pagamento)"
                    style="background:#B87333;color:#fff;border:none;padding:2px 8px;border-radius:10px;font-size:.66rem;font-weight:700;cursor:pointer;margin-left:3px;">
                💰 Cobrar
            </button>
        <?php endif; ?>
    </td>
    <td class="editable" style="min-width:60px;"><input value="<?= e($lead['urgencia'] ?? '') ?>" data-id="<?= $lid ?>" data-field="urgencia" onchange="saveCell(this)"></td>
    <td class="editable" style="min-width:120px;max-width:180px;"><input value="<?= e($lead['observacoes'] ?? $lead['notes'] ?? '') ?>" data-id="<?= $lid ?>" data-field="observacoes" onchange="saveCell(this)" title="<?= e($lead['observacoes'] ?? $lead['notes'] ?? '') ?>"></td>
    <td>
        <span class="tbl-badge" style="background:<?= $si['color'] ?>;font-size:.6rem;"><?= $si['icon'] ?> <?= $si['label'] ?></span>
    </td>
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
    <?php if (function_exists('can_excluir_lead_pipeline') && can_excluir_lead_pipeline()): ?>
    <td onclick="event.stopPropagation();" style="text-align:center;">
        <button type="button" onclick="excluirLeadPlanilha(<?= $lid ?>, <?= e(json_encode($lead['name'])) ?>)"
                style="background:transparent;border:1px solid #fecaca;color:#dc2626;padding:2px 6px;border-radius:4px;font-size:.78rem;cursor:pointer;" title="Excluir este lead (ação irreversível)">🗑️</button>
    </td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (empty($pageLeads)): ?>
<tr><td colspan="<?= (function_exists('can_excluir_lead_pipeline') && can_excluir_lead_pipeline()) ? 16 : 15 ?>" style="text-align:center;color:#999;padding:2rem;">Nenhum lead no funil.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($totalPages > 1): ?>
<div class="tbl-pag">
    <?php
    $_pageParams = $_GET;
    for ($p = 1; $p <= $totalPages; $p++):
        $_pageParams['tp'] = $p;
        $_pageUrl = '?' . http_build_query($_pageParams);
    ?>
        <a href="<?= htmlspecialchars($_pageUrl) ?>" class="<?= $p === $tabelaPage ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
</div>

<!-- Modal: Nome da Pasta (ao mover para contrato_assinado) -->
<!-- Modal: Doc Faltante (Pipeline) -->
<div id="docFaltanteModalPipe" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#dc2626;margin-bottom:.5rem;">Documento Faltante</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">Descreva quais documentos estão faltando. O Operacional será notificado.</p>
        <p style="font-size:.7rem;color:#0ea5e9;margin-bottom:.5rem;background:#eff6ff;padding:.35rem .5rem;border-radius:6px;">Separe com <strong>;</strong> (ponto e vírgula) para criar um checklist.</p>
        <textarea id="docFaltanteDescPipe" rows="3" style="width:100%;padding:.6rem .8rem;font-size:.88rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;resize:vertical;" placeholder="Ex: Certidão de nascimento ; Comprovante de renda ; RG do menor"></textarea>
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="document.getElementById('docFaltanteModalPipe').style.display='none';_pendingForm=null;" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmDocFaltantePipe()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#dc2626;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Sinalizar</button>
        </div>
    </div>
</div>

<div id="folderModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.25rem;">Contrato Assinado</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">Uma pasta será criada no Drive e no Operacional.</p>
        <div style="margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Tipo de ação</label>
            <select id="folderTipoAcao" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" onchange="atualizarNomePasta()">
                <option value="">— Selecione —</option>
                <?php
                $tiposAcao = array('Alimentos','Revisão de Alimentos','Execução de Alimentos','Execução de Alimentos - Rito Prisão','Execução de Alimentos - Rito Penhora','Exoneração de Alimentos',
                    'Divórcio','Divórcio Consensual','Divórcio Litigioso','Guarda','Guarda Compartilhada',
                    'Regulamentação de Convivência','Convivência','Investigação de Paternidade',
                    'Medida Protetiva','Tutela de Urgência','Inventário','Usucapião',
                    'Indenização','Consignatória','Trabalhista','Outro');
                foreach ($tiposAcao as $ta): ?>
                <option value="<?= e($ta) ?>"><?= e($ta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Nome da pasta</label>
            <input type="text" id="folderNameInput" style="width:100%;padding:.65rem .85rem;font-size:.95rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;" placeholder="Ex: Ana Maria Braga x Alimentos">
        </div>
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="closeFolderModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600;color:#6b7280;">Cancelar</button>
            <button onclick="confirmFolder()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Criar Pasta</button>
        </div>
    </div>
</div>

<script>
var _pendingForm = null;
var _pendingDragData = null;

// Criar cobrança no Asaas a partir de uma linha da Planilha (só autorizados: Amanda/Rodrigo/Luiz)
function criarCobrancaAsaas(leadId, nome) {
    if (!confirm('Criar cobrança no Asaas para "' + nome + '"?\n\n'
               + 'O sistema vai usar o valor, vencimento e forma de pagamento desta linha.\n\n'
               + 'Se o cliente ainda não está cadastrado no Asaas, será cadastrado automaticamente.\n\n'
               + 'Prossegue?')) return;

    var btn = null;
    try { btn = event && event.target && event.target.closest ? event.target.closest('button') : null; } catch(e) {}
    if (btn) { btn.disabled = true; btn.textContent = 'Criando...'; }

    var csrf = window._FSA_CSRF || '<?= generate_csrf_token() ?>';
    var fd = new FormData();
    fd.append('action', 'criar_cobranca_lead');
    fd.append('lead_id', leadId);
    fd.append('csrf_token', csrf);

    fetch('<?= module_url('financeiro', 'api.php') ?>', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){
        return r.text().then(function(t){
            var body = {};
            try { body = JSON.parse(t); } catch(e) { body = { error: 'Resposta inválida (HTTP ' + r.status + ')' }; }
            return { status: r.status, body: body };
        });
    }).then(function(res){
        console.log('[criarCobrancaAsaas]', res.status, res.body);
        if (res.body.ok) {
            var msg = '✅ ' + (res.body.msg || 'Cobrança criada!');
            if (res.body.invoice_url) {
                if (confirm(msg + '\n\nAbrir link da fatura em nova aba?')) {
                    window.open(res.body.invoice_url, '_blank');
                }
            } else {
                alert(msg);
            }
            if (btn) { btn.disabled = false; btn.textContent = '✓ Criada'; btn.style.background = '#059669'; }
        } else {
            alert('❌ Falha: ' + (res.body.error || ('HTTP ' + res.status)));
            if (btn) { btn.disabled = false; btn.textContent = '💰 Cobrar'; }
        }
    }).catch(function(e){
        alert('Erro de rede: ' + e.message);
        if (btn) { btn.disabled = false; btn.textContent = '💰 Cobrar'; }
    });
}

// Excluir lead irregular (só Amanda/Luiz) — usa duas confirmações em vez de prompt
function excluirLeadPlanilha(leadId, nome) {
    var msg1 = 'Remover "' + nome + '" SÓ DA PLANILHA COMERCIAL?\n\n'
             + '✅ O cliente permanece cadastrado (CRM, Operacional, Financeiro).\n'
             + '✅ Processos, documentos e cobranças ficam intactos.\n'
             + '❌ Apenas a linha do Comercial é removida.\n\n'
             + 'Confirma? (próxima tela pede confirmação final)';
    if (!confirm(msg1)) return;
    if (!confirm('⚠️ ÚLTIMA CONFIRMAÇÃO\n\nRemover "' + nome + '" da Planilha Comercial? Isso é irreversível (na planilha).')) return;

    var btn = null;
    try { btn = event && event.target && event.target.closest ? event.target.closest('button') : null; } catch(e) {}
    var row = btn && btn.closest ? btn.closest('tr') : null;
    if (btn) { btn.disabled = true; btn.textContent = '...'; }

    var csrf = window._FSA_CSRF || '<?= generate_csrf_token() ?>';
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('lead_id', leadId);
    fd.append('csrf_token', csrf);
    fetch('<?= module_url('pipeline', 'api.php') ?>', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){
        return r.text().then(function(t){
            var body = {};
            try { body = JSON.parse(t); } catch(e) { body = { error: 'Resposta não-JSON', raw: t.substring(0,200) }; }
            return { status: r.status, body: body };
        });
    }).then(function(res){
        console.log('[excluirLead] status=' + res.status, res.body);
        if (res.body && res.body.ok) {
            if (row) row.remove();
            // Toast discreto em vez de alert
            var toast = document.createElement('div');
            toast.textContent = '✓ Lead "' + nome + '" removido da planilha.';
            toast.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#059669;color:#fff;padding:10px 18px;border-radius:8px;font-weight:600;z-index:100000;box-shadow:0 8px 24px rgba(0,0,0,.25);';
            document.body.appendChild(toast);
            setTimeout(function(){ toast.remove(); }, 3000);
        } else {
            if (btn) { btn.disabled = false; btn.textContent = '🗑️'; }
            var err = (res.body && res.body.error) ? res.body.error : ('HTTP ' + res.status);
            alert('Falha ao excluir: ' + err);
        }
    }).catch(function(e){
        if (btn) { btn.disabled = false; btn.textContent = '🗑️'; }
        console.error('[excluirLead] erro de rede:', e);
        alert('Erro de rede: ' + e.message);
    });
}

function handleStageMove(select) {
    var stage = select.value;
    if (!stage) return;
    var form = select.closest('form');

    if (stage === 'doc_faltante') {
        _pendingForm = form;
        _pendingDragData = null;
        document.getElementById('docFaltanteModalPipe').style.display = 'flex';
        document.getElementById('docFaltanteDescPipe').focus();
        select.value = '';
        return;
    }

    if (stage === 'contrato_assinado') {
        var leadName = form.dataset.leadName || '';
        var caseType = form.dataset.caseType || '';
        window._folderLeadName = leadName;
        var tipoSel = document.getElementById('folderTipoAcao');
        // Pré-selecionar tipo se já existe
        if (caseType && tipoSel) {
            for (var i = 0; i < tipoSel.options.length; i++) {
                if (tipoSel.options[i].value === caseType) { tipoSel.selectedIndex = i; break; }
            }
        } else if (tipoSel) {
            tipoSel.selectedIndex = 0;
        }
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

function confirmDocFaltantePipe() {
    var desc = document.getElementById('docFaltanteDescPipe').value.trim();
    if (!desc) { document.getElementById('docFaltanteDescPipe').style.borderColor = '#ef4444'; return; }
    document.getElementById('docFaltanteModalPipe').style.display = 'none';
    if (_pendingForm) {
        var sel = _pendingForm.querySelector('select[name="to_stage"]');
        if (sel) sel.removeAttribute('name');
        var inp1 = document.createElement('input'); inp1.type = 'hidden'; inp1.name = 'to_stage'; inp1.value = 'doc_faltante'; _pendingForm.appendChild(inp1);
        var inp2 = document.createElement('input'); inp2.type = 'hidden'; inp2.name = 'doc_faltante_desc'; inp2.value = desc; _pendingForm.appendChild(inp2);
        _pendingForm.submit();
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

function atualizarNomePasta() {
    var tipo = document.getElementById('folderTipoAcao').value;
    var nome = window._folderLeadName || '';
    if (tipo && nome) {
        document.getElementById('folderNameInput').value = nome + ' x ' + tipo;
    }
}

function confirmFolder() {
    var folderName = document.getElementById('folderNameInput').value.trim();
    if (!folderName) { document.getElementById('folderNameInput').style.borderColor = '#ef4444'; return; }
    var tipoAcao = document.getElementById('folderTipoAcao').value;
    document.getElementById('folderModal').style.display = 'none';

    // Tocar sino de celebração!
    playCelebration();

    if (_pendingForm) {
        _pendingForm.querySelector('input[name="folder_name"]').value = folderName;
        // Adicionar tipo de ação ao form se selecionado
        if (tipoAcao) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'case_type_selected'; inp.value = tipoAcao;
            _pendingForm.appendChild(inp);
        }
        _pendingForm.submit();
    } else if (_pendingDragData) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = _pendingDragData.apiUrl;
        form.innerHTML = '<input type="hidden" name="csrf_token" value="' + _pendingDragData.csrfToken + '">' +
            '<input type="hidden" name="action" value="move">' +
            '<input type="hidden" name="lead_id" value="' + _pendingDragData.leadId + '">' +
            '<input type="hidden" name="to_stage" value="contrato_assinado">' +
            '<input type="hidden" name="folder_name" value="' + folderName.replace(/"/g, '&quot;') + '">' +
            (tipoAcao ? '<input type="hidden" name="case_type_selected" value="' + tipoAcao.replace(/"/g, '&quot;') + '">' : '');
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

            if (toStage === 'doc_faltante') {
                // Criar form temporário para usar no confirm
                var tmpForm = document.createElement('form');
                tmpForm.method = 'POST'; tmpForm.action = apiUrl;
                tmpForm.innerHTML = '<input type="hidden" name="csrf_token" value="' + csrfToken + '"><input type="hidden" name="action" value="move"><input type="hidden" name="lead_id" value="' + draggedId + '">';
                document.body.appendChild(tmpForm);
                _pendingForm = tmpForm;
                _pendingDragData = null;
                document.getElementById('docFaltanteModalPipe').style.display = 'flex';
                document.getElementById('docFaltanteDescPipe').focus();
                return;
            }

            if (toStage === 'contrato_assinado') {
                window._folderLeadName = draggedName;
                var tipoSel = document.getElementById('folderTipoAcao');
                if (draggedType && tipoSel) {
                    for (var ti = 0; ti < tipoSel.options.length; ti++) {
                        if (tipoSel.options[ti].value === draggedType) { tipoSel.selectedIndex = ti; break; }
                    }
                } else if (tipoSel) { tipoSel.selectedIndex = 0; }
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
// Edição inline: div exibe com ellipsis, clique troca por input temporário.
// Resolve o bug do <input> mostrar o FIM do texto quando não cabe.
function cellInlineEdit(div) {
    if (div.querySelector('input')) return; // já em edição
    var id = div.dataset.id;
    var field = div.dataset.field;
    var valor = div.title || div.textContent.trim();
    if (valor === '—') valor = '';
    var input = document.createElement('input');
    input.type = 'text';
    input.value = valor;
    input.dataset.id = id;
    input.dataset.field = field;
    input.style.cssText = 'width:100%;border:2px solid var(--rose);background:#fff;padding:2px 6px;font:inherit;outline:none;border-radius:3px;';
    div.innerHTML = '';
    div.appendChild(input);
    input.focus();
    input.select();
    var terminarEdicao = function(salvar) {
        if (input.parentNode !== div) return; // já foi processado
        var novoValor = input.value;
        if (salvar && novoValor !== valor) {
            saveCell(input); // reaproveita a função existente
        }
        div.innerHTML = novoValor ? escapeHtmlSimple(novoValor) : '<span style="color:#cbd5e1;">—</span>';
        div.title = novoValor;
    };
    input.addEventListener('blur', function(){ terminarEdicao(true); });
    input.addEventListener('keydown', function(ev){
        if (ev.key === 'Enter') { ev.preventDefault(); input.blur(); }
        if (ev.key === 'Escape') { ev.preventDefault(); terminarEdicao(false); }
    });
}
function escapeHtmlSimple(s) {
    return (s || '').replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; });
}

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
        var ok = false, errMsg = '';
        // 401 = sessão expirada → modal visual
        if (xhr.status === 401 && window.fsaMostrarSessaoExpirada) {
            window.fsaMostrarSessaoExpirada();
            if (td) td.classList.add('save-error');
            return;
        }
        if (xhr.status === 200) {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp && resp.ok) ok = true;
                else errMsg = (resp && resp.error) ? resp.error : 'Resposta inválida';
            } catch(e) {
                errMsg = 'Resposta não-JSON. Recarregue (F5).';
            }
        } else {
            try { var j = JSON.parse(xhr.responseText); errMsg = j.error || ('HTTP ' + xhr.status); }
            catch(e) { errMsg = 'HTTP ' + xhr.status; }
        }
        if (td) {
            if (ok) {
                td.classList.add('saved'); setTimeout(function(){ td.classList.remove('saved'); }, 1500);
            } else {
                td.classList.add('save-error'); td.title = 'ERRO: ' + errMsg;
                if (!window._pipeErrShown) { window._pipeErrShown = true; alert('⚠️ Falha ao salvar: ' + errMsg); setTimeout(function(){ window._pipeErrShown = false; }, 5000); }
            }
        }
    };
    xhr.onerror = function() {
        if (td) { td.classList.add('save-error'); }
        if (!window._pipeErrShown) { window._pipeErrShown = true; alert('⚠️ Falha de rede ao salvar. Verifique sua conexão.'); setTimeout(function(){ window._pipeErrShown = false; }, 5000); }
    };
    xhr.send(formData);
}
function saveHonorarios(el) {
    // Formatar como moeda BR e salvar via valor_acao (sync automático no backend)
    var raw = el.value.replace(/[^\d.,]/g, '');
    if (raw) {
        var num = parseFloat(raw.replace(/\./g, '').replace(',', '.'));
        if (!isNaN(num) && num > 0) {
            el.value = num.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }
    saveCell(el);
}
// Versão pra Planilha Comercial: formata, salva E recalcula o valor da parcela na mesma linha
function saveHonorariosRow(el) {
    saveHonorarios(el);
    recalcValorParcela(el.closest('tr'));
}
// Salva num_parcelas + recalcula valor da parcela
function saveParcelas(el) {
    var n = parseInt(el.value, 10);
    if (isNaN(n) || n < 1) n = 1;
    if (n > 60) n = 60;
    el.value = n;
    saveCell(el);
    recalcValorParcela(el.closest('tr'));
}
// Recalcula e renderiza a célula "Valor Parcela" de uma linha
function recalcValorParcela(tr) {
    if (!tr) return;
    var honorEl = tr.querySelector('.col-honor');
    var parcelasEl = tr.querySelector('.col-parcelas');
    var displayEl = tr.querySelector('.col-valor-parcela');
    if (!honorEl || !parcelasEl || !displayEl) return;
    var raw = (honorEl.value || '').replace(/[^\d,]/g, '').replace(',', '.');
    var honor = parseFloat(raw);
    var parcelas = Math.max(1, parseInt(parcelasEl.value, 10) || 1);
    if (!isNaN(honor) && honor > 0) {
        var v = honor / parcelas;
        displayEl.textContent = 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        displayEl.style.color = 'var(--petrol-900)';
    } else {
        displayEl.innerHTML = '<span style="color:#cbd5e1;">—</span>';
    }
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
        // Kanban original é flex (colunas lado a lado com scroll horizontal)
        k.style.display = 'flex'; t.style.display = 'none';
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

// Filtro de mês: server-side. Preserva busca (q) e aba de tabela.
function filterPipelineByMes(ym) {
    var params = new URLSearchParams(window.location.search);
    if (ym) params.set('mes', ym); else params.delete('mes');
    params.delete('tp'); // reset paginação ao trocar filtro
    // Flag pra reabrir aba Tabela após reload
    try { localStorage.setItem('pipeline_view', 'tabela'); } catch(e) {}
    window.location.search = params.toString();
}

// Ordenação agora é server-side (via ?sort=col&dir=asc|desc no link do header).
// Evita o bug antigo onde sort client-side só reordenava os 25 da página atual.

// Freeze colunas # e Nome via translateX sincronizado ao scroll horizontal.
// position:sticky em <td> tem muitos quirks (table-layout, ancestors com overflow/transform, etc).
// Esta abordagem sempre funciona, independente do contexto.
(function(){
    function initFreeze(){
        var wrap = document.querySelector('#viewTabela .tbl-wrap');
        if (!wrap) return;
        var cells = wrap.querySelectorAll('.sticky-col-1, .sticky-col-2');
        if (!cells.length) { console.warn('[freeze] sem células sticky-col'); return; }
        function sync(){
            var x = wrap.scrollLeft;
            // "left" em position:relative funciona em <td>; "transform" é ignorado em table-cell no Chrome
            for (var i = 0; i < cells.length; i++) {
                cells[i].style.left = x + 'px';
            }
        }
        // Escuta scroll no wrapper E no window (fallback caso scroll esteja no body)
        wrap.addEventListener('scroll', sync, { passive: true });
        window.addEventListener('scroll', function(){
            // Se o scrollLeft do body > 0, reposiciona assumindo scroll na página
            var bodyX = window.scrollX || document.documentElement.scrollLeft || 0;
            if (bodyX > 0 && wrap.scrollLeft === 0) {
                for (var i = 0; i < cells.length; i++) cells[i].style.left = bodyX + 'px';
            }
        }, { passive: true });
        sync();
        console.info('[freeze] ativo —', cells.length, 'células congeladas no wrapper de', wrap.clientWidth + 'px (table:', wrap.scrollWidth + 'px)');
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initFreeze);
    else initFreeze();
})();

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
