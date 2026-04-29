<?php
/**
 * Ferreira & Sá Hub — Kanban PREV (Previdenciário)
 * Fluxo completo: Aguardando Docs → Pasta Apta → Análise INSS →
 *        Perícia → Recurso → Ação Judicial → Sentença → Implantação
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
// Antes usava can_view_operacional() (só admin/gestão/operacional). Agora
// permite override individual via user_permissions['prev'] — Simone, p.ex.,
// só tem acesso a esse módulo, sem precisar liberar Operacional inteiro.
if (!can_access('prev')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'Kanban PREV';
$pdo = db();
$userId = current_user_id();
$isColaborador = has_role('colaborador');
$canMove = can_move_operacional();

// Filtros
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$filterSearch = isset($_GET['q']) ? trim($_GET['q']) : '';
$filterTipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$filterComarca = isset($_GET['comarca']) ? trim($_GET['comarca']) : '';

// Colunas do Kanban PREV
// Self-heal: tabela genérica pra esconder colunas de Kanbans por usuário.
// Permite ocultar qualquer aba pra um user específico sem mexer no código.
try { $pdo->exec("CREATE TABLE IF NOT EXISTS user_kanban_hidden_columns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kanban_modulo VARCHAR(40) NOT NULL,
    column_key VARCHAR(60) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_kanban_col (user_id, kanban_modulo, column_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

$columns = array(
    'aguardando_docs'           => array('label' => 'Aguardando Docs',            'color' => '#E67E22', 'icon' => '📁'),
    'pasta_apta'                => array('label' => 'Pasta Apta',                  'color' => '#27AE60', 'icon' => '✅'),
    'aguardando_analise_inss'   => array('label' => 'Aguardando Análise INSS',     'color' => '#2980B9', 'icon' => '⏳'),
    'aguardando_pericia'        => array('label' => 'Aguardando Perícia Médica',   'color' => '#8E44AD', 'icon' => '🩺'),
    'recurso_administrativo'    => array('label' => 'Recurso Administrativo',      'color' => '#F39C12', 'icon' => '📋'),
    'recurso_crps'              => array('label' => 'Recurso CRPS/CAJ',            'color' => '#D35400', 'icon' => '⚖️'),
    'acao_judicial'             => array('label' => 'Ação Judicial em Andamento',  'color' => '#1A5276', 'icon' => '🏛️'),
    'aguardando_sentenca'       => array('label' => 'Aguardando Sentença',         'color' => '#717D7E', 'icon' => '⏰'),
    'cumprimento_precatorio'    => array('label' => 'Cumprimento/Precatório',      'color' => '#1E8449', 'icon' => '💰'),
    'aguardando_implantacao'    => array('label' => 'Aguardando Implantação',      'color' => '#148F77', 'icon' => '🎯'),
    'suspenso'                  => array('label' => 'Suspenso',                    'color' => '#5B2D8E', 'icon' => '⏸️'),
    'parceria'                  => array('label' => 'Parceria',                    'color' => '#17A589', 'icon' => '🤝'),
    'cancelado'                 => array('label' => 'Cancelado',                   'color' => '#616A6B', 'icon' => '✕'),
);

// Esconde colunas configuradas pra esse usuário (ex: Simone não vê 'Parceria')
try {
    $stHide = $pdo->prepare("SELECT column_key FROM user_kanban_hidden_columns WHERE user_id = ? AND kanban_modulo = 'prev'");
    $stHide->execute(array($userId));
    foreach ($stHide->fetchAll(PDO::FETCH_COLUMN) as $colKey) {
        if (isset($columns[$colKey])) unset($columns[$colKey]);
    }
} catch (Exception $e) {}

// Tipos de benefício
$tiposBeneficio = array('INSS','BPC','LOAS','Aposentadoria por Idade','Aposentadoria por Invalidez','Auxílio-Doença','Auxílio-Acidente','Pensão por Morte','Salário-Maternidade');

// Cores dos badges
$tipoBadgeColors = array(
    'INSS' => '#2980B9', 'BPC' => '#8E44AD', 'LOAS' => '#D35400',
    'Aposentadoria por Idade' => '#1A5276', 'Aposentadoria por Invalidez' => '#C0392B',
    'Auxílio-Doença' => '#E67E22', 'Auxílio-Acidente' => '#F39C12',
    'Pensão por Morte' => '#5B2D8E', 'Salário-Maternidade' => '#148F77',
);

// Construir query — só casos com kanban_prev = 1
$where = array('cs.kanban_prev = 1');
$params = array();

if ($isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = $userId;
}
if ($filterUser && !$isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = (int)$filterUser;
}
if ($filterSearch) {
    $where[] = "(cs.title LIKE ? OR c.name LIKE ? OR cs.case_number LIKE ? OR cs.prev_tipo_beneficio LIKE ?)";
    $s = "%$filterSearch%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($filterTipo) {
    $where[] = "cs.prev_tipo_beneficio = ?";
    $params[] = $filterTipo;
}
if ($filterComarca) {
    $where[] = "cs.comarca LIKE ?";
    $params[] = "%$filterComarca%";
}

$whereStr = implode(' AND ', $where);

// Self-heal da coluna senha_gov (pra exibição no card PREV)
try { $pdo->exec("ALTER TABLE clients ADD COLUMN senha_gov VARCHAR(100) NULL"); } catch (Exception $e) {}

$sql = "SELECT cs.*, c.name as client_name, c.phone as client_phone, c.senha_gov as client_senha_gov, u.name as responsible_name,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status NOT IN ('concluido','feito')) as pending_tasks,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status IN ('concluido','feito')) as done_tasks
        FROM cases cs
        LEFT JOIN clients c ON c.id = cs.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE $whereStr AND cs.status NOT IN ('concluido','arquivado') AND IFNULL(cs.kanban_oculto, 0) = 0
        ORDER BY FIELD(cs.priority, 'urgente','alta','normal','baixa'), cs.deadline ASC, cs.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allCases = $stmt->fetchAll();

// Buscar documentos pendentes
$docsPendentes = array();
try {
    $stmtDocs = $pdo->query("SELECT id, case_id, descricao, status FROM documentos_pendentes WHERE case_id IS NOT NULL ORDER BY solicitado_em ASC");
    foreach ($stmtDocs->fetchAll() as $doc) {
        $cid = (int)$doc['case_id'];
        if (!isset($docsPendentes[$cid])) $docsPendentes[$cid] = array();
        $docsPendentes[$cid][] = $doc;
    }
} catch (Exception $e) {}

// Agrupar por prev_status
$byStatus = array();
foreach (array_keys($columns) as $s) { $byStatus[$s] = array(); }
foreach ($allCases as $cs) {
    $prevSt = isset($cs['prev_status']) ? $cs['prev_status'] : 'aguardando_docs';
    // Cancelados: só no mês que cancelou
    if ($prevSt === 'cancelado') {
        $mesRef = date('Y-m', strtotime($cs['updated_at']));
        if ($mesRef !== date('Y-m')) continue;
    }
    if (!isset($byStatus[$prevSt])) { $prevSt = 'aguardando_docs'; }
    $byStatus[$prevSt][] = $cs;
}

// KPIs
$totalAtivos = count($allCases);
$urgentes = 0;
foreach ($allCases as $cs) { if ($cs['priority'] === 'urgente') $urgentes++; }

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$parceirosAtivos = array();
try { $parceirosAtivos = $pdo->query("SELECT id, nome, area FROM parceiros WHERE ativo = 1 ORDER BY nome")->fetchAll(); } catch (Exception $e) {}
$priorityColors = array('urgente' => '#ef4444', 'alta' => '#f59e0b', 'normal' => '#6366f1', 'baixa' => '#9ca3af');

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pv-topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:.75rem; flex-wrap:wrap; gap:.5rem; }
.pv-topbar h3 { font-size:.95rem; font-weight:700; color:var(--petrol-900); }
.pv-filters { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
.pv-filter-select { font-size:.72rem; padding:.3rem .45rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); color:var(--text); cursor:pointer; }

.pv-kpis { display:flex; gap:.75rem; margin-bottom:.75rem; flex-wrap:wrap; }
.pv-kpi { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.6rem .85rem; display:flex; align-items:center; gap:.5rem; min-width:120px; }
.pv-kpi-value { font-size:1.2rem; font-weight:800; color:var(--petrol-900); }
.pv-kpi-label { font-size:.62rem; color:var(--text-muted); text-transform:uppercase; }

.pv-board { display:flex; gap:.5rem; min-height:450px; overflow-x:auto; padding-bottom:.5rem; scroll-snap-type:x proximity; }
.pv-board::-webkit-scrollbar { height:10px; }
.pv-board::-webkit-scrollbar-track { background:#f1f5f9; border-radius:5px; }
.pv-board::-webkit-scrollbar-thumb { background:var(--petrol-500); border-radius:5px; }
.pv-board::-webkit-scrollbar-thumb:hover { background:var(--petrol-900); }
.pv-column { display:flex; flex-direction:column; width:240px; min-width:240px; flex-shrink:0; scroll-snap-align:start; }
.pv-col-header { padding:.55rem .7rem; border-radius:var(--radius) var(--radius) 0 0; color:#fff; font-weight:700; font-size:.72rem; display:flex; justify-content:space-between; align-items:center; gap:.3rem; }
.pv-col-header > span:first-child { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pv-col-header .count { background:rgba(255,255,255,.25); padding:.1rem .4rem; border-radius:100px; font-size:.6rem; flex-shrink:0; }
.pv-col-body { flex:1; background:var(--bg); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius) var(--radius); padding:.4rem; display:flex; flex-direction:column; gap:.4rem; min-height:80px; overflow-y:auto; max-height:70vh; }
.pv-col-body.drag-over { background:rgba(59,79,160,.08); border-color:rgba(59,79,160,.4); }

.pv-card { background:var(--bg-card); border-radius:var(--radius); padding:.55rem .65rem; box-shadow:var(--shadow-sm); border-left:4px solid #ccc; cursor:grab; transition:all var(--transition); }
.pv-card:hover { box-shadow:var(--shadow-md); transform:translateY(-1px); }
.pv-card.dragging { opacity:.4; cursor:grabbing; }
.pv-card-name { font-weight:700; font-size:.78rem; color:var(--petrol-900); margin-bottom:.2rem; line-height:1.25; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.pv-card-client { font-size:.65rem; color:var(--text-muted); margin-bottom:.2rem; }
.pv-card-badges { display:flex; gap:.15rem; flex-wrap:wrap; margin-bottom:.25rem; }
.pv-card-badge { font-size:.55rem; font-weight:700; padding:.1rem .3rem; border-radius:4px; color:#fff; text-transform:uppercase; }
.pv-card-footer { display:flex; justify-content:space-between; align-items:center; }
.pv-card-resp { font-size:.6rem; color:var(--rose-dark); font-weight:600; }
.pv-card-tasks { font-size:.6rem; color:var(--text-muted); display:flex; align-items:center; gap:.2rem; }
.pv-card-tasks .mini-bar { width:35px; height:3px; background:var(--border); border-radius:3px; overflow:hidden; display:inline-block; }
.pv-card-tasks .mini-fill { height:100%; background:var(--success); border-radius:3px; display:block; }
.pv-card-process { font-size:.58rem; color:var(--petrol-500); font-weight:600; margin-top:.2rem; }
.pv-card-move { margin-top:.3rem; width:100%; font-size:.6rem; padding:.2rem .25rem; border:1px solid var(--border); border-radius:4px; background:var(--bg-card); cursor:pointer; }
.pv-empty { text-align:center; padding:1rem .5rem; color:var(--text-muted); font-size:.7rem; }

.page-content { max-width:none !important; padding:.75rem !important; }
@media (max-width: 768px) { .pv-column { width:220px; min-width:220px; } }
</style>

<!-- KPIs -->
<div class="pv-kpis">
    <div class="pv-kpi"><span style="font-size:1rem;">🏛️</span><div><div class="pv-kpi-value"><?= $totalAtivos ?></div><div class="pv-kpi-label"><?= $isColaborador ? 'Seus casos PREV' : 'Casos PREV ativos' ?></div></div></div>
    <?php if ($urgentes > 0): ?><div class="pv-kpi"><span style="font-size:1rem;">🔴</span><div><div class="pv-kpi-value"><?= $urgentes ?></div><div class="pv-kpi-label">Urgentes</div></div></div><?php endif; ?>
    <?php
    // Enviados este mês
    $enviadosMes = 0;
    foreach ($allCases as $cs) {
        if ((int)($cs['prev_mes_envio'] ?? 0) === (int)date('n') && (int)($cs['prev_ano_envio'] ?? 0) === (int)date('Y')) $enviadosMes++;
    }
    if ($enviadosMes > 0): ?>
    <div class="pv-kpi"><span style="font-size:1rem;">📥</span><div><div class="pv-kpi-value"><?= $enviadosMes ?></div><div class="pv-kpi-label">Novos este mês</div></div></div>
    <?php endif; ?>
</div>

<!-- Filtros -->
<div class="pv-topbar">
    <div style="display:flex;align-items:center;gap:1rem;">
        <h3 style="margin:0;">🏛️ Kanban PREV</h3>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <a href="<?= module_url('prev', 'caso_novo.php') ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#3B4FA0;">+ Novo Processo PREV</a>
        <form method="GET" class="pv-filters">
            <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="Buscar nome, nº, tipo..." class="pv-filter-select" style="min-width:160px;" onkeydown="if(event.key==='Enter')this.form.submit()">
            <select name="tipo" class="pv-filter-select" onchange="this.form.submit()">
                <option value="">Tipo de Benefício</option>
                <?php foreach ($tiposBeneficio as $tb): ?>
                    <option value="<?= e($tb) ?>" <?= $filterTipo === $tb ? 'selected' : '' ?>><?= e($tb) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isColaborador): ?>
            <select name="user" class="pv-filter-select" onchange="this.form.submit()">
                <option value="">Responsável</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="text" name="comarca" value="<?= e($filterComarca) ?>" placeholder="Comarca..." class="pv-filter-select" style="min-width:100px;" onkeydown="if(event.key==='Enter')this.form.submit()">
            <?php if ($filterTipo || $filterUser || $filterSearch || $filterComarca): ?>
                <a href="<?= module_url('prev') ?>" class="btn btn-outline btn-sm" style="font-size:.65rem;">Limpar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Board Kanban PREV -->
<div class="pv-board" id="viewPrevKanban">
    <?php foreach ($columns as $colKey => $col): ?>
    <div class="pv-column">
        <div class="pv-col-header" style="background:<?= $col['color'] ?>;">
            <span><?= $col['icon'] ?> <?= $col['label'] ?></span>
            <span class="count"><?= count($byStatus[$colKey]) ?></span>
        </div>
        <div class="pv-col-body" data-status="<?= $colKey ?>">
            <?php if (empty($byStatus[$colKey])): ?>
                <div class="pv-empty">Nenhum caso</div>
            <?php else: ?>
                <?php foreach ($byStatus[$colKey] as $cs):
                    $totalTasks = $cs['pending_tasks'] + $cs['done_tasks'];
                    $taskPct = $totalTasks > 0 ? round(($cs['done_tasks'] / $totalTasks) * 100) : 0;
                    $pColor = isset($priorityColors[$cs['priority']]) ? $priorityColors[$cs['priority']] : '#9ca3af';
                    $tipoBen = isset($cs['prev_tipo_beneficio']) ? $cs['prev_tipo_beneficio'] : '';
                    $tipoBenColor = isset($tipoBadgeColors[$tipoBen]) ? $tipoBadgeColors[$tipoBen] : '#3B4FA0';
                    $diasUpdate = (int)((time() - strtotime($cs['updated_at'])) / 86400);
                ?>
                <div class="pv-card" draggable="true" data-case-id="<?= $cs['id'] ?>" data-client-id="<?= (int)($cs['client_id'] ?? 0) ?>" style="border-left-color:<?= $pColor ?>;"
                     onclick="if(!event.target.closest('select,form,.pv-card-move,button'))window.location='<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>'">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div class="pv-card-name" style="flex:1;"><?= e($cs['title'] ?: 'Caso #' . $cs['id']) ?></div>
                        <div style="display:flex;gap:2px;flex-shrink:0;margin-left:4px;">
                            <?php if ($cs['drive_folder_url']): ?>
                                <a href="<?= e($cs['drive_folder_url']) ?>" onclick="event.stopPropagation();" target="_blank" title="Pasta Drive" style="font-size:.85rem;text-decoration:none;">📁</a>
                            <?php endif; ?>
                            <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>" onclick="event.stopPropagation();" target="_blank" title="Abrir pasta" style="font-size:.85rem;text-decoration:none;">📂</a>
                        </div>
                    </div>
                    <div class="pv-card-client">👤 <?= e($cs['client_name'] ?: 'Sem cliente') ?></div>
                    <div class="pv-card-badges">
                        <?php if ($tipoBen): ?>
                            <span class="pv-card-badge" style="background:<?= $tipoBenColor ?>;"><?= e($tipoBen) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="pv-card-footer">
                        <span class="pv-card-resp"><?= e($cs['responsible_name'] ? explode(' ', $cs['responsible_name'])[0] : '—') ?></span>
                        <span style="font-size:.65rem;color:<?= $diasUpdate > 30 ? '#dc2626' : ($diasUpdate > 7 ? '#f59e0b' : 'var(--text-muted)') ?>;"><?= $diasUpdate ?>d</span>
                        <?php if ($totalTasks > 0): ?>
                        <span class="pv-card-tasks">
                            <span class="mini-bar"><span class="mini-fill" style="width:<?= $taskPct ?>%;"></span></span>
                            <?= $cs['done_tasks'] ?>/<?= $totalTasks ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($cs['case_number']): ?>
                        <div class="pv-card-process" style="display:block;text-decoration:none;">🏛️ <?= e($cs['case_number']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($cs['prev_numero_beneficio'])): ?>
                        <div class="pv-card-process" style="display:block;text-decoration:none;">📋 NB: <?= e($cs['prev_numero_beneficio']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($cs['client_senha_gov'])):
                        $sgUid = 'sg' . (int)$cs['id'];
                    ?>
                        <div style="background:rgba(91,45,142,.1);border:1px solid rgba(91,45,142,.3);border-radius:6px;padding:.3rem .45rem;font-size:.65rem;color:#5B2D8E;margin-top:.25rem;display:flex;align-items:center;gap:.35rem;flex-wrap:wrap;" onclick="event.stopPropagation();">
                            <span style="font-weight:700;">🔐 gov.br:</span>
                            <span id="<?= $sgUid ?>" data-senha="<?= e($cs['client_senha_gov']) ?>" data-show="0" style="font-family:monospace;letter-spacing:.04em;">••••••••</span>
                            <button type="button" onclick="event.stopPropagation();pvSenhaGovToggle('<?= $sgUid ?>',this)" style="background:none;border:1px solid rgba(91,45,142,.4);border-radius:4px;cursor:pointer;font-size:.6rem;padding:1px 6px;color:#5B2D8E;">👁</button>
                            <button type="button" onclick="event.stopPropagation();pvSenhaGovCopiar('<?= $sgUid ?>',this)" style="background:none;border:1px solid rgba(91,45,142,.4);border-radius:4px;cursor:pointer;font-size:.6rem;padding:1px 6px;color:#5B2D8E;">📋</button>
                        </div>
                    <?php endif; ?>
                    <?php
                    $caseDocs = isset($docsPendentes[$cs['id']]) ? $docsPendentes[$cs['id']] : array();
                    if (!empty($caseDocs)):
                    ?>
                        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:.3rem .4rem;font-size:.6rem;color:#dc2626;margin-top:.25rem;" onclick="event.stopPropagation();">
                            <?php foreach ($caseDocs as $doc): ?>
                                <div style="display:flex;align-items:flex-start;gap:.2rem;padding:.1rem 0;font-weight:600;line-height:1.3; <?= $doc['status'] === 'recebido' ? 'color:#059669;' : '' ?>">
                                    <?php if ($doc['status'] === 'recebido'): ?>
                                        <span>&#9745;</span> <s><?= e($doc['descricao']) ?></s>
                                    <?php else: ?>
                                        <span>&#9744;</span> <?= e($doc['descricao']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($colKey === 'suspenso'):
                        $motivoSusp = isset($cs['suspensao_motivo']) ? $cs['suspensao_motivo'] : '';
                        $retornoPrev = isset($cs['suspensao_retorno_previsto']) ? $cs['suspensao_retorno_previsto'] : '';
                        $diasSusp = !empty($cs['data_suspensao']) ? (int)((time() - strtotime($cs['data_suspensao'])) / 86400) : 0;
                    ?>
                        <div style="background:#f3e8ff;border:1px solid #d8b4fe;border-radius:6px;padding:.3rem .4rem;font-size:.6rem;color:#5B2D8E;margin-top:.25rem;" onclick="event.stopPropagation();">
                            <div style="font-weight:700;"><?= $motivoSusp ? e($motivoSusp) : 'Suspenso' ?> (<?= $diasSusp ?>d)</div>
                            <?php if ($retornoPrev): $retornoAtrasado = ($retornoPrev < date('Y-m-d')); ?>
                                <div style="color:<?= $retornoAtrasado ? '#dc2626' : '#5B2D8E' ?>;font-weight:<?= $retornoAtrasado ? '700' : '400' ?>;"><?= $retornoAtrasado ? 'Retorno atrasado: ' : 'Retorno: ' ?><?= date('d/m', strtotime($retornoPrev)) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Mover rápido -->
                    <form method="POST" action="<?= module_url('prev', 'api.php') ?>" onclick="event.stopPropagation();">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update_prev_status">
                        <input type="hidden" name="case_id" value="<?= $cs['id'] ?>">
                        <select name="new_prev_status" class="pv-card-move" onchange="handlePrevMove(this)">
                            <option value="">Mover →</option>
                            <?php foreach ($columns as $sk => $sv): ?>
                                <?php if ($sk !== $colKey): ?>
                                    <option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal: Documento Faltante (PREV) -->
<div id="pvDocModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#dc2626;margin-bottom:.5rem;">⚠️ Documento Faltante</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">Descreva qual documento está faltando. Separe com <strong>;</strong> para criar checklist.</p>
        <textarea id="pvDocDesc" rows="3" style="width:100%;padding:.6rem .8rem;font-size:.88rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;resize:vertical;" placeholder="Ex: Laudo médico ; CNIS ; PPP"></textarea>
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="document.getElementById('pvDocModal').style.display='none';_pvPendingForm=null;" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmPvDoc()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#dc2626;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Sinalizar ⚠️</button>
        </div>
    </div>
</div>

<!-- Modal: Suspensão (PREV) -->
<div id="pvSuspModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto;">
        <h3 style="font-size:1rem;font-weight:700;color:#5B2D8E;margin-bottom:.75rem;">⏸️ Suspender Processo PREV</h3>
        <div style="margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Motivo da suspensão *</label>
            <select id="pvSuspMotivo" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                <option value="">— Selecionar —</option>
                <option value="Aguardando documento do cliente">Aguardando documento do cliente</option>
                <option value="Aguardando resultado de perícia">Aguardando resultado de perícia</option>
                <option value="Suspensão judicial">Suspensão judicial</option>
                <option value="Acordo em andamento">Acordo em andamento</option>
                <option value="Solicitação do cliente">Solicitação do cliente</option>
                <option value="Outros">Outros</option>
            </select>
        </div>
        <div style="margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Data de retorno prevista (opcional)</label>
            <input type="date" id="pvSuspRetorno" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;">
            <button onclick="document.getElementById('pvSuspModal').style.display='none';_pvPendingForm=null;" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmPvSusp()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#5B2D8E;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Suspender ⏸️</button>
        </div>
    </div>
</div>

<!-- Modal: Parceria (PREV) -->
<div id="pvParcModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.5rem;">🤝 Selecionar Parceiro</h3>
        <select id="pvParcSelect" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
            <option value="">Selecione o parceiro...</option>
            <?php foreach ($parceirosAtivos as $parc): ?>
                <option value="<?= $parc['id'] ?>"><?= e($parc['nome']) ?><?= $parc['area'] ? ' — ' . e($parc['area']) : '' ?></option>
            <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="document.getElementById('pvParcModal').style.display='none';_pvPendingForm=null;" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmPvParc()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Confirmar →</button>
        </div>
    </div>
</div>

<script>
var _pvPendingForm = null;
var pvCsrf = '<?= generate_csrf_token() ?>';

function handlePrevMove(select) {
    var status = select.value;
    if (!status) return;
    var form = select.closest('form');

    // Cancelado: só admin
    if (status === 'cancelado') {
        <?php if (!has_role('admin')): ?>
        alert('Apenas administradores podem cancelar.');
        select.value = '';
        return;
        <?php endif; ?>
    }

    // Doc faltante (mover para aguardando_docs com checklist)
    if (status === 'aguardando_docs') {
        var card = select.closest('.pv-card');
        var currentStatus = card ? card.closest('.pv-col-body').dataset.status : '';
        if (currentStatus && currentStatus !== 'aguardando_docs') {
            _pvPendingForm = form;
            document.getElementById('pvDocModal').style.display = 'flex';
            document.getElementById('pvDocDesc').focus();
            select.value = '';
            return;
        }
    }

    // Suspenso: modal de motivo
    if (status === 'suspenso') {
        _pvPendingForm = form;
        document.getElementById('pvSuspModal').style.display = 'flex';
        select.value = '';
        return;
    }

    // Parceria: modal parceiro
    if (status === 'parceria') {
        _pvPendingForm = form;
        document.getElementById('pvParcModal').style.display = 'flex';
        select.value = '';
        return;
    }

    form.submit();
}

function confirmPvDoc() {
    var desc = document.getElementById('pvDocDesc').value.trim();
    if (!desc) { document.getElementById('pvDocDesc').style.borderColor = '#ef4444'; return; }
    document.getElementById('pvDocModal').style.display = 'none';
    if (_pvPendingForm) {
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = 'doc_faltante_desc'; input.value = desc;
        _pvPendingForm.appendChild(input);
        _pvPendingForm.submit();
    }
}

function confirmPvSusp() {
    var motivo = document.getElementById('pvSuspMotivo').value;
    if (!motivo) { document.getElementById('pvSuspMotivo').style.borderColor = '#ef4444'; return; }
    document.getElementById('pvSuspModal').style.display = 'none';
    if (_pvPendingForm) {
        var sel = _pvPendingForm.querySelector('select[name="new_prev_status"]');
        if (sel) sel.removeAttribute('name');
        var h1 = document.createElement('input'); h1.type='hidden'; h1.name='new_prev_status'; h1.value='suspenso'; _pvPendingForm.appendChild(h1);
        var h2 = document.createElement('input'); h2.type='hidden'; h2.name='suspensao_motivo'; h2.value=motivo; _pvPendingForm.appendChild(h2);
        var retorno = document.getElementById('pvSuspRetorno').value;
        if (retorno) { var h3 = document.createElement('input'); h3.type='hidden'; h3.name='suspensao_retorno_previsto'; h3.value=retorno; _pvPendingForm.appendChild(h3); }
        _pvPendingForm.submit();
    }
}

function confirmPvParc() {
    var parceiroId = document.getElementById('pvParcSelect').value;
    if (!parceiroId) { document.getElementById('pvParcSelect').style.borderColor = '#ef4444'; return; }
    document.getElementById('pvParcModal').style.display = 'none';
    if (_pvPendingForm) {
        var sel = _pvPendingForm.querySelector('select[name="new_prev_status"]');
        if (sel) sel.removeAttribute('name');
        var h1 = document.createElement('input'); h1.type='hidden'; h1.name='new_prev_status'; h1.value='parceria'; _pvPendingForm.appendChild(h1);
        var h2 = document.createElement('input'); h2.type='hidden'; h2.name='parceiro_id'; h2.value=parceiroId; _pvPendingForm.appendChild(h2);
        _pvPendingForm.submit();
    }
}

// Toggle e copiar senha gov.br do card PREV
function pvSenhaGovToggle(uid, btn) {
    var el = document.getElementById(uid);
    if (!el) return;
    if (el.getAttribute('data-show') === '1') {
        el.textContent = '••••••••';
        el.setAttribute('data-show', '0');
        btn.textContent = '👁';
    } else {
        el.textContent = el.getAttribute('data-senha') || '';
        el.setAttribute('data-show', '1');
        btn.textContent = '🙈';
    }
}
function pvSenhaGovCopiar(uid, btn) {
    var el = document.getElementById(uid);
    if (!el) return;
    var senha = el.getAttribute('data-senha') || '';
    if (!senha) return;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(senha).then(function(){
            var orig = btn.textContent;
            btn.textContent = '✓';
            setTimeout(function(){ btn.textContent = orig; }, 1200);
        });
    }
}

// Drag & drop
(function(){
    var board = document.getElementById('viewPrevKanban');
    if (!board) return;
    var cards = board.querySelectorAll('.pv-card[draggable]');
    var cols = board.querySelectorAll('.pv-col-body');

    cards.forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            e.dataTransfer.setData('text/plain', card.dataset.caseId);
            card.classList.add('dragging');
        });
        card.addEventListener('dragend', function() { card.classList.remove('dragging'); });
    });

    cols.forEach(function(col) {
        col.addEventListener('dragover', function(e) { e.preventDefault(); col.classList.add('drag-over'); });
        col.addEventListener('dragleave', function() { col.classList.remove('drag-over'); });
        col.addEventListener('drop', function(e) {
            e.preventDefault();
            col.classList.remove('drag-over');
            var caseId = e.dataTransfer.getData('text/plain');
            var newStatus = col.dataset.status;
            if (!caseId || !newStatus) return;

            // Submeter via form dinâmico
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?= module_url("prev", "api.php") ?>';
            function addH(n, v) { var i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); }
            addH('<?= CSRF_TOKEN_NAME ?>', pvCsrf);
            addH('action', 'update_prev_status');
            addH('case_id', caseId);
            addH('new_prev_status', newStatus);
            document.body.appendChild(form);

            // Interceptar modais
            if (newStatus === 'suspenso') {
                _pvPendingForm = form;
                document.getElementById('pvSuspModal').style.display = 'flex';
                return;
            }
            if (newStatus === 'parceria') {
                _pvPendingForm = form;
                document.getElementById('pvParcModal').style.display = 'flex';
                return;
            }
            <?php if (!has_role('admin')): ?>
            if (newStatus === 'cancelado') { alert('Apenas administradores podem cancelar.'); return; }
            <?php endif; ?>

            form.submit();
        });
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
