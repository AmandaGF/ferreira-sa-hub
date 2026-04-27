<?php
/**
 * Ferreira & Sá Hub — Operacional (Kanban)
 * Fluxo: Contrato Assinado → Pasta Apta → Em Execução →
 *        Doc Faltante → Aguardando Distribuição → Processo Distribuído
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_view_operacional()) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'Operacional';
$pdo = db();
$userId = current_user_id();
$isColaborador = has_role('colaborador');
$canMove = can_move_operacional();

// Filtros
$filterPriority = isset($_GET['priority']) ? $_GET['priority'] : '';
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$filterSearch = isset($_GET['q']) ? trim($_GET['q']) : '';
$filterMonth = isset($_GET['mes']) ? $_GET['mes'] : '';

// Colunas do board (conforme doc técnico v2)
$columns = array(
    'aguardando_docs'        => array('label' => 'Contrato — Aguardando Docs', 'color' => '#f59e0b', 'icon' => '📄'),
    'em_elaboracao'          => array('label' => 'Pasta Apta',                  'color' => '#059669', 'icon' => '✔️'),
    'em_andamento'           => array('label' => 'Em Execução',                 'color' => '#0ea5e9', 'icon' => '⚙️'),
    'doc_faltante'           => array('label' => 'Doc Faltante',                'color' => '#dc2626', 'icon' => '⚠️'),
    'suspenso'               => array('label' => 'Suspenso',                   'color' => '#5B2D8E', 'icon' => '⏸️'),
    'aguardando_prazo'       => array('label' => 'Aguard. Distribuição',        'color' => '#8b5cf6', 'icon' => '⏳'),
    'distribuido'            => array('label' => 'Distribuído — aguard. despacho','color' => '#15803d', 'icon' => '🏛️'),
    'kanban_prev'            => array('label' => 'Kanban PREV',                  'color' => '#3B4FA0', 'icon' => '🏛️'),
    'parceria_previdenciario'=> array('label' => 'Parceria',                    'color' => '#06b6d4', 'icon' => '🤝'),
    'cancelado'              => array('label' => 'Cancelado',                   'color' => '#6b7280', 'icon' => '❌'),
);

// Construir query
$where = array('1=1');
$params = array();

if ($isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = $userId;
}
if ($filterPriority) {
    $where[] = "cs.priority = ?";
    $params[] = $filterPriority;
}
if ($filterUser && !$isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = (int)$filterUser;
}
if ($filterSearch) {
    $where[] = "(cs.title LIKE ? OR c.name LIKE ? OR cs.case_type LIKE ? OR cs.case_number LIKE ?)";
    $s = "%$filterSearch%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($filterMonth) {
    $where[] = "DATE_FORMAT(cs.created_at, '%Y-%m') = ?";
    $params[] = $filterMonth;
}

$whereStr = implode(' AND ', $where);

$sql = "SELECT cs.*, c.name as client_name, c.phone as client_phone, u.name as responsible_name,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status NOT IN ('concluido','feito')) as pending_tasks,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status IN ('concluido','feito')) as done_tasks,
        (SELECT MAX(a.data_andamento) FROM case_andamentos a
         WHERE a.case_id = cs.id AND a.data_andamento > cs.distribution_date) as andamento_pos_distrib
        FROM cases cs
        LEFT JOIN clients c ON c.id = cs.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE $whereStr AND cs.status NOT IN ('concluido','arquivado') AND IFNULL(cs.kanban_oculto, 0) = 0
        ORDER BY FIELD(cs.priority, 'urgente','alta','normal','baixa'), cs.deadline ASC, cs.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allCases = $stmt->fetchAll();

// Buscar documentos pendentes por caso (lista completa para checklist)
$docsPendentes = array();
try {
    $stmtDocs = $pdo->query("SELECT id, case_id, descricao, status FROM documentos_pendentes WHERE case_id IS NOT NULL ORDER BY solicitado_em ASC");
    foreach ($stmtDocs->fetchAll() as $doc) {
        $cid = (int)$doc['case_id'];
        if (!isset($docsPendentes[$cid])) $docsPendentes[$cid] = array();
        $docsPendentes[$cid][] = $doc;
    }
} catch (Exception $e) { /* tabela pode não existir */ }

// Agrupar por status
// Regra: "distribuido" só aparece no Kanban no mês da distribuição.
// Meses anteriores ficam ocultos do Kanban (mas continuam no banco como "distribuido").
$byStatus = array();
foreach (array_keys($columns) as $s) { $byStatus[$s] = array(); }
$mesAtual = date('Y-m');
foreach ($allCases as $cs) {
    $status = $cs['status'];
    // Distribuídos: aparece enquanto NÃO houver andamento posterior à data de
    // distribuição (fase crítica entre ajuizamento e primeiro despacho/citação).
    // Quando o processo recebe o 1º andamento posterior, sai automaticamente do
    // Kanban (fica só na pasta). Substitui a regra antiga "só no mês da distrib".
    if ($status === 'distribuido') {
        if (!empty($cs['andamento_pos_distrib'])) {
            continue; // já tem despacho — sai da coluna "aguardando despacho"
        }
    }
    // Cancelados: só no mês que cancelou
    if ($status === 'cancelado') {
        $mesRef = date('Y-m', strtotime($cs['updated_at']));
        if ($mesRef !== $mesAtual) {
            continue;
        }
    }
    // Kanban PREV: aparece na coluna PREV do Operacional só no mês de envio
    if (!empty($cs['kanban_prev']) && $cs['kanban_prev'] == 1) {
        $prevMes = isset($cs['prev_mes_envio']) ? (int)$cs['prev_mes_envio'] : 0;
        $prevAno = isset($cs['prev_ano_envio']) ? (int)$cs['prev_ano_envio'] : 0;
        if ($prevMes == (int)date('n') && $prevAno == (int)date('Y')) {
            $status = 'kanban_prev';
        } else {
            continue; // Meses anteriores: não mostra no Operacional
        }
    }
    if (!isset($byStatus[$status])) { $status = 'em_andamento'; }
    $byStatus[$status][] = $cs;
}

// KPIs
$totalAtivos = count($allCases);
$urgentes = 0; $docsFaltantes = count($byStatus['doc_faltante']);
foreach ($allCases as $cs) {
    if ($cs['priority'] === 'urgente') $urgentes++;
}

// Documentos pendentes (para banner de alerta)
$docsPendentes = array();
try {
    $docsPendentes = $pdo->query(
        "SELECT dp.*, c.name as client_name, cs.title as case_title
         FROM documentos_pendentes dp
         LEFT JOIN clients c ON c.id = dp.client_id
         LEFT JOIN cases cs ON cs.id = dp.case_id
         WHERE dp.status = 'pendente'
         ORDER BY dp.solicitado_em DESC"
    )->fetchAll();
} catch (Exception $e) {}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$parceirosAtivos = array();
try { $parceirosAtivos = $pdo->query("SELECT id, nome, area FROM parceiros WHERE ativo = 1 ORDER BY nome")->fetchAll(); } catch (Exception $e) {}
$priorityColors = array('urgente' => '#ef4444', 'alta' => '#f59e0b', 'normal' => '#6366f1', 'baixa' => '#9ca3af');
$priorityLabels = array('urgente' => 'URGENTE', 'alta' => 'Alta', 'normal' => 'Normal', 'baixa' => 'Baixa');

// Últimos cadastrados + últimos distribuídos (pro quadrinho no topo)
$ultimosCadastrados = array();
$ultimosDistribuidos = array();
try {
    $stmtCad = $pdo->query(
        "SELECT cs.id, cs.title, cs.created_at, cs.case_number, c.name AS client_name, u.name AS responsible_name
         FROM cases cs
         LEFT JOIN clients c ON c.id = cs.client_id
         LEFT JOIN users u ON u.id = cs.responsible_user_id
         WHERE cs.status NOT IN ('arquivado','cancelado')
         ORDER BY cs.created_at DESC
         LIMIT 3"
    );
    $ultimosCadastrados = $stmtCad->fetchAll();

    $stmtDist = $pdo->query(
        "SELECT cs.id, cs.title, cs.updated_at, cs.case_number, c.name AS client_name, u.name AS responsible_name
         FROM cases cs
         LEFT JOIN clients c ON c.id = cs.client_id
         LEFT JOIN users u ON u.id = cs.responsible_user_id
         WHERE cs.status = 'distribuido'
         ORDER BY cs.updated_at DESC
         LIMIT 3"
    );
    $ultimosDistribuidos = $stmtDist->fetchAll();
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.op-topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:.75rem; flex-wrap:wrap; gap:.5rem; }
.op-topbar h3 { font-size:.95rem; font-weight:700; color:var(--petrol-900); }
.op-filters { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
.op-filter-select { font-size:.72rem; padding:.3rem .45rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); color:var(--text); cursor:pointer; }

.op-kpis { display:flex; gap:.75rem; margin-bottom:.75rem; flex-wrap:wrap; }
.op-kpi { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.6rem .85rem; display:flex; align-items:center; gap:.5rem; min-width:120px; }
.op-kpi-value { font-size:1.2rem; font-weight:800; color:var(--petrol-900); }
.op-kpi-label { font-size:.62rem; color:var(--text-muted); text-transform:uppercase; }

.op-board { display:flex; gap:.5rem; min-height:450px; overflow-x:auto; padding-bottom:.5rem; scroll-snap-type:x proximity; }
.op-board::-webkit-scrollbar { height:10px; }
.op-board::-webkit-scrollbar-track { background:#f1f5f9; border-radius:5px; }
.op-board::-webkit-scrollbar-thumb { background:var(--petrol-500); border-radius:5px; }
.op-board::-webkit-scrollbar-thumb:hover { background:var(--petrol-900); }
.op-column { display:flex; flex-direction:column; width:240px; min-width:240px; flex-shrink:0; scroll-snap-align:start; }
.op-col-header { padding:.55rem .7rem; border-radius:var(--radius) var(--radius) 0 0; color:#fff; font-weight:700; font-size:.72rem; display:flex; justify-content:space-between; align-items:center; gap:.3rem; }
.op-col-header > :first-child { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.op-col-header .count { background:rgba(255,255,255,.25); padding:.1rem .4rem; border-radius:100px; font-size:.6rem; }
.op-col-body { flex:1; background:var(--bg); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius) var(--radius); padding:.35rem; display:flex; flex-direction:column; gap:.35rem; min-height:80px; overflow-y:auto; max-height:70vh; }
.op-col-body.drag-over { background:rgba(99,102,241,.08); border-color:rgba(99,102,241,.4); }

.op-card { background:var(--bg-card); border-radius:var(--radius); padding:.6rem .7rem; box-shadow:var(--shadow-sm); border-left:4px solid #ccc; cursor:grab; transition:all var(--transition); }
.op-card:hover { box-shadow:var(--shadow-md); transform:translateY(-1px); }
.op-card.dragging { opacity:.4; cursor:grabbing; }
.op-card-name { font-weight:700; font-size:.78rem; color:var(--petrol-900); margin-bottom:.2rem; line-height:1.25; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.op-card-client { font-size:.65rem; color:var(--text-muted); margin-bottom:.2rem; }
.op-card-badges { display:flex; gap:.15rem; flex-wrap:wrap; margin-bottom:.25rem; }
.op-card-badge { font-size:.55rem; font-weight:700; padding:.1rem .3rem; border-radius:4px; color:#fff; text-transform:uppercase; }
.op-card-footer { display:flex; justify-content:space-between; align-items:center; }
.op-card-resp { font-size:.6rem; color:var(--rose-dark); font-weight:600; }
.op-card-tasks { font-size:.6rem; color:var(--text-muted); display:flex; align-items:center; gap:.2rem; }
.op-card-tasks .mini-bar { width:35px; height:3px; background:var(--border); border-radius:3px; overflow:hidden; display:inline-block; }
.op-card-tasks .mini-fill { height:100%; background:var(--success); border-radius:3px; display:block; }
.op-card-deadline { font-size:.55rem; margin-top:.2rem; }
.op-card-deadline.overdue { color:#ef4444; font-weight:700; }
.op-card.prazo-hoje { border-left-color:#dc2626 !important; animation:pulsePrazo 1.5s ease-in-out infinite; }
.op-card.prazo-3d { border-left-color:#f59e0b !important; animation:pulsePrazoSuave 2s ease-in-out infinite; }
@keyframes pulsePrazo { 0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,.4)} 50%{box-shadow:0 0 0 6px rgba(220,38,38,0)} }
@keyframes pulsePrazoSuave { 0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.3)} 50%{box-shadow:0 0 0 4px rgba(245,158,11,0)} }
.op-card-process { font-size:.58rem; color:var(--petrol-500); font-weight:600; margin-top:.2rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%; display:block; }
.op-card-doc-alert { background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:.3rem .4rem; font-size:.6rem; color:#dc2626; margin-top:.25rem; }
.op-doc-item { display:flex; align-items:flex-start; gap:.2rem; padding:.1rem 0; font-weight:600; line-height:1.3; }
.op-doc-item.recebido { color:#059669; text-decoration:none; }
.op-doc-item.recebido s { font-weight:400; }

.op-card-move { margin-top:.3rem; width:100%; font-size:.6rem; padding:.2rem .25rem; border:1px solid var(--border); border-radius:4px; background:var(--bg-card); cursor:pointer; }
.op-empty { text-align:center; padding:1rem .5rem; color:var(--text-muted); font-size:.7rem; }

.page-content { max-width:none !important; padding:.75rem !important; }
@media (max-width: 768px) { .op-column { width:220px; min-width:220px; } }
</style>

<!-- Banner: Documentos Pendentes (colapsável) -->
<?php if (!empty($docsPendentes)):
    // Agrupar por cliente
    $docsByClient = array();
    foreach ($docsPendentes as $dp) {
        $key = $dp['client_name'] ?: 'Caso #' . $dp['case_id'];
        if (!isset($docsByClient[$key])) $docsByClient[$key] = array();
        $docsByClient[$key][] = $dp;
    }
?>
<div style="background:#fef2f2;border:2px solid #fecaca;border-radius:12px;padding:.75rem 1rem;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;" onclick="var el=document.getElementById('docsExpand');el.style.display=el.style.display==='none'?'block':'none';this.querySelector('.chevron').textContent=el.style.display==='none'?'▸':'▾';">
        <div style="display:flex;align-items:center;gap:.5rem;">
            <span style="font-size:1rem;">⚠️</span>
            <strong style="font-size:.82rem;color:#dc2626;"><?= count($docsPendentes) ?> doc(s) faltante(s) em <?= count($docsByClient) ?> cliente(s)</strong>
        </div>
        <span class="chevron" style="font-size:.8rem;color:#dc2626;">▸</span>
    </div>
    <div id="docsExpand" style="display:none;margin-top:.5rem;">
        <?php foreach ($docsByClient as $clientName => $docs): ?>
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
<div class="op-kpis">
    <div class="op-kpi"><span style="font-size:1rem;">📂</span><div><div class="op-kpi-value"><?= $totalAtivos ?></div><div class="op-kpi-label"><?= $isColaborador ? 'Seus casos' : 'Casos ativos' ?></div></div></div>
    <?php if ($urgentes > 0): ?><div class="op-kpi"><span style="font-size:1rem;">🔴</span><div><div class="op-kpi-value"><?= $urgentes ?></div><div class="op-kpi-label">Urgentes</div></div></div><?php endif; ?>
    <?php if ($docsFaltantes > 0): ?><div class="op-kpi"><span style="font-size:1rem;">⚠️</span><div><div class="op-kpi-value"><?= $docsFaltantes ?></div><div class="op-kpi-label">Doc faltante</div></div></div><?php endif; ?>
</div>

<!-- Filtros + Toggle -->
<div class="op-topbar">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <h3 style="margin:0;white-space:nowrap;">Kanban Operacional</h3>
        <div style="display:flex;border:2px solid var(--petrol-900);border-radius:10px;overflow:hidden;flex-shrink:0;">
            <button onclick="toggleOpView('kanban')" id="btnOpKanban" style="padding:7px 18px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:var(--petrol-900);color:#fff;transition:all .2s;">📋 Kanban</button>
            <button onclick="toggleOpView('tabela')" id="btnOpTabela" style="padding:7px 18px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:#fff;color:var(--petrol-900);transition:all .2s;">📊 Tabela</button>
        </div>
        <form method="GET" class="op-filters" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
            <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="🔎 Buscar nome, tipo, nº..." class="op-filter-select" style="min-width:200px;" onkeydown="if(event.key==='Enter')this.form.submit()">
            <input type="month" name="mes" value="<?= e($filterMonth) ?>" class="op-filter-select" style="min-width:120px;" onchange="this.form.submit()">
            <select name="priority" class="op-filter-select" onchange="this.form.submit()">
                <option value="">Prioridade</option>
                <?php foreach ($priorityLabels as $pk => $pl): ?>
                    <option value="<?= $pk ?>" <?= $filterPriority === $pk ? 'selected' : '' ?>><?= $pl ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isColaborador): ?>
            <select name="user" class="op-filter-select" onchange="this.form.submit()">
                <option value="">Responsável</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($filterPriority || $filterUser || $filterSearch || $filterMonth): ?>
                <a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm" style="font-size:.65rem;">✕ Limpar</a>
            <?php endif; ?>
        </form>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-shrink:0;">
        <a href="<?= module_url('operacional', 'caso_novo.php') ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;">+ Novo Processo</a>
        <a href="<?= module_url('planilha', 'importar.php?destino=operacional') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Importar CSV</a>
    </div>
</div>

<!-- Últimos processos (quadrinho informativo) -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem;">
    <!-- Últimos cadastrados -->
    <div style="background:#fff;border:1px solid var(--border);border-left:3px solid #0ea5e9;border-radius:var(--radius-md);padding:.6rem .85rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
            <strong style="font-size:.78rem;color:var(--petrol-900);">🆕 Últimos cadastrados</strong>
            <span style="font-size:.65rem;color:#64748b;">3 mais recentes</span>
        </div>
        <?php if (empty($ultimosCadastrados)): ?>
            <div style="color:#94a3b8;font-size:.75rem;padding:.3rem 0;">Nenhum ainda.</div>
        <?php else: ?>
            <?php foreach ($ultimosCadastrados as $uc):
                $agoCad = time() - strtotime($uc['created_at']);
                if     ($agoCad < 3600)  $agoCadLbl = floor($agoCad/60) . 'min atrás';
                elseif ($agoCad < 86400) $agoCadLbl = floor($agoCad/3600) . 'h atrás';
                elseif ($agoCad < 604800) $agoCadLbl = floor($agoCad/86400) . 'd atrás';
                else                     $agoCadLbl = date('d/m', strtotime($uc['created_at']));
            ?>
                <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$uc['id']) ?>"
                   style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;padding:4px 0;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;font-size:.75rem;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;color:var(--petrol-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($uc['title'] ?: 'Processo #' . $uc['id']) ?></div>
                        <div style="font-size:.66rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= e($uc['client_name'] ?: '—') ?>
                            <?= $uc['responsible_name'] ? ' · ' . e(explode(' ', $uc['responsible_name'])[0]) : '' ?>
                        </div>
                    </div>
                    <span style="font-size:.65rem;color:#0ea5e9;font-weight:600;white-space:nowrap;"><?= $agoCadLbl ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Últimos distribuídos -->
    <div style="background:#fff;border:1px solid var(--border);border-left:3px solid #15803d;border-radius:var(--radius-md);padding:.6rem .85rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
            <strong style="font-size:.78rem;color:var(--petrol-900);">🏛️ Últimos distribuídos</strong>
            <span style="font-size:.65rem;color:#64748b;">3 mais recentes</span>
        </div>
        <?php if (empty($ultimosDistribuidos)): ?>
            <div style="color:#94a3b8;font-size:.75rem;padding:.3rem 0;">Nenhum ainda.</div>
        <?php else: ?>
            <?php foreach ($ultimosDistribuidos as $ud):
                $agoDist = time() - strtotime($ud['updated_at']);
                if     ($agoDist < 3600)  $agoDistLbl = floor($agoDist/60) . 'min atrás';
                elseif ($agoDist < 86400) $agoDistLbl = floor($agoDist/3600) . 'h atrás';
                elseif ($agoDist < 604800) $agoDistLbl = floor($agoDist/86400) . 'd atrás';
                else                      $agoDistLbl = date('d/m', strtotime($ud['updated_at']));
            ?>
                <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$ud['id']) ?>"
                   style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;padding:4px 0;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;font-size:.75rem;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;color:var(--petrol-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= e($ud['title'] ?: 'Processo #' . $ud['id']) ?>
                            <?= $ud['case_number'] ? ' <span style="color:#64748b;font-weight:400;">(' . e(substr($ud['case_number'], 0, 20)) . ')</span>' : '' ?>
                        </div>
                        <div style="font-size:.66rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= e($ud['client_name'] ?: '—') ?>
                            <?= $ud['responsible_name'] ? ' · ' . e(explode(' ', $ud['responsible_name'])[0]) : '' ?>
                        </div>
                    </div>
                    <span style="font-size:.65rem;color:#15803d;font-weight:600;white-space:nowrap;"><?= $agoDistLbl ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
@media (max-width: 700px) {
    div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; }
}
</style>

<!-- Board Kanban -->
<div class="op-board" id="viewOpKanban">
    <?php foreach ($columns as $colKey => $col): ?>
    <div class="op-column">
        <div class="op-col-header" style="background:<?= $col['color'] ?>;">
            <span><?= $col['icon'] ?> <?= $col['label'] ?></span>
            <span class="count"><?= count($byStatus[$colKey]) ?></span>
        </div>
        <div class="op-col-body" data-status="<?= $colKey ?>">
            <?php if (empty($byStatus[$colKey])): ?>
                <div class="op-empty">Nenhum caso</div>
            <?php else: ?>
                <?php foreach ($byStatus[$colKey] as $cs):
                    $totalTasks = $cs['pending_tasks'] + $cs['done_tasks'];
                    $taskPct = $totalTasks > 0 ? round(($cs['done_tasks'] / $totalTasks) * 100) : 0;
                    $isOverdue = $cs['deadline'] && $cs['deadline'] < date('Y-m-d');
                    $pColor = isset($priorityColors[$cs['priority']]) ? $priorityColors[$cs['priority']] : '#9ca3af';
                    $prazoClass = '';
                    if ($cs['deadline']) {
                        $diasPrazo = (int)((strtotime($cs['deadline']) - strtotime(date('Y-m-d'))) / 86400);
                        if ($diasPrazo <= 0) $prazoClass = 'prazo-hoje';
                        elseif ($diasPrazo <= 3) $prazoClass = 'prazo-3d';
                    }
                ?>
                <div class="op-card <?= $prazoClass ?>" draggable="true" data-case-id="<?= $cs['id'] ?>" data-case-type="<?= e($cs['case_type'] ?: '') ?>" data-case-number="<?= e($cs['case_number'] ?: '') ?>" data-court="<?= e($cs['court'] ?: '') ?>" data-client-id="<?= (int)($cs['client_id'] ?? 0) ?>" data-responsible-id="<?= (int)($cs['responsible_user_id'] ?? 0) ?>" style="border-left-color:<?= $pColor ?>;"
                     onclick="if(!event.target.closest('select,form,.op-card-move,button'))window.location='<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>'">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div class="op-card-name" style="flex:1;"><?= e($cs['title'] ?: 'Caso #' . $cs['id']) ?></div>
                        <div style="display:flex;gap:2px;flex-shrink:0;margin-left:4px;">
                            <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>" onclick="event.stopPropagation();" target="_blank" title="Abrir pasta do processo" style="font-size:.85rem;text-decoration:none;">📂</a>
                            <button type="button" onclick="event.stopPropagation();event.preventDefault();arquivarCard(<?= $cs['id'] ?>)" title="Arquivar" style="background:none;border:none;cursor:pointer;font-size:.75rem;padding:0;opacity:.5;line-height:1;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.5">📦</button>
                        </div>
                    </div>
                    <div class="op-card-client">👤 <?= e($cs['client_name'] ?: 'Sem cliente') ?></div>
                    <div class="op-card-badges">
                        <?php if ($cs['case_type'] && $cs['case_type'] !== 'outro'): ?>
                            <span class="op-card-badge" style="background:#173d46;"><?= e($cs['case_type']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($cs['is_incidental']) && $cs['processo_principal_id']): ?>
                            <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['processo_principal_id']) ?>" onclick="event.stopPropagation();" class="op-card-badge" style="background:#6366f1;text-decoration:none;cursor:pointer;" title="Ver processo principal">📎 Incidental</a>
                        <?php endif; ?>
                    </div>
                    <div class="op-card-footer">
                        <span class="op-card-resp"><?= e($cs['responsible_name'] ? explode(' ', $cs['responsible_name'])[0] : '—') ?></span>
                        <?php $diasUpdate = (int)((time() - strtotime($cs['updated_at'])) / 86400); ?>
                        <span style="font-size:.65rem;color:<?= $diasUpdate > 30 ? '#dc2626' : ($diasUpdate > 7 ? '#f59e0b' : 'var(--text-muted)') ?>;"><?= $diasUpdate ?>d</span>
                        <?php if ($totalTasks > 0): ?>
                        <span class="op-card-tasks">
                            <span class="mini-bar"><span class="mini-fill" style="width:<?= $taskPct ?>%;"></span></span>
                            <?= $cs['done_tasks'] ?>/<?= $totalTasks ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($cs['case_number']): ?>
                        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>" onclick="event.stopPropagation();" target="_blank" class="op-card-process" style="text-decoration:none;display:block;" title="Abrir pasta do processo">🏛️ <?= e(format_cnj($cs['case_number'])) ?></a>
                    <?php endif; ?>
                    <?php if ($cs['deadline']): ?>
                        <div class="op-card-deadline <?= $isOverdue ? 'overdue' : '' ?>"><?= $isOverdue ? '⚠️ Vencido ' : '📅 ' ?><?= date('d/m', strtotime($cs['deadline'])) ?></div>
                    <?php endif; ?>
                    <?php
                    $caseDocs = isset($docsPendentes[$cs['id']]) ? $docsPendentes[$cs['id']] : array();
                    if (!empty($caseDocs)):
                    ?>
                        <div class="op-card-doc-alert" onclick="event.stopPropagation();">
                            <?php foreach ($caseDocs as $doc): ?>
                                <div class="op-doc-item <?= $doc['status'] === 'recebido' ? 'recebido' : '' ?>">
                                    <?php if ($doc['status'] === 'recebido'): ?>
                                        <span title="Recebido">&#9745;</span> <s><?= e($doc['descricao']) ?></s>
                                    <?php else: ?>
                                        <span title="Pendente">&#9744;</span> <?= e($doc['descricao']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($cs['status'] === 'suspenso'):
                        $diasSusp = !empty($cs['data_suspensao']) ? (int)((time() - strtotime($cs['data_suspensao'])) / 86400) : 0;
                        $motivoSusp = isset($cs['suspensao_motivo']) ? $cs['suspensao_motivo'] : '';
                        $retornoPrev = isset($cs['suspensao_retorno_previsto']) ? $cs['suspensao_retorno_previsto'] : '';
                        $retornoAtrasado = ($retornoPrev && $retornoPrev < date('Y-m-d'));
                        $procSuspId = isset($cs['suspensao_processo_id']) ? (int)$cs['suspensao_processo_id'] : 0;
                    ?>
                        <div style="background:#f3e8ff;border:1px solid #d8b4fe;border-radius:6px;padding:.3rem .4rem;font-size:.6rem;color:#5B2D8E;margin-top:.25rem;" onclick="event.stopPropagation();">
                            <div style="font-weight:700;"><?= $motivoSusp ? e($motivoSusp) : 'Suspenso' ?> (<?= $diasSusp ?>d)</div>
                            <?php if ($retornoPrev): ?>
                                <div style="color:<?= $retornoAtrasado ? '#dc2626' : '#5B2D8E' ?>;font-weight:<?= $retornoAtrasado ? '700' : '400' ?>;"><?= $retornoAtrasado ? 'Retorno atrasado: ' : 'Retorno: ' ?><?= date('d/m', strtotime($retornoPrev)) ?></div>
                            <?php endif; ?>
                            <?php if ($procSuspId): ?>
                                <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $procSuspId) ?>" style="color:#5B2D8E;font-weight:600;text-decoration:underline;" onclick="event.stopPropagation();">Ver processo vinculado</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Mover rápido -->
                    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" onclick="event.stopPropagation();">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="case_id" value="<?= $cs['id'] ?>">
                        <select name="new_status" class="op-card-move" onchange="handleOpMove(this)">
                            <option value="">Mover →</option>
                            <?php foreach ($columns as $sk => $sv): ?>
                                <?php if ($sk !== $colKey): ?>
                                    <option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <option value="concluido" style="color:#059669;">✅ Concluído</option>
                            <?php if (has_min_role('gestao')): ?>
                            <option disabled>──────────</option>
                            <option value="_merge">🔗 Juntar com outra pasta</option>
                            <?php endif; ?>
                        </select>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Visão Tabela Operacional -->
<div id="viewOpTabela" style="display:none;">
<style>
.tbl-toolbar { display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem;padding:.5rem .75rem;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg); }
.tbl-filter { font-size:.8rem;padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;color:var(--text);min-width:140px; }
.tbl-filter:focus { border-color:var(--rose);outline:none; }
.tbl-count { margin-left:auto;font-size:.78rem;color:var(--text-muted);font-weight:600; }
.tbl-csv { padding:6px 16px;background:var(--success);color:#fff;border:none;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer; }
.tbl-csv:hover { opacity:.9; }
.tbl-wrap { border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm); }
.tbl-grid { border-collapse:separate;border-spacing:0;font-size:.82rem; }
.tbl-grid thead { position:sticky;top:0;z-index:3; }
.tbl-grid th { background:linear-gradient(180deg,var(--petrol-900),var(--petrol-700));color:#fff;padding:11px 14px;text-align:left;font-size:.78rem;font-weight:700;letter-spacing:.2px;cursor:pointer;user-select:none;white-space:nowrap;border-right:1px solid rgba(255,255,255,.12);border-bottom:2px solid rgba(215,171,144,.35); }
.tbl-grid th:hover { background:var(--petrol-500); }
.tbl-grid th:last-child { border-right:none; }
.tbl-grid td { padding:8px 12px;border-bottom:1px solid #eee;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:250px; }
.tbl-grid tbody tr { cursor:pointer;transition:background .15s; }
.tbl-grid tbody tr:nth-child(even) { background:#fafbfc; }
.tbl-grid tbody tr:hover { background:rgba(215,171,144,.15); }
/* Colunas fixas: # + Caso grudam na esquerda ao rolar horizontalmente */
/* Freeze via JS (position:relative + style.left no scroll). Sticky em <td> tem quirks em tabelas HTML. */
.tbl-grid td.sticky-col-1, .tbl-grid th.sticky-col-1 { position:relative;z-index:2;box-sizing:border-box;width:48px;min-width:48px;max-width:48px;will-change:transform;text-align:center; }
.tbl-grid td.sticky-col-2, .tbl-grid th.sticky-col-2 { position:relative;z-index:2;box-sizing:border-box;width:240px;min-width:240px;max-width:240px;will-change:transform;box-shadow:2px 0 4px -2px rgba(0,0,0,.18); }
.tbl-grid td.sticky-col-1, .tbl-grid td.sticky-col-2 { background:#fff !important; }
.tbl-grid thead th.sticky-col-1, .tbl-grid thead th.sticky-col-2 { z-index:4;background:var(--petrol-900) !important;color:#fff !important; }
.tbl-grid tbody tr:nth-child(even) td.sticky-col-1, .tbl-grid tbody tr:nth-child(even) td.sticky-col-2 { background:#fafbfc !important; }
.tbl-grid tbody tr:hover td.sticky-col-1, .tbl-grid tbody tr:hover td.sticky-col-2 { background:#f5ebe0 !important; }
/* Linha divisória fina no lado direito da coluna fixa (sem sombra invasiva) */
.tbl-grid th.sticky-col-2::after, .tbl-grid td.sticky-col-2::after { content:'';position:absolute;top:0;right:0;bottom:0;width:1px;background:rgba(0,0,0,.1);pointer-events:none; }
.tbl-grid .cell-name { font-weight:700;color:var(--petrol-900); }
.tbl-grid .cell-resp { color:var(--rose-dark);font-weight:600; }
.tbl-badge { display:inline-block;padding:3px 10px;border-radius:12px;font-size:.7rem;font-weight:700;color:#fff; }
.tbl-badge-sm { display:inline-block;padding:2px 8px;border-radius:4px;font-size:.68rem;font-weight:700;color:#fff; }
.tbl-grid .cell-proc { font-size:.78rem;color:var(--petrol-500);font-weight:600; }
.tbl-grid td.editable { cursor:text;position:relative; }
.tbl-grid td.editable:hover { background:rgba(215,171,144,.1);outline:1px dashed var(--rose); }
.tbl-grid td.editable:focus-within { background:#fff;outline:2px solid var(--rose); }
.tbl-grid td.editable input, .tbl-grid td.editable select { width:100%;border:none;background:transparent;font:inherit;padding:0;outline:none; }
.tbl-grid td.saved::after { content:'ok';position:absolute;right:4px;top:50%;transform:translateY(-50%);color:var(--success);font-size:.6rem;font-weight:700;animation:fadeout 1.5s forwards; }
@keyframes fadeout { 0%{opacity:1} 70%{opacity:1} 100%{opacity:0} }
.tbl-grid .cell-move select { font-size:.78rem;padding:4px 8px;border:1.5px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;color:var(--text); }
.tbl-grid .cell-move select:hover { border-color:var(--rose); }
.tbl-pag { display:flex;justify-content:center;gap:4px;margin-top:1rem; }
.tbl-pag a { padding:6px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:.78rem;text-decoration:none;font-weight:600;color:var(--text);transition:all .15s; }
.tbl-pag a:hover { background:var(--petrol-100);border-color:var(--petrol-500); }
.tbl-pag a.active { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
/* Linhas coloridas por status */
.tbl-grid tbody tr[data-status="aguardando_docs"] { border-left:4px solid #f59e0b; }
.tbl-grid tbody tr[data-status="em_elaboracao"] { border-left:4px solid #059669; background:rgba(5,150,105,.04) !important; }
.tbl-grid tbody tr[data-status="em_andamento"] { border-left:4px solid #0ea5e9; background:rgba(14,165,233,.04) !important; }
.tbl-grid tbody tr[data-status="doc_faltante"] { border-left:4px solid #dc2626; background:rgba(220,38,38,.06) !important; }
.tbl-grid tbody tr[data-status="suspenso"] { border-left:4px solid #5B2D8E; background:rgba(91,45,142,.06) !important; }
.tbl-grid tbody tr[data-status="aguardando_prazo"] { border-left:4px solid #8b5cf6; }
.tbl-grid tbody tr[data-status="distribuido"] { border-left:4px solid #15803d; background:rgba(21,128,61,.04) !important; }
.tbl-grid tbody tr[data-status="kanban_prev"] { border-left:4px solid #3B4FA0; background:rgba(59,79,160,.06) !important; }
.tbl-grid tbody tr[data-status="parceria_previdenciario"] { border-left:4px solid #06b6d4; }
.tbl-grid tbody tr[data-status="cancelado"] { border-left:4px solid #6b7280; opacity:.7; }
</style>
<?php
$allCasesFlat = array();
foreach ($byStatus as $statusKey => $statusCases) {
    foreach ($statusCases as $cs) { $cs['_status_key'] = $statusKey; $allCasesFlat[] = $cs; }
}

// Filtro por mês (server-side, igual Comercial). Usa created_at do caso.
$opFilterMonth = isset($_GET['op_mes']) ? $_GET['op_mes'] : '';
$opMesesDisponiveis = array();
foreach ($allCasesFlat as $cs) {
    if (!empty($cs['created_at'])) {
        $ym = substr($cs['created_at'], 0, 7);
        if (!in_array($ym, $opMesesDisponiveis, true)) $opMesesDisponiveis[] = $ym;
    }
}
rsort($opMesesDisponiveis); // mais recente primeiro
if ($opFilterMonth) {
    $allCasesFlat = array_values(array_filter($allCasesFlat, function($cs) use ($opFilterMonth) {
        return !empty($cs['created_at']) && substr($cs['created_at'], 0, 7) === $opFilterMonth;
    }));
}
$opMesesBR = array('01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez');

$opPage = max(1, (int)($_GET['op'] ?? 1));
$opPerPage = 25;
$opTotalPages = max(1, ceil(count($allCasesFlat) / $opPerPage));
if ($opPage > $opTotalPages) $opPage = $opTotalPages;
$opOffset = ($opPage - 1) * $opPerPage;
$pageCases = array_slice($allCasesFlat, $opOffset, $opPerPage);
$opTipos = array();
foreach ($allCasesFlat as $cs) { if ($cs['case_type'] && $cs['case_type'] !== 'outro' && !in_array($cs['case_type'], $opTipos)) $opTipos[] = $cs['case_type']; }
sort($opTipos);
?>
<div class="tbl-toolbar">
    <select id="filterOpMes" onchange="filterOpByMes(this.value)" class="tbl-filter" title="Filtrar por mês de cadastro do caso">
        <option value="">📅 Todos os meses</option>
        <?php foreach ($opMesesDisponiveis as $ym):
            list($yy, $mm) = explode('-', $ym);
            $label = ($opMesesBR[$mm] ?? $mm) . '/' . $yy;
        ?>
            <option value="<?= e($ym) ?>" <?= $opFilterMonth === $ym ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="filterOpStatus" onchange="filterOpTable()" class="tbl-filter">
        <option value="">Todos os status</option>
        <?php foreach ($columns as $ck => $cv): ?><option value="<?= $ck ?>"><?= $cv['icon'] ?> <?= $cv['label'] ?></option><?php endforeach; ?>
    </select>
    <select id="filterOpResp" onchange="filterOpTable()" class="tbl-filter">
        <option value="">Todos responsáveis</option>
        <?php foreach ($users as $u): ?><option value="<?= e($u['name']) ?>"><?= e(explode(' ', $u['name'])[0]) ?></option><?php endforeach; ?>
    </select>
    <select id="filterOpType" onchange="filterOpTable()" class="tbl-filter">
        <option value="">Todos os tipos</option>
        <?php foreach ($opTipos as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
    </select>
    <span class="tbl-count"><?= count($allCasesFlat) ?> casos<?= $opFilterMonth ? ' em ' . e(($opMesesBR[substr($opFilterMonth,5,2)] ?? '') . '/' . substr($opFilterMonth,0,4)) : '' ?></span>
    <button onclick="exportTableCSV('opTableBody','operacional')" class="tbl-csv">Exportar CSV</button>
</div>
<div class="tbl-wrap" style="max-height:72vh;overflow:auto;overflow-x:scroll;position:relative;width:100%;">
<table class="tbl-grid" id="opTableBody" style="min-width:1800px;">
<thead><tr>
    <th class="sticky-col-1" onclick="sortTbl('opTableBody',0)" title="Número da linha">#</th>
    <th class="sticky-col-2" onclick="sortTbl('opTableBody',1)" title="Título do caso">📋 Caso</th>
    <th onclick="sortTbl('opTableBody',2)" title="Cliente principal">👤 Cliente</th>
    <th onclick="sortTbl('opTableBody',3)" title="Tipo de ação">⚖️ Tipo de Ação</th>
    <th onclick="sortTbl('opTableBody',4)" title="Advogado responsável">🧑‍💼 Responsável</th>
    <th onclick="sortTbl('opTableBody',5)" title="Status atual do caso">📌 Status</th>
    <th onclick="sortTbl('opTableBody',6)" title="Nível de prioridade">🔥 Prioridade</th>
    <th onclick="sortTbl('opTableBody',7)" title="Número do processo judicial">🔢 Nº Processo</th>
    <th onclick="sortTbl('opTableBody',8)" title="Vara e juízo de tramitação">🏛️ Vara / Juízo</th>
    <th onclick="sortTbl('opTableBody',9)" title="Prazo fatal">⏰ Prazo</th>
    <th onclick="sortTbl('opTableBody',10)" title="Observações gerais">📝 Observações</th>
    <th onclick="sortTbl('opTableBody',11)" title="Data de cadastro no sistema">📅 Cadastro</th>
    <th style="cursor:default;" title="Alterar status">↔️ Mover</th>
</tr></thead>
<tbody>
<?php $n = $opOffset + 1; foreach ($pageCases as $cs):
    $sk = $cs['_status_key']; $ci = $columns[$sk];
    $pColor = isset($priorityColors[$cs['priority']]) ? $priorityColors[$cs['priority']] : '#9ca3af';
    $pLabel = isset($priorityLabels[$cs['priority']]) ? $priorityLabels[$cs['priority']] : $cs['priority'];
    $cid = (int)$cs['id'];
?>
<tr data-status="<?= $sk ?>" data-resp="<?= e($cs['responsible_name'] ?? '') ?>" data-type="<?= e($cs['case_type'] ?? '') ?>" data-case-type="<?= e($cs['case_type'] ?? '') ?>" data-case-id="<?= $cid ?>" data-case-number="<?= e($cs['case_number'] ?? '') ?>" data-court="<?= e($cs['court'] ?? '') ?>" data-client-id="<?= (int)($cs['client_id'] ?? 0) ?>">
    <td class="sticky-col-1" style="text-align:center;color:#999;font-size:.7rem;">
        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cid) ?>" style="color:#999;text-decoration:none;" title="Abrir pasta"><?= $n++ ?></a>
    </td>
    <td class="sticky-col-2 editable" style="font-weight:700;color:var(--petrol-900);min-width:160px;"><input value="<?= e($cs['title'] ?: '') ?>" data-id="<?= $cid ?>" data-field="title" onchange="saveCaseCell(this)"></td>
    <td style="font-size:.78rem;"><?= e($cs['client_name'] ?: '—') ?></td>
    <td class="editable" style="min-width:100px;"><input value="<?= e($cs['case_type'] !== 'outro' ? ($cs['case_type'] ?? '') : '') ?>" data-id="<?= $cid ?>" data-field="case_type" onchange="saveCaseCell(this)"></td>
    <td class="editable" style="min-width:90px;">
        <select data-id="<?= $cid ?>" data-field="responsible_user_id" onchange="saveCaseCell(this)">
            <option value="">—</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$cs['responsible_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option><?php endforeach; ?>
        </select>
    </td>
    <td><span class="tbl-badge" style="background:<?= $ci['color'] ?>;"><?= $ci['icon'] ?> <?= $ci['label'] ?></span></td>
    <td class="editable" style="min-width:80px;">
        <select data-id="<?= $cid ?>" data-field="priority" onchange="saveCaseCell(this)">
            <option value="normal" <?= $cs['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="baixa" <?= $cs['priority'] === 'baixa' ? 'selected' : '' ?>>Baixa</option>
            <option value="alta" <?= $cs['priority'] === 'alta' ? 'selected' : '' ?>>Alta</option>
            <option value="urgente" <?= $cs['priority'] === 'urgente' ? 'selected' : '' ?>>Urgente</option>
        </select>
    </td>
    <td class="editable" style="min-width:140px;"><input value="<?= e($cs['case_number'] ?? '') ?>" data-id="<?= $cid ?>" data-field="case_number" onchange="saveCaseCell(this)" placeholder="0000000-00.0000.0.00.0000"></td>
    <td class="editable" style="min-width:120px;"><input value="<?= e($cs['court'] ?? '') ?>" data-id="<?= $cid ?>" data-field="court" onchange="saveCaseCell(this)" placeholder="Vara / Juízo"></td>
    <td class="editable" style="min-width:90px;"><input type="date" value="<?= e($cs['deadline'] ?? '') ?>" data-id="<?= $cid ?>" data-field="deadline" onchange="saveCaseCell(this)"></td>
    <td class="editable" style="min-width:130px;max-width:200px;"><input value="<?= e($cs['notes'] ?? '') ?>" data-id="<?= $cid ?>" data-field="notes" onchange="saveCaseCell(this)" placeholder="Observações..." title="<?= e($cs['notes'] ?? '') ?>"></td>
    <td style="font-size:.72rem;"><?= date('d/m/Y', strtotime($cs['created_at'])) ?></td>
    <td class="cell-move" onclick="event.stopPropagation();">
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="case_id" value="<?= $cid ?>">
            <select name="new_status" onchange="handleOpMove(this)">
                <option value="">Mover</option>
                <?php foreach ($columns as $csk => $csv): if ($csk !== $sk): ?>
                    <option value="<?= $csk ?>"><?= $csv['icon'] ?> <?= $csv['label'] ?></option>
                <?php endif; endforeach; ?>
                <option value="arquivado" style="color:#6b7280;">📦 Arquivar</option>
            </select>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($pageCases)): ?>
<tr><td colspan="9" style="text-align:center;color:#999;padding:2rem;">Nenhum caso encontrado.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($opTotalPages > 1): ?>
<div class="tbl-pag">
    <?php for ($p = 1; $p <= $opTotalPages; $p++): ?>
        <a href="?op=<?= $p ?><?= $filterPriority ? '&priority=' . e($filterPriority) : '' ?><?= $filterUser ? '&user=' . e($filterUser) : '' ?>" class="<?= $p === $opPage ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
</div>

<!-- Modal: Documento Faltante -->
<!-- Modal: Em Execução — pergunta quem está executando -->
<div id="executanteModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:440px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#0ea5e9;margin-bottom:.5rem;">⚙️ Em Execução — Quem está executando?</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">Selecione o responsável pela execução. Será marcado como responsável do caso.</p>
        <select id="execUserId" style="width:100%;padding:.6rem .8rem;font-size:.9rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;">
            <option value="">— Selecionar responsável —</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="closeExecutanteModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmExecutante()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#0ea5e9;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Confirmar ⚙️</button>
        </div>
    </div>
</div>

<div id="docFaltanteModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#dc2626;margin-bottom:.5rem;">⚠️ Documento Faltante</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">Descreva qual documento está faltando. O CX será notificado para providenciar.</p>
        <p style="font-size:.7rem;color:#0ea5e9;margin-bottom:.5rem;background:#eff6ff;padding:.35rem .5rem;border-radius:6px;">Separe com <strong>;</strong> (ponto e vírgula) para criar um checklist. Ex: <em>Certidão de nascimento ; Comprovante de renda</em></p>
        <textarea id="docFaltanteDesc" rows="3" style="width:100%;padding:.6rem .8rem;font-size:.88rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;resize:vertical;" placeholder="Ex: Certidão de nascimento ; Comprovante de renda ; RG do menor"></textarea>
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="closeDocModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmDocFaltante()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#dc2626;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Sinalizar ⚠️</button>
        </div>
    </div>
</div>

<?php if (has_min_role('gestao')): ?>
<!-- Modal: Juntar Pastas -->
<div id="mergeModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#5B2D8E;margin-bottom:.75rem;">🔗 Juntar com outra pasta</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:.5rem;">O caso selecionado sera <strong>absorvido</strong> pelo caso principal. Todos os dados serao migrados.</p>
        <div id="mergePrincipalInfo" style="background:#f3e8ff;border:1px solid #d8b4fe;border-radius:8px;padding:.5rem .8rem;margin-bottom:.75rem;font-size:.8rem;color:#5B2D8E;font-weight:600;"></div>

        <input type="hidden" id="mergePrincipalId" value="">

        <div style="margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Caso a ser absorvido (vai desaparecer)</label>
            <select id="mergeAbsorvido" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" onchange="mergePreview()">
                <option value="">— Carregando... —</option>
            </select>
        </div>

        <div style="margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Novo titulo (opcional)</label>
            <input type="text" id="mergeTitulo" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Título atualizado após a unificação">
        </div>

        <div id="mergePreviewBox" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.6rem .8rem;margin-bottom:.75rem;font-size:.8rem;color:#1e40af;"></div>

        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.5rem .8rem;margin-bottom:.75rem;font-size:.72rem;color:#dc2626;font-weight:600;">
            Esta acao nao pode ser desfeita. O caso absorvido sera arquivado permanentemente.
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;">
            <button onclick="closeMergeModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmarMerge()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#5B2D8E;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Confirmar unificacao</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Suspensão -->
<div id="suspensoModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto;">
        <h3 style="font-size:1rem;font-weight:700;color:#5B2D8E;margin-bottom:.75rem;">⏸️ Suspender Processo</h3>

        <div style="margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Motivo da suspensão *</label>
            <select id="suspMotivo" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" onchange="toggleSuspVinculo()">
                <option value="">— Selecionar —</option>
                <option value="Aguardando processo prejudicial">Aguardando processo prejudicial</option>
                <option value="Aguardando documento">Aguardando documento</option>
                <option value="Suspensão judicial">Suspensão judicial</option>
                <option value="Acordo em andamento">Acordo em andamento</option>
                <option value="Solicitação do cliente">Solicitação do cliente</option>
                <option value="Outros">Outros</option>
            </select>
        </div>

        <div id="suspVinculoBox" style="display:none;margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Processo prejudicial vinculado</label>
            <select id="suspProcessoId" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                <option value="">— Nenhum —</option>
            </select>
            <span style="font-size:.65rem;color:#6b7280;">Processos do mesmo cliente</span>
        </div>

        <div style="margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Data de retorno prevista (opcional)</label>
            <input type="date" id="suspRetorno" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
        </div>

        <div style="margin-bottom:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Observação interna</label>
            <textarea id="suspObs" rows="2" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;resize:vertical;" placeholder="Motivo detalhado..."></textarea>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;">
            <button onclick="closeSuspModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmSuspenso()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#5B2D8E;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Suspender ⏸️</button>
        </div>
    </div>
</div>

<!-- Modal: Confirmar Distribuição (caso já tem número) -->
<div id="distConfirmModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#15803d;margin-bottom:.75rem;">Confirmar Distribuição</h3>
        <div id="distConfirmBody"></div>
        <div id="distConfirmCorrigir" style="display:none;margin-top:.75rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Corrigir número do processo</label>
            <input type="text" id="distConfirmNumero" class="form-input" style="width:100%;" placeholder="0000000-00.0000.0.00.0000">
        </div>
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="closeDistConfirm()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="submitDistConfirm()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#15803d;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Confirmar Distribuição</button>
        </div>
    </div>
</div>

<!-- Modal: Processo Distribuído / Extrajudicial -->
<div id="processoModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:540px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 id="procModalTitle" style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.5rem;">🏛️ Dados do Processo Distribuído</h3>

        <!-- Toggle: Judicial / Extrajudicial -->
        <div style="display:flex;border:2px solid var(--petrol-900);border-radius:10px;overflow:hidden;margin-bottom:1rem;">
            <button type="button" id="btnJudicial" onclick="toggleProcType('judicial')" style="flex:1;padding:8px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:var(--petrol-900);color:#fff;transition:all .2s;">🏛️ Judicial</button>
            <button type="button" id="btnExtrajudicial" onclick="toggleProcType('extrajudicial')" style="flex:1;padding:8px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:#fff;color:var(--petrol-900);transition:all .2s;">📋 Extrajudicial</button>
        </div>

        <!-- Campos Judiciais -->
        <div id="camposJudicial" style="display:grid;gap:.75rem;">
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Número do processo *</label>
                <input type="text" id="procNumero" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="0000000-00.0000.0.00.0000">
            </div>
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Vara / Juízo *</label>
                <input type="text" id="procVara" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Ex: 1ª Vara de Família de Resende">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Tipo de demanda</label>
                    <input type="text" id="procTipo" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Divórcio, Alimentos...">
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Data da distribuição</label>
                    <input type="date" id="procData" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:.5rem;">
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Comarca</label>
                    <input type="text" id="procComarca" list="listaComarcas" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Digite ou selecione...">
                    <datalist id="listaComarcas">
                        <?php $comarcasRJ = function_exists('comarcas_rj') ? comarcas_rj() : array(); foreach ($comarcasRJ as $cm): ?>
                        <option value="<?= e($cm) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">UF</label>
                    <select id="procComarcaUf" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                        <option value="">—</option>
                        <?php foreach (array('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO') as $uf): ?>
                        <option value="<?= $uf ?>" <?= $uf === 'RJ' ? 'selected' : '' ?>><?= $uf ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Regional</label>
                    <input type="text" id="procRegional" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Ex: Volta Redonda">
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Sistema do Tribunal</label>
                    <select id="procSistema" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                        <option value="">—</option>
                        <option value="PROJUDI">PROJUDI</option>
                        <option value="PJe">PJe</option>
                        <option value="e-SAJ">e-SAJ</option>
                        <option value="EPROC">EPROC</option>
                        <option value="Outro">Outro</option>
                    </select>
                </div>
            </div>
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Parte(s) contrária(s)</label>
                <input type="text" id="procParteContraria" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Nome da parte contrária (réu ou autor)">
            </div>
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Valor da causa (R$)</label>
                <input type="text" id="procValorCausa" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Ex: 10.000,00 (opcional)">
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <input type="checkbox" id="procSegredo" value="1">
                <label for="procSegredo" style="font-size:.82rem;color:#6b7280;cursor:pointer;">Segredo de justiça</label>
            </div>
            <div id="procDicaSugestao" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.5rem .75rem;font-size:.72rem;color:#1e40af;"></div>
        </div>

        <!-- Campos Extrajudiciais -->
        <div id="camposExtrajudicial" style="display:none;grid-template-columns:1fr;gap:.75rem;">
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Tipo de serviço extrajudicial *</label>
                <select id="extraTipo" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                    <option value="">Selecione...</option>
                    <option value="Inventário">Inventário</option>
                    <option value="Divórcio Extrajudicial">Divórcio Extrajudicial</option>
                    <option value="Escritura">Escritura</option>
                    <option value="Usucapião">Usucapião</option>
                    <option value="Consultoria">Consultoria</option>
                    <option value="Contratos">Contratos</option>
                    <option value="Mediação">Mediação</option>
                    <option value="Notificação Extrajudicial">Notificação Extrajudicial</option>
                    <option value="Acordo Extrajudicial">Acordo Extrajudicial</option>
                    <option value="Outro">Outro</option>
                </select>
            </div>
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Descrição do que foi feito *</label>
                <textarea id="extraDescricao" rows="3" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;resize:vertical;" placeholder="Descreva o serviço realizado, cartório, partes envolvidas..."></textarea>
            </div>
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Data de conclusão</label>
                <input type="date" id="extraData" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" value="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <input type="hidden" id="procCategory" value="judicial">

        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="closeProcModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmProcesso()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Salvar →</button>
        </div>
    </div>
</div>

<!-- Modal: Selecionar Parceiro -->
<div id="parceiroModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.5rem;">🤝 Selecionar Parceiro</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">Selecione o advogado parceiro para este caso.</p>
        <select id="parceiroSelect" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
            <option value="">Selecione o parceiro...</option>
            <?php foreach ($parceirosAtivos as $parc): ?>
                <option value="<?= $parc['id'] ?>"><?= e($parc['nome']) ?><?= $parc['area'] ? ' — ' . e($parc['area']) : '' ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (empty($parceirosAtivos)): ?>
        <p style="font-size:.75rem;color:#dc2626;margin-top:.5rem;">Nenhum parceiro cadastrado. <a href="<?= module_url('parceiros') ?>">Cadastrar parceiro</a></p>
        <?php endif; ?>
        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="closeParceiroModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmParceiro()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Confirmar →</button>
        </div>
    </div>
</div>

<!-- Modal: Kanban PREV -->
<div id="prevModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#3B4FA0;margin-bottom:.5rem;">🏛️ Enviar para Kanban PREV</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">Selecione o tipo de benefício previdenciário deste caso.</p>
        <select id="prevTipoBeneficio" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
            <option value="">Selecione o tipo...</option>
            <option value="INSS">INSS</option>
            <option value="BPC">BPC</option>
            <option value="LOAS">LOAS</option>
            <option value="Aposentadoria por Idade">Aposentadoria por Idade</option>
            <option value="Aposentadoria por Invalidez">Aposentadoria por Invalidez</option>
            <option value="Auxílio-Doença">Auxílio-Doença</option>
            <option value="Auxílio-Acidente">Auxílio-Acidente</option>
            <option value="Pensão por Morte">Pensão por Morte</option>
            <option value="Salário-Maternidade">Salário-Maternidade</option>
        </select>
        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="closePrevModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmPrev()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#3B4FA0;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Enviar para PREV 🏛️</button>
        </div>
    </div>
</div>

<script>
var _pendingOpForm = null;
var csrfToken = '<?= generate_csrf_token() ?>';

// Edição inline na tabela operacional
var _opCsrf = '<?= generate_csrf_token() ?>';
function saveCaseCell(el) {
    var id = el.dataset.id;
    var field = el.dataset.field;
    var value = el.value;
    var td = el.closest('td');
    var formData = new FormData();
    formData.append('action', 'inline_edit_case');
    formData.append('case_id', id);
    formData.append('field', field);
    formData.append('value', value);
    formData.append('<?= CSRF_TOKEN_NAME ?>', _opCsrf);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.csrf) _opCsrf = r.csrf;
            if (r.ok && td) { td.classList.add('saved'); setTimeout(function() { td.classList.remove('saved'); }, 1500); }
            else if (r.error) { alert('Erro: ' + r.error); }
        } catch(e) {}
    };
    xhr.send(formData);
}

// ── Distribuição inteligente ──
var _distConfirmData = {};

function abrirDistribuicaoInteligente(card) {
    // Amanda pediu: SEMPRE abrir o modal completo com todos os campos
    // (antes tinha atalho de "confirmar" ou "selecionar de lista" que pulava
    // quase todos os campos. Agora abre tudo, só pré-preenche o que já sabemos).
    if (!card) { abrirModalDistOriginal(null); return; }

    var caseNumber = card.dataset.caseNumber || '';
    var court = card.dataset.court || '';
    var caseType = card.dataset.caseType || '';
    var clientId = card.dataset.clientId || '0';
    var caseId = card.dataset.caseId || '0';

    // Abre o modal completo imediatamente (pré-preenche com o que já tem no card)
    abrirModalDistOriginal(card);
    if (caseNumber) document.getElementById('procNumero').value = caseNumber;
    if (court) document.getElementById('procVara').value = court;

    // Carrega TODOS os dados atuais do case pra popular o modal — evita que
    // Amanda confirme o modal em branco e sobrescreva campos já preenchidos.
    if (caseId && caseId !== '0') {
        var xhrCase = new XMLHttpRequest();
        xhrCase.open('POST', '<?= module_url("operacional", "api.php") ?>');
        xhrCase.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhrCase.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhrCase.onload = function() {
            try {
                var r = JSON.parse(xhrCase.responseText);
                if (r.csrf) _opCsrf = r.csrf;
                if (!r.ok || !r.case) return;
                var c = r.case;
                // Pré-popula cada campo com o valor atual do case (se houver)
                var map = [
                    ['procNumero',    c.case_number],
                    ['procVara',      c.court],
                    ['procTipo',      c.case_type],
                    ['procData',      c.distribution_date],
                    ['procComarca',   c.comarca],
                    ['procComarcaUf', c.comarca_uf],
                    ['procRegional',  c.regional],
                    ['procSistema',   c.sistema_tribunal],
                    ['procCategory',  c.category]
                ];
                map.forEach(function(p) {
                    var el = document.getElementById(p[0]);
                    if (el && p[1]) el.value = p[1];
                });
                // Checkbox de segredo de justiça
                var segEl = document.getElementById('procSegredo');
                if (segEl) segEl.checked = (parseInt(c.segredo_justica, 10) === 1);
                // Se veio com extrajudicial, ajusta os campos visíveis
                if (c.category === 'extrajudicial' && typeof toggleProcType === 'function') {
                    toggleProcType('extrajudicial');
                }
            } catch(e) {}
        };
        xhrCase.send('action=buscar_case_dados&case_id=' + caseId + '&<?= CSRF_TOKEN_NAME ?>=' + _opCsrf);
    }

    // Se cliente tem outros processos cadastrados, busca o mais recente pra
    // pré-preencher Comarca/UF/Sistema/Regional como SUGESTÃO (só onde ainda
    // estiver vazio após a pré-carga do case atual — não atropela valor existente).
    if (clientId && clientId !== '0') {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.csrf) _opCsrf = r.csrf;
                var casos = (r.casos || []).filter(function(c){ return c.case_number && c.case_number.length > 5; });
                if (casos.length === 0) return;

                // Usa o MAIS RECENTE como sugestão — dados semelhantes costumam repetir
                var ref = casos[0];
                var campos = [
                    ['procComarca', ref.comarca],
                    ['procComarcaUf', ref.comarca_uf],
                    ['procSistema', ref.sistema_tribunal],
                    ['procRegional', ref.regional],
                ];
                // Só preenche se o campo estiver vazio (não atropela o que user já digitou)
                campos.forEach(function(p) {
                    var el = document.getElementById(p[0]);
                    if (el && !el.value && p[1]) el.value = p[1];
                });
                // Vara só pré-preenche se vazia (senão mantém o que veio do próprio case)
                var varaEl = document.getElementById('procVara');
                if (varaEl && !varaEl.value && ref.court) varaEl.value = ref.court;

                // Mostra dica com link pra copiar CNJ se quiser reutilizar
                var dica = document.getElementById('procDicaSugestao');
                if (dica) {
                    var linkCnjs = casos.slice(0, 3).map(function(c) {
                        return '<a href="#" onclick="document.getElementById(\'procNumero\').value=\'' + esc(c.case_number) + '\';return false;" style="color:#1e40af;text-decoration:underline;font-family:monospace;">' + esc(c.case_number) + '</a>';
                    }).join(' · ');
                    dica.innerHTML = '💡 Dados de Comarca/Sistema pré-preenchidos do processo mais recente deste cliente. '
                                   + 'Edite o que for diferente.<br>'
                                   + '<strong>CNJs existentes:</strong> ' + linkCnjs;
                    dica.style.display = 'block';
                }
            } catch(e) {}
        };
        xhr.send('action=buscar_casos_cliente&case_id=' + caseId + '&<?= CSRF_TOKEN_NAME ?>=' + _opCsrf);
    }
}

function abrirModalDistOriginal(card) {
    if (card && card.dataset.caseType) {
        document.getElementById('procTipo').value = card.dataset.caseType;
    }
    toggleProcType('judicial');
    document.getElementById('processoModal').style.display = 'flex';
    document.getElementById('procNumero').focus();
}

function mostrarCorrigir() {
    var el = document.getElementById('distConfirmCorrigir');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    if (el.style.display === 'block') {
        document.getElementById('distConfirmNumero').value = _distConfirmData.caseNumber || '';
        document.getElementById('distConfirmNumero').focus();
    }
}

function closeDistConfirm() {
    document.getElementById('distConfirmModal').style.display = 'none';
    _pendingOpForm = null;
}

function submitDistConfirm() {
    if (!_pendingOpForm) return;

    var numero = '';
    var court = '';

    if (_distConfirmData.modo === 'selecionar') {
        var sel = document.querySelector('input[name="distSelNum"]:checked');
        if (!sel) { alert('Selecione um processo.'); return; }
        if (sel.value === '_novo') {
            var novoNum = document.getElementById('distConfirmNumero').value.trim();
            if (!novoNum) { document.getElementById('distConfirmNumero').style.borderColor = '#ef4444'; return; }
            numero = novoNum;
        } else {
            numero = sel.value;
            court = sel.dataset.court || '';
        }
    } else {
        // Modo confirmar (já tinha número)
        var corrigirEl = document.getElementById('distConfirmCorrigir');
        if (corrigirEl && corrigirEl.style.display !== 'none') {
            numero = document.getElementById('distConfirmNumero').value.trim();
            if (!numero) { document.getElementById('distConfirmNumero').style.borderColor = '#ef4444'; return; }
        } else {
            numero = _distConfirmData.caseNumber;
            court = _distConfirmData.court || '';
        }
    }

    document.getElementById('distConfirmModal').style.display = 'none';

    // Criar form NOVO com CSRF fresco (evita token expirado após AJAX)
    var caseIdForm = _pendingOpForm ? _pendingOpForm.querySelector('input[name="case_id"]') : null;
    var caseIdVal = caseIdForm ? caseIdForm.value : (_distConfirmData.caseId || '');

    var newForm = document.createElement('form');
    newForm.method = 'POST';
    newForm.action = '<?= module_url("operacional", "api.php") ?>';

    function addH(name, value) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = name; inp.value = value;
        newForm.appendChild(inp);
    }

    addH('<?= CSRF_TOKEN_NAME ?>', _opCsrf);
    addH('action', 'update_status');
    addH('case_id', caseIdVal);
    // Pegar comarca/UF/sistema do radio selecionado (se veio de lista)
    var comarca = '', uf = '', sistema = '', regional = '';
    var selRadio = document.querySelector('input[name="distSelNum"]:checked');
    if (selRadio && selRadio.value !== '_novo') {
        comarca = selRadio.dataset.comarca || '';
        uf = selRadio.dataset.uf || '';
        sistema = selRadio.dataset.sistema || '';
        regional = selRadio.dataset.regional || '';
        if (!court) court = selRadio.dataset.court || '';
    }

    addH('new_status', 'distribuido');
    addH('proc_numero', numero);
    addH('proc_vara', court);
    addH('proc_data', '<?= date("Y-m-d") ?>');
    if (comarca) addH('proc_comarca', comarca);
    if (uf) addH('proc_comarca_uf', uf);
    if (sistema) addH('proc_sistema', sistema);
    if (regional) addH('proc_regional', regional);

    document.body.appendChild(newForm);
    newForm.submit();
}

function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function arquivarCard(caseId) {
    if (!confirm('Ocultar este processo do Kanban?\nO processo continua inalterado, só sai desta visualização.')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= module_url("operacional", "api.php") ?>';
    var f1 = document.createElement('input'); f1.type='hidden'; f1.name='csrf_token'; f1.value=csrfToken; form.appendChild(f1);
    var f2 = document.createElement('input'); f2.type='hidden'; f2.name='action'; f2.value='ocultar_kanban'; form.appendChild(f2);
    var f3 = document.createElement('input'); f3.type='hidden'; f3.name='case_id'; f3.value=caseId; form.appendChild(f3);
    document.body.appendChild(form);
    form.submit();
}

function handleOpMove(select) {
    var status = select.value;
    if (!status) return;
    var form = select.closest('form');

    if (status === 'doc_faltante') {
        _pendingOpForm = form;
        document.getElementById('docFaltanteModal').style.display = 'flex';
        document.getElementById('docFaltanteDesc').focus();
        select.value = '';
        return;
    }

    if (status === 'parceria_previdenciario') {
        _pendingOpForm = form;
        document.getElementById('parceiroModal').style.display = 'flex';
        select.value = '';
        return;
    }

    if (status === 'kanban_prev') {
        _pendingOpForm = form;
        document.getElementById('prevModal').style.display = 'flex';
        select.value = '';
        return;
    }

    if (status === '_merge') {
        var card = select.closest('.op-card') || select.closest('tr');
        var caseId = card ? card.dataset.caseId : '';
        var caseName = card ? card.querySelector('.op-card-name').textContent.trim() : '';
        abrirMergeModal(caseId, caseName);
        select.value = '';
        return;
    }

    if (status === 'suspenso') {
        _pendingOpForm = form;
        // Carregar processos do mesmo cliente para vínculo
        var card = select.closest('.op-card') || select.closest('tr');
        var caseId = card ? card.dataset.caseId : '';
        if (caseId) carregarProcessosSusp(caseId);
        document.getElementById('suspensoModal').style.display = 'flex';
        document.getElementById('suspMotivo').focus();
        select.value = '';
        return;
    }

    if (status === 'distribuido') {
        _pendingOpForm = form;
        var card = select.closest('.op-card') || select.closest('tr');
        select.value = '';
        abrirDistribuicaoInteligente(card);
        return;
    }

    if (status === 'em_andamento') {
        _pendingOpForm = form;
        _pendingExecCard = null; _pendingExecCol = null;
        // Pré-seleciona responsável atual do caso, se houver
        var card = select.closest('.op-card') || select.closest('tr');
        var respAtual = card ? (card.dataset.responsibleId || '') : '';
        var execSel = document.getElementById('execUserId');
        if (execSel) execSel.value = respAtual || '';
        document.getElementById('executanteModal').style.display = 'flex';
        if (execSel) execSel.focus();
        select.value = '';
        return;
    }

    form.submit();
}

// Em Execução — perguntar quem está executando
var _pendingExecCard = null;
var _pendingExecCol = null;
var _pendingExecCaseId = null;

function closeExecutanteModal() {
    document.getElementById('executanteModal').style.display = 'none';
    _pendingOpForm = null;
    _pendingExecCard = null;
    _pendingExecCol = null;
    _pendingExecCaseId = null;
}

function confirmExecutante() {
    var sel = document.getElementById('execUserId');
    var uid = sel ? sel.value : '';
    if (!uid) { if (sel) sel.style.borderColor = '#ef4444'; return; }
    document.getElementById('executanteModal').style.display = 'none';

    // Caminho 1: veio do dropdown — usa form existente
    if (_pendingOpForm) {
        var statusSel = _pendingOpForm.querySelector('select[name="new_status"]');
        if (statusSel) statusSel.removeAttribute('name');
        var iSt = document.createElement('input');
        iSt.type = 'hidden'; iSt.name = 'new_status'; iSt.value = 'em_andamento';
        _pendingOpForm.appendChild(iSt);
        var iU = document.createElement('input');
        iU.type = 'hidden'; iU.name = 'responsible_user_id'; iU.value = uid;
        _pendingOpForm.appendChild(iU);
        _pendingOpForm.submit();
        return;
    }

    // Caminho 2: veio do drag-drop — XHR direto (segue padrão dos outros drops)
    if (!_pendingExecCaseId || !_pendingExecCard || !_pendingExecCol) return;
    var caseId = _pendingExecCaseId;
    var card = _pendingExecCard;
    var col = _pendingExecCol;

    var fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('case_id', caseId);
    fd.append('new_status', 'em_andamento');
    fd.append('responsible_user_id', uid);
    fd.append('csrf_token', csrfToken);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.error) { showToast(resp.error, 'error'); location.reload(); }
                else {
                    showToast('Caso movido pra Em Execução!');
                    var empty = col.querySelector('.op-empty');
                    if (empty) empty.remove();
                    col.appendChild(card);
                    card.dataset.responsibleId = uid;
                }
            } catch(ex) { location.reload(); }
        } else { location.reload(); }
        _pendingExecCard = null; _pendingExecCol = null; _pendingExecCaseId = null;
    };
    xhr.send(fd);
}

// Doc Faltante
function closeDocModal() {
    document.getElementById('docFaltanteModal').style.display = 'none';
    _pendingOpForm = null;
}

function confirmDocFaltante() {
    var desc = document.getElementById('docFaltanteDesc').value.trim();
    if (!desc) { document.getElementById('docFaltanteDesc').style.borderColor = '#ef4444'; return; }
    document.getElementById('docFaltanteModal').style.display = 'none';

    if (_pendingOpForm) {
        // Remover o select para evitar conflito de nomes
        var sel = _pendingOpForm.querySelector('select[name="new_status"]');
        if (sel) sel.removeAttribute('name');
        // Adicionar campos
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = 'doc_faltante_desc'; input.value = desc;
        _pendingOpForm.appendChild(input);
        var statusInput = document.createElement('input');
        statusInput.type = 'hidden'; statusInput.name = 'new_status'; statusInput.value = 'doc_faltante';
        _pendingOpForm.appendChild(statusInput);
        _pendingOpForm.submit();
    }
}

// Merge de Pastas
function abrirMergeModal(caseId, caseTitle) {
    document.getElementById('mergePrincipalId').value = caseId;
    document.getElementById('mergePrincipalInfo').textContent = 'Principal (permanece): ' + caseTitle;
    document.getElementById('mergeTitulo').value = caseTitle;
    document.getElementById('mergePreviewBox').style.display = 'none';
    document.getElementById('mergeModal').style.display = 'flex';

    var select = document.getElementById('mergeAbsorvido');
    select.innerHTML = '<option value="">— Carregando... —</option>';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            select.innerHTML = '<option value="">— Selecionar caso —</option>';
            var casos = r.casos || [];
            for (var i = 0; i < casos.length; i++) {
                var c = casos[i];
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.title + (c.case_number ? ' — ' + c.case_number : '') + ' [' + (c.status || '') + ']';
                opt.dataset.title = c.title;
                select.appendChild(opt);
            }
            if (select.options.length <= 1) {
                select.innerHTML = '<option value="">Nenhum outro caso deste cliente</option>';
            }
        } catch(e) { select.innerHTML = '<option value="">Erro ao carregar</option>'; }
    };
    xhr.send('action=buscar_casos_cliente&case_id=' + caseId + '&<?= CSRF_TOKEN_NAME ?>=<?= generate_csrf_token() ?>');
}

function closeMergeModal() {
    document.getElementById('mergeModal').style.display = 'none';
}

function mergePreview() {
    var sel = document.getElementById('mergeAbsorvido');
    var box = document.getElementById('mergePreviewBox');
    if (!sel.value) { box.style.display = 'none'; return; }
    var opt = sel.options[sel.selectedIndex];
    var absorvido = opt.dataset.title || opt.textContent;
    var principal = document.getElementById('mergeTitulo').value || 'Caso principal';
    box.innerHTML = '<strong>Juntar:</strong> [' + absorvido + '] → [' + principal + ']';
    box.style.display = 'block';
}

function confirmarMerge() {
    var absorvido = document.getElementById('mergeAbsorvido').value;
    if (!absorvido) { document.getElementById('mergeAbsorvido').style.borderColor = '#ef4444'; return; }
    if (!confirm('Tem certeza? Esta acao NAO pode ser desfeita. O caso selecionado sera absorvido e arquivado.')) return;

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= module_url("operacional", "api.php") ?>';

    function addField(name, value) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = name; inp.value = value;
        form.appendChild(inp);
    }

    addField('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    addField('action', 'merge_cases');
    addField('case_principal', document.getElementById('mergePrincipalId').value);
    addField('case_absorvido', absorvido);
    addField('novo_titulo', document.getElementById('mergeTitulo').value);

    document.body.appendChild(form);
    form.submit();
}

// Suspensão
function closeSuspModal() {
    document.getElementById('suspensoModal').style.display = 'none';
    _pendingOpForm = null;
}

function toggleSuspVinculo() {
    var motivo = document.getElementById('suspMotivo').value;
    document.getElementById('suspVinculoBox').style.display = (motivo === 'Aguardando processo prejudicial') ? 'block' : 'none';
}

function carregarProcessosSusp(caseId) {
    var select = document.getElementById('suspProcessoId');
    select.innerHTML = '<option value="">— Carregando... —</option>';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            select.innerHTML = '<option value="">— Nenhum —</option>';
            if (r.casos && r.casos.length) {
                for (var i = 0; i < r.casos.length; i++) {
                    var c = r.casos[i];
                    var opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.title + (c.case_number ? ' — ' + c.case_number : '');
                    select.appendChild(opt);
                }
            }
        } catch(e) { select.innerHTML = '<option value="">— Erro —</option>'; }
    };
    xhr.send('action=buscar_casos_cliente&case_id=' + caseId + '&<?= CSRF_TOKEN_NAME ?>=<?= generate_csrf_token() ?>');
}

function confirmSuspenso() {
    var motivo = document.getElementById('suspMotivo').value;
    if (!motivo) { document.getElementById('suspMotivo').style.borderColor = '#ef4444'; return; }

    document.getElementById('suspensoModal').style.display = 'none';

    if (_pendingOpForm) {
        var sel = _pendingOpForm.querySelector('select[name="new_status"]');
        if (sel) sel.removeAttribute('name');

        function addHidden(name, value) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = name; inp.value = value;
            _pendingOpForm.appendChild(inp);
        }

        addHidden('new_status', 'suspenso');
        addHidden('suspensao_motivo', motivo);
        addHidden('suspensao_processo_id', document.getElementById('suspProcessoId').value || '');
        addHidden('suspensao_retorno_previsto', document.getElementById('suspRetorno').value || '');
        addHidden('suspensao_observacao', document.getElementById('suspObs').value || '');
        _pendingOpForm.submit();
    }
}

// Processo Distribuído
function closeProcModal() {
    document.getElementById('processoModal').style.display = 'none';
    _pendingOpForm = null;
}

// Parceiro
function closeParceiroModal() {
    document.getElementById('parceiroModal').style.display = 'none';
    _pendingOpForm = null;
}

function confirmParceiro() {
    var parceiroId = document.getElementById('parceiroSelect').value;
    if (!parceiroId) { document.getElementById('parceiroSelect').style.borderColor = '#ef4444'; return; }
    document.getElementById('parceiroModal').style.display = 'none';

    if (_pendingOpForm) {
        var sel = _pendingOpForm.querySelector('select[name="new_status"]');
        if (sel) sel.removeAttribute('name');
        var statusInput = document.createElement('input');
        statusInput.type = 'hidden'; statusInput.name = 'new_status'; statusInput.value = 'parceria_previdenciario';
        _pendingOpForm.appendChild(statusInput);
        var parcInput = document.createElement('input');
        parcInput.type = 'hidden'; parcInput.name = 'parceiro_id'; parcInput.value = parceiroId;
        _pendingOpForm.appendChild(parcInput);
        _pendingOpForm.submit();
    }
}

// Kanban PREV
function closePrevModal() {
    document.getElementById('prevModal').style.display = 'none';
    _pendingOpForm = null;
}

function confirmPrev() {
    var tipo = document.getElementById('prevTipoBeneficio').value;
    if (!tipo) { document.getElementById('prevTipoBeneficio').style.borderColor = '#ef4444'; return; }
    document.getElementById('prevModal').style.display = 'none';

    if (_pendingOpForm) {
        var sel = _pendingOpForm.querySelector('select[name="new_status"]');
        if (sel) sel.removeAttribute('name');
        var statusInput = document.createElement('input');
        statusInput.type = 'hidden'; statusInput.name = 'new_status'; statusInput.value = 'kanban_prev';
        _pendingOpForm.appendChild(statusInput);
        var tipoInput = document.createElement('input');
        tipoInput.type = 'hidden'; tipoInput.name = 'prev_tipo_beneficio'; tipoInput.value = tipo;
        _pendingOpForm.appendChild(tipoInput);
        _pendingOpForm.submit();
    }
}

// Toggle Judicial / Extrajudicial
function toggleProcType(type) {
    var judicial = document.getElementById('camposJudicial');
    var extra = document.getElementById('camposExtrajudicial');
    var btnJ = document.getElementById('btnJudicial');
    var btnE = document.getElementById('btnExtrajudicial');
    var title = document.getElementById('procModalTitle');
    document.getElementById('procCategory').value = type;
    if (type === 'extrajudicial') {
        judicial.style.display = 'none';
        extra.style.display = 'grid';
        btnJ.style.background = '#fff'; btnJ.style.color = 'var(--petrol-900)';
        btnE.style.background = 'var(--petrol-900)'; btnE.style.color = '#fff';
        title.innerHTML = '📋 Serviço Extrajudicial';
    } else {
        judicial.style.display = 'grid';
        extra.style.display = 'none';
        btnE.style.background = '#fff'; btnE.style.color = 'var(--petrol-900)';
        btnJ.style.background = 'var(--petrol-900)'; btnJ.style.color = '#fff';
        title.innerHTML = '🏛️ Dados do Processo Distribuído';
    }
}

function confirmProcesso() {
    var category = document.getElementById('procCategory').value;

    if (category === 'extrajudicial') {
        var tipo = document.getElementById('extraTipo').value;
        var desc = document.getElementById('extraDescricao').value.trim();
        if (!tipo) { document.getElementById('extraTipo').style.borderColor = '#ef4444'; return; }
        if (!desc) { document.getElementById('extraDescricao').style.borderColor = '#ef4444'; return; }
        document.getElementById('processoModal').style.display = 'none';

        if (_pendingOpForm) {
            var sel = _pendingOpForm.querySelector('select[name="new_status"]');
            if (sel) sel.removeAttribute('name');
            var fields = {
                'proc_tipo': tipo,
                'proc_data': document.getElementById('extraData').value,
                'proc_numero': '',
                'proc_vara': desc,
                'proc_category': 'extrajudicial'
            };
            for (var k in fields) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = k; input.value = fields[k];
                _pendingOpForm.appendChild(input);
            }
            var statusInput = document.createElement('input');
            statusInput.type = 'hidden'; statusInput.name = 'new_status'; statusInput.value = 'distribuido';
            _pendingOpForm.appendChild(statusInput);
            _pendingOpForm.submit();
        }
    } else {
        var numero = document.getElementById('procNumero').value.trim();
        var vara = document.getElementById('procVara').value.trim();
        if (!numero || !vara) {
            if (!numero) document.getElementById('procNumero').style.borderColor = '#ef4444';
            if (!vara) document.getElementById('procVara').style.borderColor = '#ef4444';
            return;
        }
        document.getElementById('processoModal').style.display = 'none';

        if (_pendingOpForm) {
            var sel = _pendingOpForm.querySelector('select[name="new_status"]');
            if (sel) sel.removeAttribute('name');
            var fields = {
                'proc_numero': numero,
                'proc_vara': vara,
                'proc_tipo': document.getElementById('procTipo').value,
                'proc_data': document.getElementById('procData').value,
                'proc_comarca': document.getElementById('procComarca').value,
                'proc_comarca_uf': document.getElementById('procComarcaUf').value,
                'proc_regional': document.getElementById('procRegional').value,
                'proc_sistema': document.getElementById('procSistema').value,
                'proc_segredo': document.getElementById('procSegredo').checked ? '1' : '0',
                'proc_parte_contraria': (document.getElementById('procParteContraria')||{}).value || '',
                'proc_valor_causa': (document.getElementById('procValorCausa')||{}).value || '',
                'proc_category': 'judicial'
            };
            for (var k in fields) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = k; input.value = fields[k];
                _pendingOpForm.appendChild(input);
            }
            var statusInput = document.createElement('input');
            statusInput.type = 'hidden'; statusInput.name = 'new_status'; statusInput.value = 'distribuido';
            _pendingOpForm.appendChild(statusInput);
            _pendingOpForm.submit();
        }
    }
}

// Drag and Drop
(function() {
    var dragCard = null;

    document.querySelectorAll('.op-card[draggable]').forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            dragCard = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.caseId);
        });
        card.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            document.querySelectorAll('.op-col-body').forEach(function(col) { col.classList.remove('drag-over'); });
            dragCard = null;
        });
    });

    document.querySelectorAll('.op-col-body').forEach(function(col) {
        col.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
        col.addEventListener('dragleave', function(e) { if (!this.contains(e.relatedTarget)) this.classList.remove('drag-over'); });
        col.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            var caseId = e.dataTransfer.getData('text/plain');
            var newStatus = this.dataset.status;
            if (!dragCard || !caseId || !newStatus) return;

            if (newStatus === 'doc_faltante') {
                var form = dragCard.querySelector('form');
                _pendingOpForm = form;
                document.getElementById('docFaltanteModal').style.display = 'flex';
                document.getElementById('docFaltanteDesc').focus();
                return;
            }

            if (newStatus === 'suspenso') {
                var form = dragCard.querySelector('form');
                _pendingOpForm = form;
                carregarProcessosSusp(caseId);
                document.getElementById('suspensoModal').style.display = 'flex';
                document.getElementById('suspMotivo').focus();
                return;
            }

            if (newStatus === 'parceria_previdenciario') {
                var form = dragCard.querySelector('form');
                _pendingOpForm = form;
                document.getElementById('parceiroModal').style.display = 'flex';
                return;
            }

            if (newStatus === 'distribuido') {
                var form = dragCard.querySelector('form');
                _pendingOpForm = form;
                abrirDistribuicaoInteligente(dragCard);
                return;
            }

            if (newStatus === 'em_andamento') {
                _pendingOpForm = null;
                _pendingExecCard = dragCard;
                _pendingExecCol = col;
                _pendingExecCaseId = caseId;
                var respAtual = dragCard.dataset.responsibleId || '';
                var execSel = document.getElementById('execUserId');
                if (execSel) execSel.value = respAtual || '';
                document.getElementById('executanteModal').style.display = 'flex';
                if (execSel) execSel.focus();
                return;
            }

            // Mover via AJAX
            var formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('case_id', caseId);
            formData.append('new_status', newStatus);
            formData.append('csrf_token', csrfToken);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.error) { showToast(resp.error, 'error'); location.reload(); }
                        else { showToast('Caso movido!'); var empty = col.querySelector('.op-empty'); if (empty) empty.remove(); col.appendChild(dragCard); }
                    } catch(ex) { location.reload(); }
                } else { location.reload(); }
            };
            xhr.send(formData);
        });
    });
})();
</script>

<script>
// Toggle Kanban / Tabela
function toggleOpView(view) {
    var k = document.getElementById('viewOpKanban');
    var t = document.getElementById('viewOpTabela');
    var bk = document.getElementById('btnOpKanban');
    var bt = document.getElementById('btnOpTabela');
    if (view === 'tabela') {
        k.style.display = 'none'; t.style.display = 'block';
        bk.style.background = 'var(--bg-card)'; bk.style.color = 'var(--text)';
        bt.style.background = 'var(--petrol-900)'; bt.style.color = '#fff';
    } else {
        k.style.display = 'grid'; t.style.display = 'none';
        bt.style.background = 'var(--bg-card)'; bt.style.color = 'var(--text)';
        bk.style.background = 'var(--petrol-900)'; bk.style.color = '#fff';
    }
    try { localStorage.setItem('operacional_view', view); } catch(e) {}
}
try { var saved = localStorage.getItem('operacional_view'); if (saved === 'tabela') toggleOpView('tabela'); } catch(e) {}

// Filtros tabela operacional
function filterOpTable() {
    var status = document.getElementById('filterOpStatus').value;
    var resp = document.getElementById('filterOpResp').value;
    var tipo = document.getElementById('filterOpType').value;
    var rows = document.querySelectorAll('#opTableBody tbody tr');
    rows.forEach(function(row) {
        if (!row.dataset.status) return;
        var show = true;
        if (status && row.dataset.status !== status) show = false;
        if (resp && row.dataset.resp !== resp) show = false;
        if (tipo && row.dataset.type !== tipo) show = false;
        row.style.display = show ? '' : 'none';
    });
}

// Filtro de mês server-side — preserva view tabela ativa após reload
function filterOpByMes(ym) {
    var params = new URLSearchParams(window.location.search);
    if (ym) params.set('op_mes', ym); else params.delete('op_mes');
    params.delete('op'); // reset paginação
    try { localStorage.setItem('operacional_view', 'tabela'); } catch(e) {}
    window.location.search = params.toString();
}

// Ordenar e exportar (compartilhados)
var _sortDirs = {};
function sortTbl(tableId, colIdx) {
    var table = document.getElementById(tableId);
    var tbody = table.querySelector('tbody');
    var selector = tableId === 'opTableBody' ? 'tr[data-status]' : 'tr[data-stage]';
    var rows = Array.from(tbody.querySelectorAll(selector));
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

// Freeze colunas # e Caso via JS (mesma receita da Planilha Comercial).
// position:sticky em <td> não é confiável; transform é ignorado em table-cell no Chrome.
// style.left em position:relative sempre funciona.
(function(){
    function initFreeze(){
        var wrap = document.querySelector('#viewOpTabela .tbl-wrap');
        if (!wrap) { console.warn('[op-freeze] #viewOpTabela .tbl-wrap não encontrado'); return; }
        var cells = wrap.querySelectorAll('.sticky-col-1, .sticky-col-2');
        if (!cells.length) { console.warn('[op-freeze] sem células sticky-col'); return; }
        function sync(){
            var x = wrap.scrollLeft;
            for (var i = 0; i < cells.length; i++) cells[i].style.left = x + 'px';
        }
        wrap.addEventListener('scroll', sync, { passive: true });
        // Fallback: se o scroll horizontal estiver no body em vez do wrapper
        window.addEventListener('scroll', function(){
            var bodyX = window.scrollX || document.documentElement.scrollLeft || 0;
            if (bodyX > 0 && wrap.scrollLeft === 0) {
                for (var i = 0; i < cells.length; i++) cells[i].style.left = bodyX + 'px';
            }
        }, { passive: true });
        sync();
        console.info('[op-freeze] ativo —', cells.length, 'células | wrapper:', wrap.clientWidth + 'px | table:', wrap.scrollWidth + 'px | overflow?', wrap.scrollWidth > wrap.clientWidth);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initFreeze);
    else initFreeze();
})();

function exportTableCSV(tableId, name) {
    var table = document.getElementById(tableId);
    var csv = [];
    table.querySelectorAll('tr').forEach(function(row) {
        if (row.style.display === 'none') return;
        var cols = [];
        var lastIdx = row.cells.length - 1;
        row.querySelectorAll('th, td').forEach(function(cell, i) {
            if (i === lastIdx) return;
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
<?php if (!empty($_SESSION['efeito_distribuicao'])): unset($_SESSION['efeito_distribuicao']); ?>
<script>
// Efeito de comemoração ao distribuir processo
setTimeout(function() {
    // Confetti
    if (typeof window._gamConfetti === 'function') {
        window._gamConfetti();
    } else {
        // Fallback: confetti simples
        var canvas = document.createElement('canvas');
        canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999;';
        document.body.appendChild(canvas);
        canvas.width = window.innerWidth; canvas.height = window.innerHeight;
        var ctx = canvas.getContext('2d');
        var particles = [];
        var cores = ['#e67e22','#059669','#3b82f6','#dc2626','#B87333','#6366f1','#f59e0b','#052228'];
        for (var i = 0; i < 150; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: Math.random() * canvas.height - canvas.height,
                r: Math.random() * 6 + 3,
                c: cores[Math.floor(Math.random() * cores.length)],
                vx: (Math.random() - 0.5) * 4,
                vy: Math.random() * 3 + 2,
                rot: Math.random() * 360
            });
        }
        function animar() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            var vivos = false;
            particles.forEach(function(p) {
                if (p.y < canvas.height + 20) {
                    vivos = true;
                    p.x += p.vx; p.y += p.vy; p.vy += 0.05; p.rot += 3;
                    ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot * Math.PI / 180);
                    ctx.fillStyle = p.c; ctx.fillRect(-p.r/2, -p.r/2, p.r, p.r * 0.6);
                    ctx.restore();
                }
            });
            if (vivos) requestAnimationFrame(animar);
            else canvas.remove();
        }
        animar();
    }

    // Som de aplausos
    try {
        var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        // Simular aplausos com ruído branco
        var dur = 2.5;
        var bufferSize = audioCtx.sampleRate * dur;
        var buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate);
        var data = buffer.getChannelData(0);
        for (var i = 0; i < bufferSize; i++) {
            var env = Math.sin(Math.PI * i / bufferSize) * 0.3;
            data[i] = (Math.random() * 2 - 1) * env;
        }
        var source = audioCtx.createBufferSource();
        source.buffer = buffer;
        var filter = audioCtx.createBiquadFilter();
        filter.type = 'bandpass'; filter.frequency.value = 3000; filter.Q.value = 0.5;
        source.connect(filter); filter.connect(audioCtx.destination);
        source.start();
    } catch(e) {}
}, 500);
</script>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
