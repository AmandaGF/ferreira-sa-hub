<?php
/**
 * Ferreira & Sá Hub — Dashboard Reformulado (Geral / Comercial / Operacional)
 * Spec: DASHBOARD_SPEC.md — Abril/2026
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Dashboard';
$user = current_user();
$role = current_user_role();
$pdo = db();
$firstName = explode(' ', $user['name'])[0];

$mesAtual = date('Y-m');
$mesAnterior = date('Y-m', strtotime('-1 month'));
$ML = array('','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez');
$mesNome = $ML[(int)date('n')];
$tab = $_GET['tab'] ?? 'geral';

// Helper: query segura
function qval($pdo, $sql) { try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Exception $e) { return 0; } }
function qfloat($pdo, $sql) { try { $v = $pdo->query($sql)->fetchColumn(); return $v ? round((float)$v, 2) : 0; } catch (Exception $e) { return 0; } }
function qrows($pdo, $sql) { try { return $pdo->query($sql)->fetchAll(); } catch (Exception $e) { return array(); } }

// ═══════════════════════════════════════════════════════════
// METAS (editáveis pelo admin, salvas em configuracoes)
// ═══════════════════════════════════════════════════════════
$metasDefault = array('contratos_mes' => 10, 'faturamento_mes' => 50000, 'distribuicoes_mes' => 8, 'entregas_mes' => 5);
$metas = $metasDefault;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chave VARCHAR(60) UNIQUE NOT NULL, valor TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $metaRows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'meta_%'")->fetchAll();
    foreach ($metaRows as $mr) {
        $key = str_replace('meta_', '', $mr['chave']);
        if (isset($metas[$key])) $metas[$key] = (int)$mr['valor'];
    }
    foreach ($metasDefault as $k => $v) {
        $pdo->prepare("INSERT IGNORE INTO configuracoes (chave, valor) VALUES (?, ?)")->execute(array('meta_' . $k, $v));
    }
} catch (Exception $e) {}

// POST: admin salva metas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'salvar_metas' && has_role('admin')) {
    if (validate_csrf()) {
        foreach (array_keys($metasDefault) as $mk) {
            $val = (int)($_POST[$mk] ?? 0);
            if ($val > 0) {
                try { $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?")->execute(array('meta_' . $mk, $val, $val)); $metas[$mk] = $val; } catch (Exception $e) {}
            }
        }
        flash_set('success', 'Metas atualizadas!');
        redirect(module_url('dashboard', '?tab=' . $tab));
    }
}

// ═══════════════════════════════════════════════════════════
// MÉTRICAS COMERCIAL
// ═══════════════════════════════════════════════════════════

// Contratos fechados no mês (converted_at no mês)
$contratosMes = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at,'%Y-%m')='$mesAtual'");
$contratosMesAnt = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at,'%Y-%m')='$mesAnterior'");

// Faturamento do mês
$faturamentoMes = qfloat($pdo, "SELECT IFNULL(SUM(estimated_value_cents),0)/100 FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at,'%Y-%m')='$mesAtual'");
$faturamentoMesAnt = qfloat($pdo, "SELECT IFNULL(SUM(estimated_value_cents),0)/100 FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at,'%Y-%m')='$mesAnterior'");

// Ticket médio
$ticketMedio = $contratosMes > 0 ? round($faturamentoMes / $contratosMes, 2) : 0;

// Melhor mês em 12 meses (faturamento e contratos)
$melhorFat = 0; $melhorFatMes = '';
$melhorContratos = 0; $melhorContratosMes = '';
for ($i = 1; $i <= 12; $i++) {
    $m = date('Y-m', strtotime("-$i months"));
    $mLabel = $ML[(int)date('n', strtotime("-$i months"))] . '/' . date('y', strtotime("-$i months"));
    $fat = qfloat($pdo, "SELECT IFNULL(SUM(estimated_value_cents),0)/100 FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at,'%Y-%m')='$m'");
    $con = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at,'%Y-%m')='$m'");
    if ($fat > $melhorFat) { $melhorFat = $fat; $melhorFatMes = $mLabel; }
    if ($con > $melhorContratos) { $melhorContratos = $con; $melhorContratosMes = $mLabel; }
}

// Tempo médio lead → contrato (dias)
$tempoMedio = qfloat($pdo, "SELECT AVG(DATEDIFF(converted_at, created_at)) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at,'%Y-%m')='$mesAtual'");

// Pipeline por estágio — sem importados
// Aguardando assinatura = SOMENTE quem recebeu o link mas ainda não assinou
$aguardandoContrato = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE stage = 'link_enviados' AND (notes IS NULL OR notes NOT LIKE '%Importado%')");
// Em captação = ainda preparando docs/contrato
$emCaptacao = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('cadastro_preenchido','elaboracao_docs') AND (notes IS NULL OR notes NOT LIKE '%Importado%')");
$pastasAptas = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE stage = 'pasta_apta'");

// Cancelados
$canceladosMes = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('cancelado','perdido') AND DATE_FORMAT(updated_at,'%Y-%m')='$mesAtual'");
$canceladosTotal = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('cancelado','perdido')");
$canceladosDetalhe = qrows($pdo, "SELECT name, DATE_FORMAT(converted_at,'%m/%Y') as mes_contrato, DATE_FORMAT(updated_at,'%d/%m') as data_cancel FROM pipeline_leads WHERE stage IN ('cancelado','perdido') AND DATE_FORMAT(updated_at,'%Y-%m')='$mesAtual' ORDER BY updated_at DESC LIMIT 10");

// Funil — sem importados
$pipeStages = array('cadastro_preenchido'=>0,'elaboracao_docs'=>0,'link_enviados'=>0,'contrato_assinado'=>0,'agendado_docs'=>0,'reuniao_cobranca'=>0,'pasta_apta'=>0,'perdido'=>0);
$pipeRows = qrows($pdo, "SELECT stage, COUNT(*) as total FROM pipeline_leads WHERE notes IS NULL OR notes NOT LIKE '%Importado%' GROUP BY stage");
foreach ($pipeRows as $r) { if (isset($pipeStages[$r['stage']])) $pipeStages[$r['stage']] = (int)$r['total']; }

// Taxa de conversão + entradas/contratos (6 meses, sem importados)
$convLabels = array(); $convData = array(); $convEntradas = array(); $convConvertidos = array();
for ($i = 5; $i >= 0; $i--) {
    $mes = date('Y-m', strtotime("-$i months"));
    $convLabels[] = $ML[(int)date('n', strtotime("-$i months"))];
    $total = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE DATE_FORMAT(created_at,'%Y-%m')='$mes' AND (notes IS NULL OR notes NOT LIKE '%Importado%')");
    $conv = qval($pdo, "SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at,'%Y-%m')='$mes'");
    $convEntradas[] = $total;
    $convConvertidos[] = $conv;
    $convData[] = $total > 0 ? round(($conv / $total) * 100) : 0;
}

// Ranking tipos de ação
$rankingAcoes = qrows($pdo, "SELECT case_type, COUNT(*) as total, IFNULL(SUM(estimated_value_cents),0)/100 as faturamento FROM pipeline_leads WHERE converted_at IS NOT NULL AND case_type IS NOT NULL AND case_type != '' GROUP BY case_type ORDER BY total DESC LIMIT 8");

// ═══════════════════════════════════════════════════════════
// MÉTRICAS OPERACIONAL
// ═══════════════════════════════════════════════════════════

$casosEmAndamento = qval($pdo, "SELECT COUNT(*) FROM cases WHERE status = 'em_andamento'");
$casosSuspensos = qval($pdo, "SELECT COUNT(*) FROM cases WHERE status = 'suspenso'");
$casosDocFaltante = qval($pdo, "SELECT COUNT(*) FROM cases WHERE status = 'doc_faltante'");
$distribuidos = qval($pdo, "SELECT COUNT(*) FROM cases WHERE status = 'distribuido' AND DATE_FORMAT(updated_at,'%Y-%m')='$mesAtual'");
$distribuidosAnt = qval($pdo, "SELECT COUNT(*) FROM cases WHERE status = 'distribuido' AND DATE_FORMAT(updated_at,'%Y-%m')='$mesAnterior'");
$entregasMes = qval($pdo, "SELECT COUNT(*) FROM cases WHERE status IN ('concluido','arquivado') AND DATE_FORMAT(updated_at,'%Y-%m')='$mesAtual'");

// Prazos vencendo em 7 dias
$prazos7dias = qval($pdo, "SELECT COUNT(*) FROM prazos_processuais WHERE concluido = 0 AND prazo_fatal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$prazosLista = qrows($pdo, "SELECT p.descricao_acao, p.prazo_fatal, DATEDIFF(p.prazo_fatal, CURDATE()) as dias, cl.name as client_name FROM prazos_processuais p LEFT JOIN clients cl ON cl.id = p.client_id WHERE p.concluido = 0 AND p.prazo_fatal >= CURDATE() ORDER BY p.prazo_fatal LIMIT 10");

// Clientes sem movimentação 30+ dias
$semMovimentacao = qrows($pdo, "SELECT c.id, c.title, cl.name, DATEDIFF(NOW(), c.updated_at) as dias_parado FROM cases c JOIN clients cl ON cl.id = c.client_id WHERE c.status NOT IN ('cancelado','concluido','arquivado','renunciamos','distribuido') AND c.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY dias_parado DESC LIMIT 10");

// Carga por responsável (só ativos)
$cargaResp = qrows($pdo, "SELECT u.name, COUNT(CASE WHEN c.status NOT IN ('cancelado','concluido','arquivado','renunciamos','distribuido') THEN 1 END) as ativos, COUNT(CASE WHEN c.status='distribuido' AND DATE_FORMAT(c.updated_at,'%Y-%m')='$mesAtual' THEN 1 END) as distribuidos_mes FROM users u LEFT JOIN cases c ON c.responsible_user_id = u.id WHERE u.is_active = 1 GROUP BY u.id ORDER BY ativos DESC");

// Distribuídos x Pendentes (6 meses)
$distPendLabels = array(); $distPendDist = array(); $distPendPend = array();
for ($i = 5; $i >= 0; $i--) {
    $mes = date('Y-m', strtotime("-$i months"));
    $distPendLabels[] = $ML[(int)date('n', strtotime("-$i months"))];
    $distPendDist[] = qval($pdo, "SELECT COUNT(*) FROM cases WHERE status='distribuido' AND DATE_FORMAT(updated_at,'%Y-%m')='$mes'");
    $distPendPend[] = qval($pdo, "SELECT COUNT(*) FROM cases WHERE status IN ('em_elaboracao','em_andamento','pasta_apta') AND DATE_FORMAT(updated_at,'%Y-%m')='$mes'");
}

// ═══════════════════════════════════════════════════════════
// GERAL — Pendências, clientes, compromissos
// ═══════════════════════════════════════════════════════════
$totalClientes = qval($pdo, "SELECT COUNT(*) FROM clients");
$ticketsAbertos = qval($pdo, "SELECT COUNT(*) FROM tickets WHERE status IN ('aberto','em_andamento','aguardando')");
$docsFaltantes = qval($pdo, "SELECT COUNT(*) FROM documentos_pendentes WHERE status = 'pendente'");

// Próximos compromissos (3 dias)
$proxCompromissos = qrows($pdo, "SELECT e.titulo, e.tipo, e.data_inicio, e.local, cl.name as client_name FROM agenda_eventos e LEFT JOIN clients cl ON cl.id = e.client_id WHERE e.status != 'cancelado' AND e.data_inicio BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) ORDER BY e.data_inicio LIMIT 5");

// ═══════════════════════════════════════════════════════════
// ATIVIDADES RECENTES — linguagem humana
// ═══════════════════════════════════════════════════════════
$atividades = qrows($pdo, "SELECT al.action, al.entity_type, al.details, al.created_at, u.name as user_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id WHERE al.action NOT IN ('login','logout','login_failed') ORDER BY al.created_at DESC LIMIT 10");

$traducoes = array(
    'case_status' => 'moveu caso para', 'doc_faltante' => 'sinalizou documento faltante',
    'stage_change' => 'avançou lead para', 'case_created' => 'criou novo caso',
    'client_created' => 'cadastrou novo cliente', 'peticao_gerada' => 'gerou petição',
    'contrato_assinado' => 'confirmou contrato assinado', 'pasta_apta' => 'marcou pasta como apta',
    'processo_distribuido' => 'registrou distribuição', 'doc_recebido' => 'confirmou recebimento de documento',
    'cancelado' => 'cancelou caso', 'user_approved' => 'aprovou usuário', 'user_activated' => 'ativou usuário',
    'user_deactivated' => 'desativou usuário', 'user_rejected' => 'recusou usuário',
    'password_reset' => 'redefiniu senha', 'lead_created' => 'cadastrou novo cliente no pipeline',
    'lead_moved' => 'avançou etapa no pipeline', 'lead_converted' => 'converteu em contrato',
    'ticket_created' => 'abriu chamado', 'ticket_closed' => 'encerrou chamado',
    'ANDAMENTO_EXCLUIDO' => 'excluiu andamento',
);

// Aniversariantes
$aniversariantes = qrows($pdo, "SELECT name, phone, birth_date, TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) as idade FROM clients WHERE birth_date IS NOT NULL AND DATE_FORMAT(birth_date,'%m-%d') = DATE_FORMAT(CURDATE(),'%m-%d') ORDER BY name");
$proxAniversarios = qrows($pdo, "SELECT name, DATE_FORMAT(birth_date,'%d/%m') as data_fmt, DATEDIFF(DATE_ADD(birth_date, INTERVAL TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) + IF(DATE_FORMAT(birth_date,'%m%d') <= DATE_FORMAT(CURDATE(),'%m%d'),1,0) YEAR), CURDATE()) as dias_faltam FROM clients WHERE birth_date IS NOT NULL AND DATE_FORMAT(birth_date,'%m-%d') != DATE_FORMAT(CURDATE(),'%m-%d') HAVING dias_faltam BETWEEN 1 AND 7 ORDER BY dias_faltam LIMIT 5");

// ═══════════════════════════════════════════════════════════
require_once APP_ROOT . '/templates/layout_start.php';

// Helper: barra de comparativo
function comparativo($atual, $anterior) {
    $diff = $atual - $anterior;
    $pct = $anterior > 0 ? round(($diff / $anterior) * 100) : ($atual > 0 ? 100 : 0);
    $cls = $diff > 0 ? 'stat-up' : ($diff < 0 ? 'stat-down' : 'stat-equal');
    $arrow = $diff > 0 ? '↑' : ($diff < 0 ? '↓' : '=');
    return '<div class="stat-compare ' . $cls . '">' . $arrow . abs($diff) . ' (' . ($pct >= 0 ? '+' : '') . $pct . '%) vs mês anterior</div>';
}

function metaBar($atual, $meta, $width = '100%') {
    $pct = $meta > 0 ? min(100, round(($atual / $meta) * 100)) : 0;
    $cor = $pct >= 80 ? '#059669' : ($pct >= 50 ? '#f59e0b' : '#dc2626');
    return '<div class="meta-bar" style="width:' . $width . ';"><div class="meta-fill" style="width:' . $pct . '%;background:' . $cor . ';"></div></div><div class="meta-text">' . $pct . '% da meta (' . $meta . ')</div>';
}
?>

<style>
.dash-welcome { background:linear-gradient(135deg,#052228 0%,#0d3640 50%,#173d46 100%); border-radius:var(--radius-lg); padding:1.5rem 2rem; color:#fff; margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center; }
.dash-welcome h2 { font-size:1.2rem; font-weight:800; margin-bottom:.25rem; }
.dash-welcome .sub { color:rgba(255,255,255,.5); font-size:.78rem; }
.dash-tabs { display:flex; gap:0; margin-bottom:1.25rem; border-bottom:2px solid var(--border); }
.dash-tab { padding:.6rem 1.5rem; font-size:.85rem; font-weight:700; color:var(--text-muted); cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; text-decoration:none; transition:all .2s; }
.dash-tab:hover { color:var(--petrol-900); } .dash-tab.active { color:var(--petrol-900); border-bottom-color:#B87333; }

.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.75rem; margin-bottom:1.25rem; }
.kpi-card { background:var(--bg-card); border-radius:var(--radius-lg); padding:1rem 1.15rem; border:1px solid var(--border); display:flex; align-items:center; gap:.75rem; transition:all var(--transition); }
.kpi-card:hover { box-shadow:var(--shadow-md); transform:translateY(-2px); }
a.kpi-card { text-decoration:none; color:inherit; cursor:pointer; }
.kpi-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
.kpi-icon.blue { background:rgba(99,102,241,.12); } .kpi-icon.green { background:rgba(5,150,105,.12); }
.kpi-icon.orange { background:rgba(249,115,22,.12); } .kpi-icon.red { background:rgba(239,68,68,.12); }
.kpi-icon.rose { background:rgba(215,171,144,.12); } .kpi-icon.purple { background:rgba(139,92,246,.12); }
.kpi-value { font-size:1.5rem; font-weight:800; color:var(--petrol-900); line-height:1; }
.kpi-label { font-size:.68rem; color:var(--text-muted); margin-top:.1rem; text-transform:uppercase; letter-spacing:.4px; }
.kpi-sub { font-size:.65rem; color:var(--rose); font-weight:600; margin-top:.1rem; }

.meta-bar { height:5px; background:#e5e7eb; border-radius:3px; margin-top:.3rem; overflow:hidden; }
.meta-fill { height:100%; border-radius:3px; transition:width .5s; }
.meta-text { font-size:.58rem; color:var(--text-muted); margin-top:.1rem; }
.stat-compare { display:flex; align-items:center; gap:.2rem; font-size:.63rem; font-weight:600; margin-top:.1rem; }
.stat-up { color:#059669; } .stat-down { color:#dc2626; } .stat-equal { color:var(--text-muted); }

.dash-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem; }
.dash-grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1.25rem; }
.dash-card { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1.15rem; }
.dash-card h4 { font-size:.85rem; font-weight:700; color:var(--petrol-900); margin-bottom:.75rem; display:flex; align-items:center; gap:.4rem; }
.dash-card canvas { max-height:200px; }
.dash-card table { width:100%; border-collapse:collapse; font-size:.78rem; }
.dash-card th { text-align:left; font-size:.68rem; text-transform:uppercase; color:var(--text-muted); padding:.4rem .5rem; border-bottom:1px solid var(--border); }
.dash-card td { padding:.4rem .5rem; border-bottom:1px solid rgba(0,0,0,.04); }

.funnel-card { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1.15rem; margin-bottom:1.25rem; }
.funnel-bar { display:flex; gap:3px; height:28px; border-radius:var(--radius); overflow:hidden; margin-bottom:.4rem; }
.funnel-segment { display:flex; align-items:center; justify-content:center; font-size:.6rem; font-weight:700; color:#fff; min-width:22px; }
.funnel-legend { display:flex; flex-wrap:wrap; gap:.35rem .6rem; }
.funnel-legend-item { display:flex; align-items:center; gap:.25rem; font-size:.65rem; color:var(--text-muted); }
.funnel-legend-dot { width:8px; height:8px; border-radius:2px; }

.alert-item { display:flex; align-items:center; gap:.6rem; padding:.5rem .6rem; margin-bottom:.3rem; border-radius:8px; font-size:.78rem; }
.alert-item.warn { background:#fffbeb; border-left:3px solid #f59e0b; }
.alert-item.danger { background:#fef2f2; border-left:3px solid #dc2626; }
.alert-item.info { background:#eff6ff; border-left:3px solid #6366f1; }

.activity-item { display:flex; gap:.6rem; padding:.45rem 0; border-bottom:1px solid var(--border); align-items:flex-start; }
.activity-item:last-child { border-bottom:none; }
.activity-dot { width:7px; height:7px; border-radius:50%; margin-top:.35rem; flex-shrink:0; }
.activity-dot.green { background:#059669; } .activity-dot.blue { background:#6366f1; } .activity-dot.orange { background:#f59e0b; } .activity-dot.red { background:#ef4444; }
.activity-text { font-size:.75rem; color:var(--text); line-height:1.35; }
.activity-text strong { color:var(--petrol-900); }
.activity-time { font-size:.6rem; color:var(--text-muted); }

.bday-item { display:flex; align-items:center; gap:.6rem; padding:.45rem 0; border-bottom:1px solid var(--border); }
.bday-item:last-child { border-bottom:none; }
.bday-avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,var(--rose),#B87333); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.65rem; font-weight:700; flex-shrink:0; }
.bday-tag { font-size:.6rem; font-weight:700; padding:.15rem .4rem; border-radius:5px; color:#fff; }
.bday-tag.today { background:#059669; } .bday-tag.soon { background:#6366f1; }

@media (max-width:1024px) { .kpi-grid { grid-template-columns:repeat(2,1fr); } .dash-grid2,.dash-grid3 { grid-template-columns:1fr; } }
@media (max-width:600px) { .kpi-grid { grid-template-columns:1fr; } .dash-welcome { flex-direction:column; text-align:center; } }
</style>

<!-- Bem-vindo -->
<div class="dash-welcome">
    <div>
        <h2>Bem-vindo(a), <?= e($firstName) ?>!</h2>
        <div class="sub"><?= e(role_label($role)) ?> — <?= date('d/m/Y') ?></div>
    </div>
    <?php if (has_role('admin')): ?>
    <button onclick="document.getElementById('modalMetas').style.display='flex';" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);font-size:.72rem;">⚙️ Metas</button>
    <?php endif; ?>
</div>

<!-- Abas -->
<div class="dash-tabs">
    <a href="?tab=geral" class="dash-tab <?= $tab === 'geral' ? 'active' : '' ?>">Geral</a>
    <?php if (can_access('dashboard_comercial')): ?><a href="?tab=comercial" class="dash-tab <?= $tab === 'comercial' ? 'active' : '' ?>">Comercial</a><?php endif; ?>
    <?php if (can_access('dashboard_operacional')): ?><a href="?tab=operacional" class="dash-tab <?= $tab === 'operacional' ? 'active' : '' ?>">Operacional</a><?php endif; ?>
</div>

<?php if ($tab === 'geral'): ?>
<!-- ═══════════════ ABA GERAL ═══════════════ -->
<div class="kpi-grid">
    <a href="?tab=comercial" class="kpi-card">
        <div class="kpi-icon green">✅</div>
        <div><div class="kpi-value"><?= $contratosMes ?></div><div class="kpi-label">Contratos em <?= $mesNome ?></div><?= comparativo($contratosMes, $contratosMesAnt) ?><?= metaBar($contratosMes, $metas['contratos_mes'], '100px') ?></div>
    </a>
    <a href="<?= module_url('pipeline') ?>" class="kpi-card">
        <div class="kpi-icon blue">📝</div>
        <div><div class="kpi-value"><?= $aguardandoContrato ?></div><div class="kpi-label">Aguardando Contrato</div><div class="kpi-sub">Assinatura pendente</div></div>
    </a>
    <a href="?tab=operacional" class="kpi-card">
        <div class="kpi-icon <?= $prazos7dias > 0 ? 'red' : 'green' ?>">⏰</div>
        <div><div class="kpi-value"><?= $prazos7dias ?></div><div class="kpi-label">Prazos em 7 dias</div><?php if ($prazos7dias > 0): ?><div class="kpi-sub" style="color:#dc2626;">Atenção!</div><?php endif; ?></div>
    </a>
</div>

<div class="kpi-grid">
    <a href="<?= module_url('operacional') ?>" class="kpi-card">
        <div class="kpi-icon green">📂</div>
        <div><div class="kpi-value"><?= $pastasAptas ?></div><div class="kpi-label">Pastas Aptas</div></div>
    </a>
    <a href="?tab=operacional" class="kpi-card">
        <div class="kpi-icon orange">🏛️</div>
        <div><div class="kpi-value"><?= $distribuidos ?></div><div class="kpi-label">Distribuídos em <?= $mesNome ?></div><?= comparativo($distribuidos, $distribuidosAnt) ?></div>
    </a>
    <a href="<?= module_url('helpdesk') ?>" class="kpi-card">
        <div class="kpi-icon rose">🎫</div>
        <div><div class="kpi-value"><?= $ticketsAbertos ?></div><div class="kpi-label">Chamados Abertos</div></div>
    </a>
    <a href="<?= module_url('crm') ?>" class="kpi-card">
        <div class="kpi-icon blue">👥</div>
        <div><div class="kpi-value"><?= $totalClientes ?></div><div class="kpi-label">Clientes</div></div>
    </a>
</div>

<!-- Alertas + Compromissos + Atividades + Aniversários -->
<?php if ($prazos7dias > 0 || $docsFaltantes > 0 || !empty($proxCompromissos)): ?>
<div class="dash-card" style="margin-bottom:1.25rem;">
    <h4>🔔 Alertas e Próximos Compromissos</h4>
    <?php foreach ($prazosLista as $p): if ((int)$p['dias'] <= 7): ?>
    <div class="alert-item <?= (int)$p['dias'] <= 2 ? 'danger' : 'warn' ?>">
        <span>⏰</span>
        <div style="flex:1;"><strong><?= e($p['descricao_acao']) ?></strong><?php if ($p['client_name']): ?> — <?= e($p['client_name']) ?><?php endif; ?></div>
        <span style="font-size:.72rem;font-weight:700;color:<?= (int)$p['dias'] <= 2 ? '#dc2626' : '#f59e0b' ?>;"><?= (int)$p['dias'] === 0 ? 'HOJE' : $p['dias'] . 'd' ?></span>
    </div>
    <?php endif; endforeach; ?>
    <?php if ($docsFaltantes > 0): ?>
    <div class="alert-item warn"><span>📄</span><div style="flex:1;"><strong><?= $docsFaltantes ?></strong> documento(s) faltante(s)</div><a href="<?= module_url('operacional') ?>" style="font-size:.72rem;">Ver →</a></div>
    <?php endif; ?>
    <?php foreach ($proxCompromissos as $comp): ?>
    <div class="alert-item info">
        <span>📅</span>
        <div style="flex:1;"><strong><?= e($comp['titulo']) ?></strong><?php if ($comp['client_name']): ?> — <?= e($comp['client_name']) ?><?php endif; ?><?php if ($comp['local']): ?> · <?= e($comp['local']) ?><?php endif; ?></div>
        <span style="font-size:.72rem;color:#6366f1;font-weight:600;"><?= date('d/m H:i', strtotime($comp['data_inicio'])) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="dash-grid2">
    <div class="dash-card">
        <h4>🕐 Atividades Recentes</h4>
        <?php foreach ($atividades as $at):
            $acao = isset($traducoes[$at['action']]) ? $traducoes[$at['action']] : $at['action'];
            $dotClass = in_array($at['action'], array('lead_converted','case_created','client_created','ticket_closed','contrato_assinado','pasta_apta','doc_recebido','user_approved')) ? 'green' : (in_array($at['action'], array('cancelado','user_rejected')) ? 'red' : (in_array($at['action'], array('user_deactivated','password_reset','doc_faltante')) ? 'orange' : 'blue'));
            $diff = time() - strtotime($at['created_at']);
            $timeAgo = $diff < 60 ? 'agora' : ($diff < 3600 ? floor($diff/60).'min' : ($diff < 86400 ? floor($diff/3600).'h' : floor($diff/86400).'d'));
            $nome = $at['user_name'] ? explode(' ', $at['user_name'])[0] : 'Sistema';
        ?>
        <div class="activity-item">
            <div class="activity-dot <?= $dotClass ?>"></div>
            <div style="flex:1;"><div class="activity-text"><strong><?= e($nome) ?></strong> <?= e($acao) ?><?php if ($at['details']): ?> <span style="color:var(--text-muted);">— <?= e(mb_substr($at['details'], 0, 60)) ?></span><?php endif; ?></div><div class="activity-time"><?= $timeAgo ?> atrás</div></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($atividades)): ?><p style="color:var(--text-muted);font-size:.78rem;">Nenhuma atividade.</p><?php endif; ?>
    </div>
    <div class="dash-card">
        <h4>🎂 Aniversariantes</h4>
        <?php if (!empty($aniversariantes)): ?><div style="font-size:.68rem;color:var(--rose);font-weight:700;text-transform:uppercase;margin-bottom:.4rem;">Hoje</div>
        <?php foreach ($aniversariantes as $b): ?>
        <div class="bday-item"><div class="bday-avatar"><?= mb_substr($b['name'],0,2,'UTF-8') ?></div><div style="flex:1;"><div style="font-size:.8rem;font-weight:700;color:var(--petrol-900);"><?= e($b['name']) ?></div><div style="font-size:.65rem;color:var(--text-muted);"><?= $b['idade'] ? $b['idade'].' anos' : '' ?></div></div><span class="bday-tag today">HOJE</span></div>
        <?php endforeach; endif; ?>
        <?php if (!empty($proxAniversarios)): ?><div style="font-size:.68rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin:<?= !empty($aniversariantes) ? '.6rem' : '0' ?> 0 .4rem;">Próximos 7 dias</div>
        <?php foreach ($proxAniversarios as $p): ?>
        <div class="bday-item"><div class="bday-avatar" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);"><?= mb_substr($p['name'],0,2,'UTF-8') ?></div><div style="flex:1;"><div style="font-size:.8rem;font-weight:700;"><?= e($p['name']) ?></div><div style="font-size:.65rem;color:var(--text-muted);"><?= e($p['data_fmt']) ?></div></div><span class="bday-tag soon"><?= $p['dias_faltam'] ?>d</span></div>
        <?php endforeach; endif; ?>
        <?php if (empty($aniversariantes) && empty($proxAniversarios)): ?><p style="color:var(--text-muted);font-size:.78rem;">Nenhum nos próximos 7 dias.</p><?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'comercial'): ?>
<!-- ═══════════════ ABA COMERCIAL ═══════════════ -->
<div class="kpi-grid">
    <a href="<?= module_url('pipeline') ?>" class="kpi-card"><div class="kpi-icon green">✅</div><div><div class="kpi-value"><?= $contratosMes ?></div><div class="kpi-label">Contratos em <?= $mesNome ?></div><?= comparativo($contratosMes, $contratosMesAnt) ?><?php if ($melhorContratos > 0): ?><div style="font-size:.58rem;color:var(--text-muted);margin-top:.1rem;">Recorde: <?= $melhorContratos ?> (<?= $melhorContratosMes ?>)</div><?php endif; ?><?= metaBar($contratosMes, $metas['contratos_mes'], '100px') ?></div></a>
    <?php if (can_access('faturamento')): ?>
    <a href="<?= module_url('pipeline') ?>" class="kpi-card"><div class="kpi-icon purple">💰</div><div><div class="kpi-value">R$ <?= number_format($faturamentoMes, 0, ',', '.') ?></div><div class="kpi-label">Faturamento <?= $mesNome ?></div><?= comparativo($faturamentoMes, $faturamentoMesAnt) ?><?php if ($melhorFat > 0): ?><div style="font-size:.58rem;color:var(--text-muted);margin-top:.1rem;">Recorde: R$ <?= number_format($melhorFat, 0, ',', '.') ?> (<?= $melhorFatMes ?>)</div><?php endif; ?></div></a>
    <div class="kpi-card"><div class="kpi-icon blue">🎯</div><div><div class="kpi-value">R$ <?= number_format($ticketMedio, 0, ',', '.') ?></div><div class="kpi-label">Ticket Médio</div><div class="kpi-sub"><?= $tempoMedio > 0 ? round($tempoMedio) . ' dias p/ fechar' : '—' ?></div></div></div>
    <?php else: ?>
    <?php $pctFat = $faturamentoMesAnt > 0 ? round((($faturamentoMes - $faturamentoMesAnt) / $faturamentoMesAnt) * 100) : ($faturamentoMes > 0 ? 100 : 0); ?>
    <?php $pctRecorde = $melhorFat > 0 ? round(($faturamentoMes / $melhorFat) * 100) : 0; ?>
    <div class="kpi-card"><div class="kpi-icon purple">📊</div><div><div class="kpi-value" style="color:<?= $pctFat >= 0 ? '#059669' : '#dc2626' ?>;"><?= $pctFat >= 0 ? '+' : '' ?><?= $pctFat ?>%</div><div class="kpi-label">Faturamento vs <?= $ML[(int)date('n', strtotime('-1 month'))] ?></div><div class="kpi-sub"><?= $pctFat >= 0 ? 'Acima' : 'Abaixo' ?> do mês anterior</div><?php if ($melhorFat > 0): ?><div style="font-size:.58rem;color:var(--text-muted);margin-top:.1rem;"><?= $pctRecorde ?>% do recorde (<?= $melhorFatMes ?>)</div><?php endif; ?></div></div>
    <div class="kpi-card"><div class="kpi-icon blue">🎯</div><div><div class="kpi-value"><?= $tempoMedio > 0 ? round($tempoMedio) . 'd' : '—' ?></div><div class="kpi-label">Tempo Médio p/ Fechar</div><div class="kpi-sub">Dias até contrato</div></div></div>
    <?php endif; ?>
    <a href="<?= module_url('crm') ?>" class="kpi-card"><div class="kpi-icon red">❌</div><div><div class="kpi-value"><?= $canceladosMes ?></div><div class="kpi-label">Cancelados <?= $mesNome ?></div><div class="kpi-sub" style="color:var(--text-muted);">Total: <?= $canceladosTotal ?></div></div></a>
</div>

<?php if (!empty($canceladosDetalhe)): ?>
<div class="dash-card" style="margin-bottom:1.25rem;">
    <h4>❌ Cancelamentos em <?= $mesNome ?> <span style="font-weight:400;color:var(--text-muted);font-size:.72rem;">(quando o contrato foi fechado)</span></h4>
    <?php foreach ($canceladosDetalhe as $cd): ?>
    <div class="alert-item danger"><span class="nome" style="font-weight:600;color:#dc2626;"><?= e($cd['name']) ?></span><span class="info" style="color:var(--text-muted);font-size:.72rem;margin-left:.5rem;">cancelou em <?= $cd['data_cancel'] ?><?= $cd['mes_contrato'] ? ' (contrato: '.$cd['mes_contrato'].')' : '' ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Funil -->
<?php $totalPipe = array_sum($pipeStages);
$fColors = array('cadastro_preenchido'=>'#6366f1','elaboracao_docs'=>'#0ea5e9','link_enviados'=>'#f59e0b','contrato_assinado'=>'#059669','agendado_docs'=>'#0d9488','reuniao_cobranca'=>'#d97706','pasta_apta'=>'#15803d','perdido'=>'#dc2626');
$fLabels = array('cadastro_preenchido'=>'Cadastro','elaboracao_docs'=>'Elaboração','link_enviados'=>'Link Enviado','contrato_assinado'=>'Contrato','agendado_docs'=>'Agendado','reuniao_cobranca'=>'Cobrando Docs','pasta_apta'=>'Pasta Apta','perdido'=>'Cancelado');
?>
<div class="funnel-card"><h4 style="font-size:.85rem;font-weight:700;color:var(--petrol-900);margin-bottom:.75rem;">📊 Funil Comercial <span style="font-weight:400;color:var(--text-muted);font-size:.72rem;">(<?= $totalPipe ?>)</span></h4>
<?php if ($totalPipe > 0): ?><div class="funnel-bar"><?php foreach ($pipeStages as $s => $c): if ($c > 0): ?><div class="funnel-segment" style="flex:<?= $c ?>;background:<?= $fColors[$s] ?? '#888' ?>;" title="<?= $fLabels[$s] ?? $s ?>: <?= $c ?>"><?= $c ?></div><?php endif; endforeach; ?></div>
<div class="funnel-legend"><?php foreach ($pipeStages as $s => $c): ?><div class="funnel-legend-item"><div class="funnel-legend-dot" style="background:<?= $fColors[$s] ?? '#888' ?>;"></div><?= $fLabels[$s] ?? $s ?> (<?= $c ?>)</div><?php endforeach; ?></div>
<?php endif; ?></div>

<div class="dash-grid2">
    <div class="dash-card"><h4>📉 Taxa de Conversão (6 meses)</h4><canvas id="chartConv"></canvas></div>
    <div class="dash-card"><h4>📊 Entradas vs Contratos</h4><canvas id="chartEC"></canvas></div>
</div>

<?php if (!empty($rankingAcoes)): ?>
<div class="dash-card" style="margin-bottom:1.25rem;">
    <h4>🏆 Tipos de Ação mais Contratados</h4>
    <table><thead><tr><th>Tipo</th><th>Qtd</th><?php if (can_access('faturamento')): ?><th>Faturamento</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($rankingAcoes as $ra): ?>
    <tr><td style="font-weight:600;"><?= e($ra['case_type']) ?></td><td><?= $ra['total'] ?></td><?php if (can_access('faturamento')): ?><td>R$ <?= number_format($ra['faturamento'], 0, ',', '.') ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table>
</div>
<?php endif; ?>

<?php elseif ($tab === 'operacional'): ?>
<!-- ═══════════════ ABA OPERACIONAL ═══════════════ -->
<div class="kpi-grid">
    <a href="<?= module_url('operacional') ?>" class="kpi-card"><div class="kpi-icon green">🟢</div><div><div class="kpi-value"><?= $casosEmAndamento ?></div><div class="kpi-label">Em Andamento</div></div></a>
    <a href="<?= module_url('operacional') ?>" class="kpi-card"><div class="kpi-icon orange">🟡</div><div><div class="kpi-value"><?= $casosSuspensos ?></div><div class="kpi-label">Suspensos</div></div></a>
    <a href="<?= module_url('operacional') ?>" class="kpi-card"><div class="kpi-icon red">📄</div><div><div class="kpi-value"><?= $casosDocFaltante ?></div><div class="kpi-label">Doc Faltante</div></div></a>
    <a href="<?= module_url('prazos') ?>" class="kpi-card"><div class="kpi-icon <?= $prazos7dias > 0 ? 'red' : 'green' ?>">⏰</div><div><div class="kpi-value"><?= $prazos7dias ?></div><div class="kpi-label">Prazos 7 dias</div><?php if ($prazos7dias > 0): ?><div class="kpi-sub" style="color:#dc2626;">Atenção!</div><?php endif; ?></div></a>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);">
    <a href="<?= module_url('operacional') ?>" class="kpi-card"><div class="kpi-icon orange">🏛️</div><div><div class="kpi-value"><?= $distribuidos ?></div><div class="kpi-label">Distribuídos <?= $mesNome ?></div><?= comparativo($distribuidos, $distribuidosAnt) ?><?= metaBar($distribuidos, $metas['distribuicoes_mes'], '100px') ?></div></a>
    <a href="<?= module_url('operacional') ?>" class="kpi-card"><div class="kpi-icon green">📦</div><div><div class="kpi-value"><?= $entregasMes ?></div><div class="kpi-label">Entregues <?= $mesNome ?></div><?= metaBar($entregasMes, $metas['entregas_mes'], '100px') ?></div></a>
    <a href="<?= module_url('operacional') ?>" class="kpi-card"><div class="kpi-icon green">📂</div><div><div class="kpi-value"><?= $pastasAptas ?></div><div class="kpi-label">Pastas Aptas</div></div></a>
</div>

<div class="dash-grid2">
    <div class="dash-card"><h4>📊 Distribuídos × Pendentes (6 meses)</h4><canvas id="chartDP"></canvas></div>
    <div class="dash-card"><h4>👷 Carga por Responsável</h4><canvas id="chartCarga"></canvas></div>
</div>

<!-- Prazos vencendo -->
<?php if (!empty($prazosLista)): ?>
<div class="dash-card" style="margin-bottom:1.25rem;">
    <h4>⏰ Prazos Processuais</h4>
    <table><thead><tr><th>Descrição</th><th>Cliente</th><th>Prazo</th><th>Dias</th></tr></thead><tbody>
    <?php foreach ($prazosLista as $p): $dias = (int)$p['dias']; ?>
    <tr style="<?= $dias <= 2 ? 'background:#fef2f2;' : ($dias <= 5 ? 'background:#fffbeb;' : '') ?>">
        <td style="font-weight:600;"><?= e($p['descricao_acao']) ?></td>
        <td><?= e($p['client_name'] ?: '—') ?></td>
        <td style="font-family:monospace;font-size:.72rem;"><?= date('d/m/Y', strtotime($p['prazo_fatal'])) ?></td>
        <td style="font-weight:700;color:<?= $dias <= 2 ? '#dc2626' : ($dias <= 5 ? '#f59e0b' : '#059669') ?>;"><?= $dias === 0 ? 'HOJE' : $dias . 'd' ?></td>
    </tr>
    <?php endforeach; ?></tbody></table>
</div>
<?php endif; ?>

<!-- Sem movimentação 30+ dias -->
<?php if (!empty($semMovimentacao)): ?>
<div class="dash-card" style="margin-bottom:1.25rem;">
    <h4>⚠️ Sem Movimentação há 30+ dias</h4>
    <table><thead><tr><th>Processo</th><th>Cliente</th><th>Dias parado</th></tr></thead><tbody>
    <?php foreach ($semMovimentacao as $sm): ?>
    <tr><td><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $sm['id']) ?>" style="color:var(--petrol-900);font-weight:600;"><?= e($sm['title']) ?></a></td><td><?= e($sm['name']) ?></td><td style="font-weight:700;color:#dc2626;"><?= $sm['dias_parado'] ?>d</td></tr>
    <?php endforeach; ?></tbody></table>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Modal Metas -->
<?php if (has_role('admin')): ?>
<div id="modalMetas" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:420px;width:90%;box-shadow:0 20px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:1rem;margin-bottom:1rem;color:var(--petrol-900);">⚙️ Metas Mensais</h3>
    <form method="POST"><?= csrf_input() ?><input type="hidden" name="action" value="salvar_metas">
        <div style="margin-bottom:.6rem;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Contratos / mês</label><input type="number" name="contratos_mes" value="<?= $metas['contratos_mes'] ?>" class="form-input" min="1" style="width:100%;"></div>
        <div style="margin-bottom:.6rem;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Faturamento / mês (R$)</label><input type="number" name="faturamento_mes" value="<?= $metas['faturamento_mes'] ?>" class="form-input" min="1" style="width:100%;"></div>
        <div style="margin-bottom:.6rem;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Distribuições / mês</label><input type="number" name="distribuicoes_mes" value="<?= $metas['distribuicoes_mes'] ?>" class="form-input" min="1" style="width:100%;"></div>
        <div style="margin-bottom:1rem;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Entregas / mês</label><input type="number" name="entregas_mes" value="<?= $metas['entregas_mes'] ?>" class="form-input" min="1" style="width:100%;"></div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('modalMetas').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Salvar</button>
        </div>
    </form>
</div></div>
<?php endif; ?>

<!-- Chart.js + Auto-refresh -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
    var fc='#94a3b8', gc='rgba(148,163,184,.08)';
    var opts = {responsive:true, plugins:{legend:{labels:{color:fc,font:{size:10}}}}, scales:{y:{beginAtZero:true,ticks:{color:fc,stepSize:5},grid:{color:gc}},x:{ticks:{color:fc},grid:{display:false}}}};

    <?php if ($tab === 'comercial'): ?>
    var c1=document.getElementById('chartConv');
    if(c1) new Chart(c1,{type:'line',data:{labels:<?= json_encode($convLabels) ?>,datasets:[{label:'Conversão %',data:<?= json_encode($convData) ?>,borderColor:'#B87333',backgroundColor:'rgba(184,115,51,.08)',borderWidth:2,tension:.4,fill:true,pointRadius:3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,max:100,ticks:{color:fc,callback:function(v){return v+'%'}},grid:{color:gc}},x:{ticks:{color:fc},grid:{display:false}}}}});

    var c2=document.getElementById('chartEC');
    if(c2) new Chart(c2,{type:'bar',data:{labels:<?= json_encode($convLabels) ?>,datasets:[{label:'Entradas',data:<?= json_encode($convEntradas) ?>,backgroundColor:'rgba(99,102,241,.5)',borderRadius:3},{label:'Contratos',data:<?= json_encode($convConvertidos) ?>,backgroundColor:'rgba(5,150,105,.6)',borderRadius:3}]},options:opts});
    <?php endif; ?>

    <?php if ($tab === 'operacional'): ?>
    var c3=document.getElementById('chartDP');
    if(c3) new Chart(c3,{type:'bar',data:{labels:<?= json_encode($distPendLabels) ?>,datasets:[{label:'Distribuídos',data:<?= json_encode($distPendDist) ?>,backgroundColor:'rgba(5,150,105,.6)',borderRadius:3},{label:'Pendentes',data:<?= json_encode($distPendPend) ?>,backgroundColor:'rgba(249,115,22,.5)',borderRadius:3}]},options:opts});

    var c4=document.getElementById('chartCarga');
    if(c4){
        var nomes=<?= json_encode(array_map(function($r){return explode(' ',$r['name'])[0];}, $cargaResp)) ?>;
        var ativos=<?= json_encode(array_map(function($r){return (int)$r['ativos'];}, $cargaResp)) ?>;
        var distM=<?= json_encode(array_map(function($r){return (int)$r['distribuidos_mes'];}, $cargaResp)) ?>;
        new Chart(c4,{type:'bar',data:{labels:nomes,datasets:[{label:'Ativos',data:ativos,backgroundColor:'rgba(99,102,241,.6)',borderRadius:3},{label:'Distrib. mês',data:distM,backgroundColor:'rgba(5,150,105,.6)',borderRadius:3}]},options:{responsive:true,indexAxis:'y',plugins:{legend:{labels:{color:fc,font:{size:10}}}},scales:{x:{beginAtZero:true,ticks:{color:fc,stepSize:1},grid:{color:gc}},y:{ticks:{color:fc},grid:{display:false}}}}});
    }
    <?php endif; ?>

    // Auto-refresh a cada 5 minutos
    setTimeout(function(){ location.reload(); }, 300000);
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
