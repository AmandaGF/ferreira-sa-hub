<?php
/**
 * Ferreira & Sá Hub — Cobrança de Honorários
 * Painel principal com 4 abas: Visão Geral, Fila de Cobrança, Histórico, Configurações
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access('cobranca_honorarios')) { redirect(url('modules/dashboard/')); }

$pdo = db();
$userRole = current_user_role();
$isAdmin = ($userRole === 'admin');
$abaAtiva = $_GET['aba'] ?? 'geral';
$ML = array('','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez');

// ─── KPIs ───
$totalAberto = 0; $emNotificacao = 0; $emJudicial = 0; $entraramMes = 0; $recuperadosMes = 0;
try {
    $totalAberto = (float)$pdo->query("SELECT IFNULL(SUM(valor_total - valor_pago),0) FROM honorarios_cobranca WHERE status NOT IN ('pago','cancelado')")->fetchColumn();
    $emNotificacao = (int)$pdo->query("SELECT COUNT(*) FROM honorarios_cobranca WHERE status IN ('notificado_1','notificado_2','notificado_extrajudicial')")->fetchColumn();
    $emJudicial = (int)$pdo->query("SELECT COUNT(*) FROM honorarios_cobranca WHERE status = 'judicial'")->fetchColumn();
    $mesAtual = date('Y-m');
    $entraramMes = (int)$pdo->query("SELECT COUNT(*) FROM honorarios_cobranca WHERE DATE_FORMAT(created_at,'%Y-%m') = '$mesAtual'")->fetchColumn();
    $recuperadosMes = (float)$pdo->query("SELECT IFNULL(SUM(hh.valor_pago),0) FROM honorarios_cobranca_historico hh WHERE hh.etapa IN ('pagamento_parcial','pagamento_total') AND DATE_FORMAT(hh.created_at,'%Y-%m') = '$mesAtual'")->fetchColumn();
} catch (Exception $e) {}

// ─── Gráfico 6 meses ───
$grafLabels = array(); $grafRecuperado = array(); $grafAberto = array();
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $grafLabels[] = $ML[(int)date('n', strtotime("-$i months"))];
    try {
        $grafRecuperado[] = (float)$pdo->query("SELECT IFNULL(SUM(valor_pago),0) FROM honorarios_cobranca_historico WHERE etapa IN ('pagamento_parcial','pagamento_total') AND DATE_FORMAT(created_at,'%Y-%m') = '$m'")->fetchColumn();
        $grafAberto[] = (float)$pdo->query("SELECT IFNULL(SUM(valor_total - valor_pago),0) FROM honorarios_cobranca WHERE status NOT IN ('pago','cancelado') AND DATE_FORMAT(created_at,'%Y-%m') <= '$m'")->fetchColumn();
    } catch (Exception $e) { $grafRecuperado[] = 0; $grafAberto[] = 0; }
}

// ─── Fila de Cobrança (Kanban) ───
$filaCobranca = array();
try {
    $filaCobranca = $pdo->query(
        "SELECT hc.*, cl.name as client_name, cl.phone as client_phone, cs.title as case_title,
                u.name as responsavel_nome,
                DATEDIFF(CURDATE(), hc.vencimento) as dias_atraso,
                (SELECT MAX(hh2.created_at) FROM honorarios_cobranca_historico hh2 WHERE hh2.cobranca_id = hc.id) as ultima_acao
         FROM honorarios_cobranca hc
         LEFT JOIN clients cl ON cl.id = hc.client_id
         LEFT JOIN cases cs ON cs.id = hc.case_id
         LEFT JOIN users u ON u.id = hc.responsavel_cobranca
         WHERE hc.status NOT IN ('pago','cancelado')
         ORDER BY hc.status ASC, dias_atraso DESC"
    )->fetchAll();
} catch (Exception $e) {}

// Agrupar por status para Kanban
$colunas = array(
    'atrasado' => array('label' => 'Atrasado', 'icon' => '⚠️', 'color' => '#dc2626', 'items' => array()),
    'notificado_1' => array('label' => 'Notif. 1', 'icon' => '📱', 'color' => '#d97706', 'items' => array()),
    'notificado_2' => array('label' => 'Notif. 2', 'icon' => '📱', 'color' => '#f59e0b', 'items' => array()),
    'notificado_extrajudicial' => array('label' => 'Extrajudicial', 'icon' => '📄', 'color' => '#8b5cf6', 'items' => array()),
    'judicial' => array('label' => 'Judicial', 'icon' => '⚖️', 'color' => '#1e40af', 'items' => array()),
);
foreach ($filaCobranca as $item) {
    $st = $item['status'];
    if ($st === 'em_dia' || $st === 'atrasado') $st = 'atrasado';
    if (isset($colunas[$st])) {
        $colunas[$st]['items'][] = $item;
    }
}

// ─── Histórico ───
$filtroStatus = $_GET['status'] ?? '';
$filtroResp = (int)($_GET['responsavel'] ?? 0);
$filtroPeriodo = $_GET['periodo'] ?? '';
$historicoWhere = "1=1";
$historicoParams = array();
if ($filtroStatus) { $historicoWhere .= " AND hc.status = ?"; $historicoParams[] = $filtroStatus; }
if ($filtroResp) { $historicoWhere .= " AND hc.responsavel_cobranca = ?"; $historicoParams[] = $filtroResp; }
if ($filtroPeriodo) {
    $datas = explode(' a ', $filtroPeriodo);
    if (count($datas) === 2) {
        $historicoWhere .= " AND hc.created_at BETWEEN ? AND ?";
        $historicoParams[] = $datas[0] . ' 00:00:00';
        $historicoParams[] = $datas[1] . ' 23:59:59';
    }
}
$historico = array();
try {
    $stmtH = $pdo->prepare(
        "SELECT hc.*, cl.name as client_name, cl.phone as client_phone, u.name as responsavel_nome,
                DATEDIFF(CURDATE(), hc.vencimento) as dias_atraso
         FROM honorarios_cobranca hc
         LEFT JOIN clients cl ON cl.id = hc.client_id
         LEFT JOIN users u ON u.id = hc.responsavel_cobranca
         WHERE $historicoWhere
         ORDER BY hc.updated_at DESC LIMIT 100"
    );
    $stmtH->execute($historicoParams);
    $historico = $stmtH->fetchAll();
} catch (Exception $e) {}

// ─── Configurações ───
$config = array('dias_para_cobranca' => 90, 'prazo_notificacao_1' => 7, 'prazo_notificacao_2' => 15, 'prazo_extrajudicial' => 10, 'responsavel_padrao_id' => null, 'msg_notificacao_1' => '', 'msg_notificacao_2' => '');
try {
    $cfgRow = $pdo->query("SELECT * FROM honorarios_config ORDER BY id LIMIT 1")->fetch();
    if ($cfgRow) $config = array_merge($config, $cfgRow);
} catch (Exception $e) {}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$pageTitle = 'Cobrança de Honorários';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ch-tabs { display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:1.25rem; }
.ch-tab { padding:.6rem 1.2rem;font-size:.82rem;font-weight:700;color:var(--text-muted);cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s;text-decoration:none; }
.ch-tab:hover { color:var(--petrol-900); }
.ch-tab.active { color:#B87333;border-bottom-color:#B87333; }
.ch-panel { display:none; }
.ch-panel.active { display:block; }
.ch-kpi { display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem;margin-bottom:1.25rem; }
.ch-kpi-card { background:var(--bg-card);border-radius:var(--radius-lg);border:1px solid var(--border);padding:.85rem 1rem;display:flex;align-items:center;gap:.6rem; }
.ch-kpi-icon { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0; }
.ch-kpi-val { font-size:1.25rem;font-weight:800;line-height:1; }
.ch-kpi-label { font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;margin-top:.1rem; }
/* Kanban */
.ch-kanban { display:flex;gap:.6rem;margin-bottom:1rem;overflow-x:auto;padding-bottom:.5rem;scroll-snap-type:x proximity; }
.ch-kanban::-webkit-scrollbar { height:10px; }
.ch-kanban::-webkit-scrollbar-track { background:#f1f5f9;border-radius:5px; }
.ch-kanban::-webkit-scrollbar-thumb { background:var(--petrol-500);border-radius:5px; }
.ch-kanban::-webkit-scrollbar-thumb:hover { background:var(--petrol-900); }
.ch-col { background:var(--bg-card);border-radius:var(--radius-lg);border:1px solid var(--border);min-height:300px;width:260px;min-width:260px;flex-shrink:0;scroll-snap-align:start; }
.ch-col-header { padding:.6rem .8rem;border-bottom:1px solid var(--border);font-size:.78rem;font-weight:700;display:flex;align-items:center;gap:.3rem; }
.ch-col-body { padding:.5rem; }
.ch-card { background:#fff;border:1px solid var(--border);border-radius:8px;padding:.6rem .7rem;margin-bottom:.5rem;cursor:pointer;transition:box-shadow .2s; }
.ch-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.1); }
.ch-card-name { font-weight:700;font-size:.82rem;color:var(--petrol-900); }
.ch-card-tipo { font-size:.68rem;color:var(--text-muted); }
.ch-card-valor { font-size:.9rem;font-weight:800;color:#dc2626;margin:.2rem 0; }
.ch-card-info { font-size:.65rem;color:var(--text-muted);display:flex;justify-content:space-between;align-items:center; }
.ch-card-btn { display:inline-block;padding:3px 10px;border-radius:6px;font-size:.68rem;font-weight:700;color:#fff;cursor:pointer;border:none;margin-top:.3rem; }
/* Histórico */
.ch-timeline { position:relative;padding-left:20px; }
.ch-timeline::before { content:'';position:absolute;left:6px;top:0;bottom:0;width:2px;background:var(--border); }
.ch-tl-item { position:relative;margin-bottom:.8rem;padding-left:16px; }
.ch-tl-item::before { content:'';position:absolute;left:-17px;top:6px;width:10px;height:10px;border-radius:50%;background:#B87333;border:2px solid #fff; }
/* Modal */
.ch-modal { display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center; }
.ch-modal-box { background:#fff;border-radius:12px;padding:1.5rem;max-width:550px;width:95%;box-shadow:0 20px 40px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto; }
@media (max-width:1200px) { .ch-kpi { grid-template-columns:repeat(3,1fr); } }
@media (max-width:768px) { .ch-col { width:240px;min-width:240px; } .ch-kpi { grid-template-columns:repeat(2,1fr); } }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem;">
    <h2 style="font-size:1.1rem;font-weight:800;color:var(--petrol-900);">⚠️ Cobrança de Honorários</h2>
    <div style="display:flex;gap:.4rem;">
        <form method="POST" action="<?= module_url('cobranca_honorarios', 'api.php') ?>" style="margin:0;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="importar_asaas">
            <input type="hidden" name="apenas_overdue" value="1">
            <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;" data-confirm="Importar todos os inadimplentes (vencidos) do Asaas para o Kanban de Cobrança? Cobranças já importadas serão atualizadas, não duplicadas.">📥 Importar do Asaas</button>
        </form>
        <button onclick="document.getElementById('modalNovaCobranca').style.display='flex';" class="btn btn-primary btn-sm" style="background:#B87333;">+ Marcar Inadimplência</button>
    </div>
</div>

<!-- Abas -->
<div class="ch-tabs">
    <a href="?aba=geral" class="ch-tab <?= $abaAtiva === 'geral' ? 'active' : '' ?>">📊 Visão Geral</a>
    <a href="?aba=fila" class="ch-tab <?= $abaAtiva === 'fila' ? 'active' : '' ?>">📋 Fila de Cobrança</a>
    <a href="?aba=historico" class="ch-tab <?= $abaAtiva === 'historico' ? 'active' : '' ?>">📜 Histórico</a>
    <?php if ($isAdmin): ?>
    <a href="?aba=config" class="ch-tab <?= $abaAtiva === 'config' ? 'active' : '' ?>">⚙️ Configurações</a>
    <?php endif; ?>
</div>

<!-- ═══ ABA 1: VISÃO GERAL ═══ -->
<div class="ch-panel <?= $abaAtiva === 'geral' ? 'active' : '' ?>">
    <div class="ch-kpi">
        <div class="ch-kpi-card">
            <div class="ch-kpi-icon" style="background:rgba(220,38,38,.12);">💰</div>
            <div><div class="ch-kpi-val" style="color:#dc2626;">R$ <?= number_format($totalAberto, 2, ',', '.') ?></div><div class="ch-kpi-label">Total em aberto</div></div>
        </div>
        <div class="ch-kpi-card">
            <div class="ch-kpi-icon" style="background:rgba(217,119,6,.12);">📋</div>
            <div><div class="ch-kpi-val"><?= $emNotificacao ?></div><div class="ch-kpi-label">Em notificação</div></div>
        </div>
        <div class="ch-kpi-card">
            <div class="ch-kpi-icon" style="background:rgba(30,64,175,.12);">⚖️</div>
            <div><div class="ch-kpi-val"><?= $emJudicial ?></div><div class="ch-kpi-label">Cobrança judicial</div></div>
        </div>
        <div class="ch-kpi-card">
            <div class="ch-kpi-icon" style="background:rgba(249,115,22,.12);">⚠️</div>
            <div><div class="ch-kpi-val"><?= $entraramMes ?></div><div class="ch-kpi-label">Entraram este mês</div></div>
        </div>
        <div class="ch-kpi-card">
            <div class="ch-kpi-icon" style="background:rgba(5,150,105,.12);">✅</div>
            <div><div class="ch-kpi-val" style="color:#059669;">R$ <?= number_format($recuperadosMes, 2, ',', '.') ?></div><div class="ch-kpi-label">Recuperados este mês</div></div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;">
            <h4 style="font-size:.85rem;font-weight:700;margin-bottom:.75rem;">📊 Recuperado × Em Aberto (6 meses)</h4>
            <canvas id="chartCobranca" style="max-height:220px;"></canvas>
        </div>
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;">
            <h4 style="font-size:.85rem;font-weight:700;margin-bottom:.75rem;">🔥 Maiores Devedores</h4>
            <?php
            $topDevedores = array();
            try {
                $topDevedores = $pdo->query("SELECT hc.client_id, cl.name, SUM(hc.valor_total - hc.valor_pago) as saldo, MAX(DATEDIFF(CURDATE(), hc.vencimento)) as max_atraso FROM honorarios_cobranca hc LEFT JOIN clients cl ON cl.id = hc.client_id WHERE hc.status NOT IN ('pago','cancelado') GROUP BY hc.client_id ORDER BY saldo DESC LIMIT 8")->fetchAll();
            } catch (Exception $e) {}
            if (empty($topDevedores)): ?>
                <p style="text-align:center;color:var(--text-muted);padding:2rem;font-size:.85rem;">Nenhum devedor registrado</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                <thead><tr><th style="text-align:left;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);padding:.3rem .4rem;border-bottom:1px solid var(--border);">Cliente</th><th style="text-align:right;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);padding:.3rem .4rem;border-bottom:1px solid var(--border);">Saldo</th><th style="text-align:right;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);padding:.3rem .4rem;border-bottom:1px solid var(--border);">Atraso</th></tr></thead>
                <tbody>
                <?php foreach ($topDevedores as $dev): ?>
                <tr>
                    <td style="padding:.35rem .4rem;font-weight:600;"><?= e($dev['name'] ?: '—') ?></td>
                    <td style="padding:.35rem .4rem;text-align:right;font-weight:700;color:#dc2626;">R$ <?= number_format($dev['saldo'], 2, ',', '.') ?></td>
                    <td style="padding:.35rem .4rem;text-align:right;font-weight:700;color:#d97706;"><?= $dev['max_atraso'] ?>d</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══ ABA 2: FILA DE COBRANÇA (Kanban) ═══ -->
<div class="ch-panel <?= $abaAtiva === 'fila' ? 'active' : '' ?>">
    <div class="ch-kanban">
        <?php foreach ($colunas as $colKey => $col): ?>
        <div class="ch-col">
            <div class="ch-col-header" style="border-top:3px solid <?= $col['color'] ?>;">
                <?= $col['icon'] ?> <?= $col['label'] ?>
                <span style="margin-left:auto;background:<?= $col['color'] ?>;color:#fff;padding:1px 7px;border-radius:9px;font-size:.65rem;"><?= count($col['items']) ?></span>
            </div>
            <div class="ch-col-body">
                <?php if (empty($col['items'])): ?>
                    <p style="text-align:center;color:var(--text-muted);font-size:.72rem;padding:1.5rem .5rem;">Nenhum</p>
                <?php endif; ?>
                <?php foreach ($col['items'] as $item):
                    $diasAtraso = max(0, (int)$item['dias_atraso']);
                    $saldo = $item['valor_total'] - $item['valor_pago'];
                ?>
                <div class="ch-card" onclick="abrirDetalhe(<?= $item['id'] ?>)">
                    <div class="ch-card-name"><?= e($item['client_name'] ?: 'Sem nome') ?></div>
                    <div class="ch-card-tipo"><?= e($item['tipo_debito']) ?></div>
                    <div class="ch-card-valor">R$ <?= number_format($saldo, 2, ',', '.') ?></div>
                    <div class="ch-card-info">
                        <span><?= $diasAtraso ?>d atraso</span>
                        <?php if ($item['case_title']): ?><span title="<?= e($item['case_title']) ?>">⚖️</span><?php endif; ?>
                        <?php if ($item['responsavel_nome']): ?><span title="<?= e($item['responsavel_nome']) ?>">👤 <?= e(mb_substr($item['responsavel_nome'], 0, 10)) ?></span><?php endif; ?>
                    </div>
                    <?php if ($item['ultima_acao']): ?>
                    <div style="font-size:.6rem;color:var(--text-muted);margin-top:.2rem;">Última ação: <?= date('d/m/Y', strtotime($item['ultima_acao'])) ?></div>
                    <?php endif; ?>

                    <?php
                    // Botão de ação por status
                    $nextAction = '';
                    $nextLabel = '';
                    $btnColor = $col['color'];
                    if ($colKey === 'atrasado') { $nextAction = 'notificar_1'; $nextLabel = 'Notificar →'; }
                    elseif ($colKey === 'notificado_1') { $nextAction = 'notificar_2'; $nextLabel = 'Notificar 2 →'; }
                    elseif ($colKey === 'notificado_2') { $nextAction = 'notificar_extrajudicial'; $nextLabel = 'Extrajudicial →'; }
                    elseif ($colKey === 'notificado_extrajudicial') { $nextAction = 'judicial'; $nextLabel = '→ Judicial'; }
                    ?>
                    <?php if ($nextAction): ?>
                    <button class="ch-card-btn" style="background:<?= $btnColor ?>;" onclick="event.stopPropagation();avancarEtapa(<?= $item['id'] ?>,'<?= $nextAction ?>')">
                        <?= $nextLabel ?>
                    </button>
                    <?php endif; ?>
                    <button class="ch-card-btn" style="background:#059669;" onclick="event.stopPropagation();registrarPagamento(<?= $item['id'] ?>,<?= $saldo ?>)">
                        💰 Pagar
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══ ABA 3: HISTÓRICO ═══ -->
<div class="ch-panel <?= $abaAtiva === 'historico' ? 'active' : '' ?>">
    <!-- Filtros -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:.8rem;margin-bottom:1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;">
        <form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="aba" value="historico">
            <div>
                <label style="font-size:.68rem;font-weight:700;display:block;margin-bottom:.15rem;">Status</label>
                <select name="status" class="form-select" style="font-size:.78rem;padding:.35rem .5rem;">
                    <option value="">Todos</option>
                    <?php foreach (array('em_dia'=>'Em dia','atrasado'=>'Atrasado','notificado_1'=>'Notif. 1','notificado_2'=>'Notif. 2','notificado_extrajudicial'=>'Extrajudicial','judicial'=>'Judicial','pago'=>'Pago','cancelado'=>'Cancelado') as $sk => $sl): ?>
                    <option value="<?= $sk ?>" <?= $filtroStatus === $sk ? 'selected' : '' ?>><?= $sl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:.68rem;font-weight:700;display:block;margin-bottom:.15rem;">Responsável</label>
                <select name="responsavel" class="form-select" style="font-size:.78rem;padding:.35rem .5rem;">
                    <option value="">Todos</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filtroResp == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;">Filtrar</button>
            <a href="?aba=historico" class="btn btn-outline btn-sm" style="font-size:.72rem;">Limpar</a>
        </form>
        <div style="margin-left:auto;">
            <a href="<?= module_url('cobranca_honorarios', 'api.php?action=exportar_excel') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">📥 Exportar Excel</a>
        </div>
    </div>

    <?php if (empty($historico)): ?>
        <p style="text-align:center;color:var(--text-muted);padding:2rem;font-size:.85rem;">Nenhum registro de cobrança encontrado.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:.8rem;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
        <thead><tr style="background:rgba(0,0,0,.02);">
            <th style="text-align:left;padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);">Cliente</th>
            <th style="text-align:left;padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);">Tipo</th>
            <th style="text-align:right;padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);">Valor</th>
            <th style="text-align:right;padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);">Pago</th>
            <th style="text-align:right;padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);">Saldo</th>
            <th style="text-align:center;padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);">Status</th>
            <th style="text-align:center;padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);">Atraso</th>
            <th style="text-align:left;padding:.5rem .6rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);">Resp.</th>
            <th style="padding:.5rem .6rem;border-bottom:1px solid var(--border);"></th>
        </tr></thead>
        <tbody>
        <?php
        $statusLabels = array('em_dia'=>array('#6366f1','Em dia'),'atrasado'=>array('#dc2626','Atrasado'),'notificado_1'=>array('#d97706','Notif. 1'),'notificado_2'=>array('#f59e0b','Notif. 2'),'notificado_extrajudicial'=>array('#8b5cf6','Extrajudicial'),'judicial'=>array('#1e40af','Judicial'),'pago'=>array('#059669','Pago'),'cancelado'=>array('#6b7280','Cancelado'));
        foreach ($historico as $h):
            $saldo = $h['valor_total'] - $h['valor_pago'];
            $stl = $statusLabels[$h['status']] ?? array('#888', ucfirst($h['status']));
        ?>
        <tr style="border-bottom:1px solid rgba(0,0,0,.04);">
            <td style="padding:.45rem .6rem;font-weight:600;"><?= e($h['client_name'] ?: '—') ?></td>
            <td style="padding:.45rem .6rem;font-size:.75rem;"><?= e($h['tipo_debito']) ?></td>
            <td style="padding:.45rem .6rem;text-align:right;font-weight:600;">R$ <?= number_format($h['valor_total'], 2, ',', '.') ?></td>
            <td style="padding:.45rem .6rem;text-align:right;color:#059669;font-weight:600;">R$ <?= number_format($h['valor_pago'], 2, ',', '.') ?></td>
            <td style="padding:.45rem .6rem;text-align:right;color:#dc2626;font-weight:700;">R$ <?= number_format($saldo, 2, ',', '.') ?></td>
            <td style="padding:.45rem .6rem;text-align:center;"><span style="background:<?= $stl[0] ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:.68rem;font-weight:700;"><?= $stl[1] ?></span></td>
            <td style="padding:.45rem .6rem;text-align:center;font-weight:700;color:#d97706;"><?= max(0, (int)$h['dias_atraso']) ?>d</td>
            <td style="padding:.45rem .6rem;font-size:.75rem;"><?= e($h['responsavel_nome'] ?: '—') ?></td>
            <td style="padding:.45rem .6rem;">
                <button onclick="abrirDetalhe(<?= $h['id'] ?>)" style="background:none;border:none;cursor:pointer;font-size:.75rem;color:#B87333;font-weight:700;">Ver →</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ═══ ABA 4: CONFIGURAÇÕES (Admin) ═══ -->
<?php if ($isAdmin): ?>
<div class="ch-panel <?= $abaAtiva === 'config' ? 'active' : '' ?>">
    <div style="max-width:700px;">
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1rem;">
            <h4 style="font-size:.9rem;font-weight:700;margin-bottom:1rem;">⚙️ Parâmetros do Fluxo</h4>
            <form method="POST" action="<?= module_url('cobranca_honorarios', 'api.php') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="salvar_config">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;">
                    <div>
                        <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Dias para entrada automática</label>
                        <input type="number" name="dias_para_cobranca" class="form-input" value="<?= (int)$config['dias_para_cobranca'] ?>" min="1" max="365">
                        <span style="font-size:.62rem;color:var(--text-muted);">Cobranças vencidas há X dias entram automaticamente no fluxo</span>
                    </div>
                    <div>
                        <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Responsável padrão judicial</label>
                        <select name="responsavel_padrao_id" class="form-select">
                            <option value="">— Nenhum —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ($config['responsavel_padrao_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:1rem;">
                    <div>
                        <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Prazo Notif. 1 (dias)</label>
                        <input type="number" name="prazo_notificacao_1" class="form-input" value="<?= (int)$config['prazo_notificacao_1'] ?>" min="1" max="90">
                    </div>
                    <div>
                        <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Prazo Notif. 2 (dias)</label>
                        <input type="number" name="prazo_notificacao_2" class="form-input" value="<?= (int)$config['prazo_notificacao_2'] ?>" min="1" max="90">
                    </div>
                    <div>
                        <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Prazo Extrajudicial (dias)</label>
                        <input type="number" name="prazo_extrajudicial" class="form-input" value="<?= (int)$config['prazo_extrajudicial'] ?>" min="1" max="90">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Salvar Parâmetros</button>
            </form>
        </div>

        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;">
            <h4 style="font-size:.9rem;font-weight:700;margin-bottom:1rem;">📱 Templates de Mensagem WhatsApp</h4>
            <form method="POST" action="<?= module_url('cobranca_honorarios', 'api.php') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="salvar_mensagens">

                <div style="margin-bottom:.8rem;">
                    <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Notificação 1 (amigável)</label>
                    <textarea name="msg_notificacao_1" rows="4" class="form-input" style="font-size:.8rem;resize:vertical;"><?= e($config['msg_notificacao_1'] ?? '') ?></textarea>
                    <span style="font-size:.62rem;color:var(--text-muted);">Variáveis: [Nome], [valor], [data]</span>
                </div>

                <div style="margin-bottom:.8rem;">
                    <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Notificação 2 (formal)</label>
                    <textarea name="msg_notificacao_2" rows="4" class="form-input" style="font-size:.8rem;resize:vertical;"><?= e($config['msg_notificacao_2'] ?? '') ?></textarea>
                    <span style="font-size:.62rem;color:var(--text-muted);">Variáveis: [Nome], [valor], [data]</span>
                </div>

                <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Salvar Mensagens</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: Nova Cobrança / Marcar Inadimplência ═══ -->
<div id="modalNovaCobranca" class="ch-modal">
<div class="ch-modal-box">
    <h3 style="font-size:1rem;margin-bottom:1rem;color:var(--petrol-900);">⚠️ Marcar Inadimplência</h3>
    <form method="POST" action="<?= module_url('cobranca_honorarios', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="criar_cobranca">

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Cliente *</label>
            <select name="client_id" class="form-select" required>
                <option value="">— Selecionar —</option>
                <?php
                $clientes = $pdo->query("SELECT id, name, cpf FROM clients ORDER BY name")->fetchAll();
                foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?><?= $c['cpf'] ? ' — ' . e($c['cpf']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Tipo do débito *</label>
                <select name="tipo_debito" class="form-select" required>
                    <option value="Honorários advocatícios">Honorários advocatícios</option>
                    <option value="Honorários contratuais">Honorários contratuais</option>
                    <option value="Honorários de êxito">Honorários de êxito</option>
                    <option value="Custas processuais">Custas processuais</option>
                    <option value="Outro">Outro</option>
                </select>
            </div>
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Valor (R$) *</label>
                <input type="text" name="valor_total" class="form-input input-reais" required placeholder="0,00">
            </div>
        </div>

        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Vencimento *</label>
                <input type="date" name="vencimento" class="form-input" required>
            </div>
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Processo vinculado</label>
                <select name="case_id" class="form-select">
                    <option value="">— Nenhum —</option>
                    <?php
                    $casos = $pdo->query("SELECT cs.id, cs.title, cl.name FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id WHERE cs.status NOT IN ('cancelado','arquivado') ORDER BY cs.title LIMIT 100")->fetchAll();
                    foreach ($casos as $cs): ?>
                    <option value="<?= $cs['id'] ?>"><?= e($cs['title']) ?> — <?= e($cs['name'] ?: '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Observação</label>
            <textarea name="observacoes" rows="2" class="form-input" style="resize:vertical;" placeholder="Detalhes adicionais..."></textarea>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalNovaCobranca').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Registrar Inadimplência</button>
        </div>
    </form>
</div></div>

<!-- ═══ MODAL: Detalhe da Cobrança ═══ -->
<div id="modalDetalhe" class="ch-modal">
<div class="ch-modal-box" id="modalDetalheContent">
    <p style="text-align:center;padding:2rem;color:var(--text-muted);">Carregando...</p>
</div></div>

<!-- ═══ MODAL: Pagamento ═══ -->
<div id="modalPagamento" class="ch-modal">
<div class="ch-modal-box">
    <h3 style="font-size:1rem;margin-bottom:1rem;color:var(--petrol-900);">💰 Registrar Pagamento</h3>
    <form method="POST" action="<?= module_url('cobranca_honorarios', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="registrar_pagamento">
        <input type="hidden" name="cobranca_id" id="pagCobrancaId">

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Valor pago (R$) *</label>
            <input type="text" name="valor_pago" id="pagValor" class="form-input input-reais" required placeholder="0,00">
            <span style="font-size:.62rem;color:var(--text-muted);" id="pagSaldoInfo"></span>
        </div>

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Recebido via</label>
            <select name="enviado_via" class="form-select">
                <option value="manual">Manual / Dinheiro</option>
                <option value="whatsapp">PIX (WhatsApp)</option>
                <option value="portal">Portal / Asaas</option>
            </select>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalPagamento').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#059669;">Confirmar Pagamento</button>
        </div>
    </form>
</div></div>

<!-- ═══ MODAL: Avançar para Judicial ═══ -->
<div id="modalJudicial" class="ch-modal">
<div class="ch-modal-box">
    <h3 style="font-size:1rem;margin-bottom:1rem;color:var(--petrol-900);">⚖️ Mover para Cobrança Judicial</h3>
    <form method="POST" action="<?= module_url('cobranca_honorarios', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="mover_judicial">
        <input type="hidden" name="cobranca_id" id="judicialCobrancaId">

        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;">
            Ao mover para judicial, um caso será criado automaticamente no Kanban Operacional e o responsável será notificado.
        </p>

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Responsável pela cobrança judicial *</label>
            <select name="responsavel_id" class="form-select" required>
                <option value="">— Selecionar —</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalJudicial').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#1e40af;">Confirmar Judicial</button>
        </div>
    </form>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// Gráfico
(function(){
    var c = document.getElementById('chartCobranca');
    if (c) new Chart(c, {
        type: 'bar',
        data: {
            labels: <?= json_encode($grafLabels) ?>,
            datasets: [
                { label: 'Recuperado', data: <?= json_encode($grafRecuperado) ?>, backgroundColor: 'rgba(5,150,105,.6)', borderRadius: 4 },
                { label: 'Em Aberto', data: <?= json_encode($grafAberto) ?>, backgroundColor: 'rgba(220,38,38,.4)', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { labels: { color: '#94a3b8', font: { size: 11 } } } },
            scales: {
                y: { beginAtZero: true, ticks: { color: '#94a3b8', callback: function(v){ return 'R$'+v.toLocaleString('pt-BR'); } }, grid: { color: 'rgba(148,163,184,.08)' } },
                x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
            }
        }
    });
})();

function abrirDetalhe(id) {
    document.getElementById('modalDetalhe').style.display = 'flex';
    var box = document.getElementById('modalDetalheContent');
    box.innerHTML = '<p style="text-align:center;padding:2rem;color:var(--text-muted);">Carregando...</p>';
    fetch('<?= module_url('cobranca_honorarios', 'api.php') ?>?action=detalhe&id=' + id)
        .then(function(r) { return r.text(); })
        .then(function(html) { box.innerHTML = html; });
}

function avancarEtapa(id, acao) {
    if (acao === 'judicial') {
        document.getElementById('judicialCobrancaId').value = id;
        document.getElementById('modalJudicial').style.display = 'flex';
        return;
    }
    if (!confirm('Confirma avançar esta cobrança para a próxima etapa?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= module_url('cobranca_honorarios', 'api.php') ?>';
    form.innerHTML = '<input type="hidden" name="action" value="avancar_etapa">' +
        '<input type="hidden" name="cobranca_id" value="' + id + '">' +
        '<input type="hidden" name="proxima_etapa" value="' + acao + '">' +
        '<?= csrf_input() ?>';
    document.body.appendChild(form);
    form.submit();
}

function registrarPagamento(id, saldo) {
    document.getElementById('pagCobrancaId').value = id;
    document.getElementById('pagSaldoInfo').textContent = 'Saldo devedor: R$ ' + saldo.toFixed(2).replace('.', ',');
    document.getElementById('modalPagamento').style.display = 'flex';
}

// Fechar modais ao clicar fora
document.querySelectorAll('.ch-modal').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.style.display = 'none';
    });
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
