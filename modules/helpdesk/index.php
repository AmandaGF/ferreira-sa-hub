<?php
/**
 * Ferreira & Sá Hub — Helpdesk (Chamados)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Helpdesk';
$pdo = db();
$userId = current_user_id();
// Config da cobrança de chamados parados (só gestão+ enxerga o painel)
$hdCobCfg = null;
if (has_min_role('gestao')) {
    require_once APP_ROOT . '/core/functions_helpdesk_cobranca.php';
    $hdCobCfg = helpdesk_cobranca_cfg($pdo);
}
// Filtros
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$search         = trim($_GET['q'] ?? '');

$where = [];
$params = [];

// Equipe vê tudo. Colaborador/estagiário vê só os seus
if (has_role('colaborador') || has_role('estagiario')) {
    $where[] = "(t.requester_id = ? OR ta.user_id = ?)";
    $params[] = $userId;
    $params[] = $userId;
}
$showArquivados = isset($_GET['arquivados']) && $_GET['arquivados'] === '1';
// Aba: 'equipe' (default), 'clientes' (origem='salavip')
$filterOrigem = $_GET['origem'] ?? 'equipe';
if ($filterOrigem === 'clientes') {
    $where[] = "t.origem = 'salavip'";
} else {
    $where[] = "(t.origem IS NULL OR t.origem != 'salavip')";
}
if ($filterStatus) {
    $where[] = "t.status = ?"; $params[] = $filterStatus;
} elseif (!$showArquivados) {
    // Por padrão, ocultar resolvidos e cancelados
    $where[] = "t.status NOT IN ('resolvido','cancelado')";
}
if ($filterPriority) { $where[] = "t.priority = ?"; $params[] = $filterPriority; }
$filterCategory = $_GET['category'] ?? '';
$filterAssignee = (int)($_GET['assignee'] ?? 0);
if ($filterCategory) { $where[] = "t.category = ?"; $params[] = $filterCategory; }
if ($filterAssignee) { $where[] = "ta.user_id = ?"; $params[] = $filterAssignee; }
if ($search) {
    // Amanda 10/06/2026: aceitar busca por ID (#253, 253, " 253 ")
    $searchClean = ltrim(trim($search), '#');
    $like = '%' . $search . '%';
    if (ctype_digit($searchClean) && (int)$searchClean > 0) {
        $where[] = "(t.id = ? OR t.title LIKE ? OR t.client_name LIKE ? OR t.case_number LIKE ?)";
        $params = array_merge($params, array((int)$searchClean, $like, $like, $like));
    } else {
        $where[] = "(t.title LIKE ? OR t.client_name LIKE ? OR t.case_number LIKE ?)";
        $params = array_merge($params, array($like, $like, $like));
    }
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Self-heal: coluna pinned (pra fixar tickets no topo independente de status)
try { $pdo->exec("ALTER TABLE tickets ADD COLUMN pinned TINYINT(1) DEFAULT 0 AFTER status"); } catch (Exception $e) {}

// Ordenação
$sortParam = $_GET['sort'] ?? '';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$validSorts = array(
    'id' => 't.id',
    'titulo' => 't.title',
    'prioridade' => "FIELD(t.priority,'urgente','normal','baixa')",
    'status' => "FIELD(t.status,'aberto','em_andamento','aguardando','resolvido','cancelado')",
    'data' => 't.created_at',
    'atualizado' => 't.updated_at',
);
// Pinned SEMPRE vem primeiro (prefixo do orderBy), independente da ordenação escolhida.
// 11/05/2026: ordem default mudou de "agrupado por status" pra "mais recente primeiro".
// Motivo (Amanda): chamado novo que virava 'em_andamento' descia pro fim da lista
// (depois de TODOS os 40+ abertos antigos), parecendo que "nao aparecia". Agora qualquer
// chamado novo aparece logo abaixo dos pinned, independente de status.
if ($sortParam && isset($validSorts[$sortParam])) {
    $orderBy = "COALESCE(t.pinned, 0) DESC, " . $validSorts[$sortParam] . ' ' . $sortDir;
} else {
    $orderBy = "COALESCE(t.pinned, 0) DESC, t.created_at DESC";
}

// Paginacao (Nilce r7 31/05/2026): antes era LIMIT 100 sem aviso, 170+ chamados
// ficavam ocultos. Agora paginacao real com count + UI no topo e rodape.
$hdPageNum = max(1, (int)($_GET['page'] ?? 1));
$hdPerPage = 100;
$hdOffset  = ($hdPageNum - 1) * $hdPerPage;
$hdTotalLista = 0;

if ($filterOrigem === 'clientes') {
    // ── Aba Chamados de Clientes: puxa de salavip_threads ──
    // Mapeia status salavip → status ticket: aberta → aberto, respondida → em_andamento, fechada → resolvido
    $mapStatus = array('aberta' => 'aberto', 'respondida' => 'em_andamento', 'aguardando' => 'aguardando', 'fechada' => 'resolvido');
    $whereThreads = array();
    $paramsThreads = array();
    if ($filterStatus) {
        // Inverso — mapeia ticket status pra salavip
        $revMap = array('aberto' => 'aberta', 'em_andamento' => 'respondida', 'resolvido' => 'fechada', 'aguardando' => 'aguardando');
        if (isset($revMap[$filterStatus])) {
            $whereThreads[] = "st.status = ?";
            $paramsThreads[] = $revMap[$filterStatus];
        }
    } elseif (!$showArquivados) {
        $whereThreads[] = "st.status != 'fechada'";
    }
    if ($search) {
        $whereThreads[] = "(st.assunto LIKE ? OR c.name LIKE ?)";
        $paramsThreads[] = '%' . $search . '%';
        $paramsThreads[] = '%' . $search . '%';
    }
    $whereStrThreads = $whereThreads ? 'WHERE ' . implode(' AND ', $whereThreads) : '';

    $stmt = $pdo->prepare(
        "SELECT st.id, st.assunto AS title, 'Central VIP' AS category, NULL AS department,
                'normal' AS priority, st.status AS status_raw,
                c.id AS client_id, c.name AS client_name, c.phone AS client_contact,
                st.processo_id AS case_id, cs.case_number, cs.title AS case_title,
                NULL AS due_date, NULL AS resolved_at, st.criado_em AS created_at, st.atualizado_em AS updated_at,
                'salavip' AS origem, NULL AS sla_prazo,
                c.name AS requester_name, NULL AS assignees,
                (SELECT COUNT(*) FROM salavip_mensagens WHERE thread_id = st.id) AS msg_count
         FROM salavip_threads st
         LEFT JOIN clients c ON c.id = st.cliente_id
         LEFT JOIN cases cs ON cs.id = st.processo_id
         $whereStrThreads
         ORDER BY FIELD(st.status, 'aberta','respondida','aguardando','fechada'), st.criado_em DESC
         LIMIT $hdPerPage OFFSET $hdOffset"
    );
    $stmt->execute($paramsThreads);
    $tickets = $stmt->fetchAll();
    try {
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM salavip_threads st LEFT JOIN clients c ON c.id = st.cliente_id $whereStrThreads");
        $stmtC->execute($paramsThreads);
        $hdTotalLista = (int)$stmtC->fetchColumn();
    } catch (Exception $e) { $hdTotalLista = count($tickets); }
    // Mapear status pra usar mesmos labels/badges dos tickets
    foreach ($tickets as &$t) { $t['status'] = $mapStatus[$t['status_raw']] ?? 'aberto'; }
    unset($t);
} else {
    $stmt = $pdo->prepare(
        "SELECT t.*, u.name as requester_name,
         GROUP_CONCAT(u2.name SEPARATOR ', ') as assignees,
         (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as msg_count
         FROM tickets t
         LEFT JOIN users u ON u.id = t.requester_id
         LEFT JOIN ticket_assignees ta ON ta.ticket_id = t.id
         LEFT JOIN users u2 ON u2.id = ta.user_id
         $whereStr
         GROUP BY t.id
         ORDER BY $orderBy
         LIMIT $hdPerPage OFFSET $hdOffset"
    );
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
    try {
        $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tickets t LEFT JOIN ticket_assignees ta ON ta.ticket_id = t.id $whereStr");
        $stmtC->execute($params);
        $hdTotalLista = (int)$stmtC->fetchColumn();
    } catch (Exception $e) { $hdTotalLista = count($tickets); }
}
$hdTotalPag = max(1, (int)ceil($hdTotalLista / $hdPerPage));
if ($hdPageNum > $hdTotalPag) { $hdPageNum = $hdTotalPag; $hdOffset = ($hdPageNum - 1) * $hdPerPage; }

// KPIs ─ dependem da aba selecionada (Nilce r7 31/05/2026):
// antes a query era so sobre tickets e mostrava "68 abertos" mesmo na aba
// Central VIP, confundindo a leitura. Agora cada aba tem sua propria base.
if ($filterOrigem === 'clientes') {
    $kpi = $pdo->query("SELECT
        SUM(CASE WHEN status IN ('aberta','respondida','aguardando') THEN 1 ELSE 0 END) as abertos,
        0 as urgentes,
        SUM(CASE WHEN status = 'fechada' AND MONTH(atualizado_em) = MONTH(NOW()) AND YEAR(atualizado_em) = YEAR(NOW()) THEN 1 ELSE 0 END) as resolvidos_mes
        FROM salavip_threads")->fetch();
    $kpiContexto = 'Central VIP';
} else {
    $kpi = $pdo->query("SELECT
        SUM(CASE WHEN status IN ('aberto','em_andamento','aguardando') THEN 1 ELSE 0 END) as abertos,
        SUM(CASE WHEN priority = 'urgente' AND status IN ('aberto','em_andamento') THEN 1 ELSE 0 END) as urgentes,
        SUM(CASE WHEN status = 'resolvido' AND MONTH(resolved_at) = MONTH(NOW()) THEN 1 ELSE 0 END) as resolvidos_mes
        FROM tickets WHERE (origem IS NULL OR origem != 'salavip')")->fetch();
    $kpiContexto = 'Chamados internos';
}

// ─── Dados para gráficos (Amanda 08/06/2026) ──────────────
// Respeitam aba origem (equipe vs Central VIP). Pegam TODOS chamados pra
// dar visão real de produtividade — nao filtram por status atual.
$grafDados = array(
    'status'      => array(),  // Distribuição por status (pizza)
    'prioridade'  => array(),  // Distribuição por prioridade (donut)
    'categoria'   => array(),  // Top categorias (barras)
    'resolvedores'=> array(),  // Ranking de quem resolveu mais (barras)
);
try {
    if ($filterOrigem === 'clientes') {
        // Status Central VIP (salavip_threads)
        $stG = $pdo->query("SELECT status, COUNT(*) as n FROM salavip_threads GROUP BY status ORDER BY n DESC");
        foreach ($stG as $r) $grafDados['status'][$r['status']] = (int)$r['n'];
        // Ranking de respondedores ultimos 60 dias (quem enviou mais msgs origem=conecta)
        $stR = $pdo->query("SELECT u.name as nome, COUNT(*) as n
            FROM salavip_mensagens m
            JOIN users u ON u.id = m.remetente_id
            WHERE m.origem = 'conecta' AND m.criado_em >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            GROUP BY m.remetente_id, u.name ORDER BY n DESC LIMIT 10");
        foreach ($stR as $r) $grafDados['resolvedores'][$r['nome']] = (int)$r['n'];
    } else {
        // Status chamados internos
        $stG = $pdo->query("SELECT status, COUNT(*) as n
            FROM tickets WHERE (origem IS NULL OR origem != 'salavip')
            GROUP BY status ORDER BY n DESC");
        foreach ($stG as $r) $grafDados['status'][$r['status']] = (int)$r['n'];
        // Prioridade (so abertos+em_andamento — o que importa agora)
        $stP = $pdo->query("SELECT priority, COUNT(*) as n
            FROM tickets WHERE (origem IS NULL OR origem != 'salavip')
              AND status IN ('aberto','em_andamento','aguardando')
            GROUP BY priority ORDER BY n DESC");
        foreach ($stP as $r) $grafDados['prioridade'][$r['priority']] = (int)$r['n'];
        // Categoria — top 8 (so abertos+em_andamento)
        $stC = $pdo->query("SELECT COALESCE(NULLIF(category,''),'(sem categoria)') as category, COUNT(*) as n
            FROM tickets WHERE (origem IS NULL OR origem != 'salavip')
              AND status IN ('aberto','em_andamento','aguardando')
            GROUP BY category ORDER BY n DESC LIMIT 8");
        foreach ($stC as $r) $grafDados['categoria'][$r['category']] = (int)$r['n'];
        // Ranking de quem resolveu mais nos ultimos 60 dias (top 10)
        $stR = $pdo->query("SELECT u.name as nome, COUNT(DISTINCT t.id) as n
            FROM tickets t
            JOIN ticket_assignees ta ON ta.ticket_id = t.id
            JOIN users u ON u.id = ta.user_id
            WHERE (t.origem IS NULL OR t.origem != 'salavip')
              AND t.status = 'resolvido'
              AND t.resolved_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            GROUP BY ta.user_id, u.name ORDER BY n DESC LIMIT 10");
        foreach ($stR as $r) $grafDados['resolvedores'][$r['nome']] = (int)$r['n'];
    }
} catch (Exception $eG) { /* silencioso */ }

$statusLabels = array('aberto' => 'Aberto', 'em_andamento' => 'Em andamento', 'aguardando' => 'Aguardando', 'resolvido' => 'Resolvido', 'cancelado' => 'Cancelado');
$statusBadge = array('aberto' => 'warning', 'em_andamento' => 'info', 'aguardando' => 'gestao', 'resolvido' => 'success', 'cancelado' => 'danger');
$statusIcons = array('aberto' => '🟡', 'em_andamento' => '🔵', 'aguardando' => '🟠', 'resolvido' => '✅', 'cancelado' => '❌');
$priorityBadge = array('urgente' => 'danger', 'normal' => 'gestao', 'baixa' => 'colaborador');

// Contadores por status (respeitando aba origem)
$statusCounts = array();
try {
    if ($filterOrigem === 'clientes') {
        // Conta salavip_threads mapeando status
        $scRows = $pdo->query("SELECT status, COUNT(*) as cnt FROM salavip_threads GROUP BY status")->fetchAll();
        $mapSC = array('aberta' => 'aberto', 'respondida' => 'em_andamento', 'aguardando' => 'aguardando', 'fechada' => 'resolvido');
        foreach ($scRows as $sc) {
            $k = $mapSC[$sc['status']] ?? 'aberto';
            $statusCounts[$k] = ($statusCounts[$k] ?? 0) + (int)$sc['cnt'];
        }
    } else {
        $scRows = $pdo->query("SELECT status, COUNT(*) as cnt FROM tickets WHERE (origem IS NULL OR origem != 'salavip') GROUP BY status")->fetchAll();
        foreach ($scRows as $sc) $statusCounts[$sc['status']] = (int)$sc['cnt'];
    }
} catch (Exception $e) {}
$totalTickets = array_sum($statusCounts);

// Contadores por origem (para badges nas abas)
$countEquipe = 0;
$countClientes = 0;
try {
    $countEquipe = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE (origem IS NULL OR origem != 'salavip') AND status NOT IN ('resolvido','cancelado')")->fetchColumn();
    $countClientes = (int)$pdo->query("SELECT COUNT(*) FROM salavip_threads WHERE status != 'fechada'")->fetchColumn();
} catch (Exception $e) {}

// Categorias existentes
$categorias = array();
try { $categorias = $pdo->query("SELECT DISTINCT category FROM tickets WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

// Usuários para filtro de responsável
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.hd-topbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1rem; }
.hd-filters { display:flex; flex-direction:column; gap:.6rem; margin-bottom:1.25rem; }
.hd-filter-row { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.hd-filter-label { font-size:.68rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; min-width:70px; }
.hd-pill { display:inline-flex; align-items:center; gap:4px; padding:5px 12px; border-radius:100px; font-size:.75rem; font-weight:500; border:1.5px solid var(--border); background:var(--bg-card); color:var(--text-muted); cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap; }
.hd-pill:hover { border-color:var(--petrol-300); color:var(--petrol-900); }
.hd-pill.active { background:var(--petrol-900); color:#fff; border-color:var(--petrol-900); }
.hd-pill .cnt { background:rgba(0,0,0,.1); padding:1px 6px; border-radius:100px; font-size:.65rem; font-weight:700; }
.hd-pill.active .cnt { background:rgba(255,255,255,.25); }
.hd-search { display:flex; gap:.4rem; align-items:center; }
.hd-search input { font-size:.82rem; padding:.45rem .75rem; border:1.5px solid var(--border); border-radius:var(--radius); width:220px; }
.hd-search input:focus { border-color:var(--rose); outline:none; }
</style>

<?php if ($hdCobCfg !== null):
    $_hdOn = ($hdCobCfg['ativo'] === '1');
?>
<!-- ⚙️ Cobrança de chamados parados (gestão+) -->
<details style="margin-bottom:1rem;border:1px solid var(--border);border-radius:10px;background:var(--bg-card);">
    <summary style="cursor:pointer;padding:.6rem .9rem;font-size:.8rem;font-weight:700;color:var(--petrol-900);display:flex;align-items:center;gap:.5rem;">
        ⏰ Cobrança de chamados parados
        <span style="font-size:.62rem;font-weight:700;padding:1px 8px;border-radius:9999px;background:<?= $_hdOn ? '#dcfce7' : '#f1f5f9' ?>;color:<?= $_hdOn ? '#166534' : '#64748b' ?>;"><?= $_hdOn ? 'LIGADA' : 'desligada' ?></span>
    </summary>
    <form method="POST" action="<?= module_url('helpdesk', 'api.php') ?>" style="padding:.3rem .9rem 1rem;display:flex;flex-direction:column;gap:.7rem;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="salvar_cobranca_cfg">
        <div style="font-size:.72rem;color:var(--text-muted);">Chamado aberto sem movimento há +N horas → notifica o(s) responsável(is) + resumo no grupo do WhatsApp. Roda em horário comercial (9h–18h, seg–sex).</div>
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;font-weight:600;cursor:pointer;">
            <input type="checkbox" name="ativo" value="1" <?= $_hdOn ? 'checked' : '' ?>> Ligar cobrança automática
        </label>
        <div style="display:flex;gap:.7rem;flex-wrap:wrap;">
            <label style="font-size:.72rem;font-weight:700;">Horas sem movimento<br>
                <input type="number" name="horas" min="1" max="720" value="<?= (int)$hdCobCfg['horas'] ?>" style="width:90px;padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:.82rem;"></label>
            <label style="font-size:.72rem;font-weight:700;" title="Não cobra chamados parados há mais que isso (evita spam de backlog antigo)">Só dos últimos (dias)<br>
                <input type="number" name="janela_dias" min="1" max="365" value="<?= (int)$hdCobCfg['janela_dias'] ?>" style="width:90px;padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:.82rem;"></label>
            <label style="font-size:.72rem;font-weight:700;">Canal do grupo<br>
                <select name="grupo_canal" style="padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:.82rem;">
                    <option value="24" <?= $hdCobCfg['grupo_canal'] === '24' ? 'selected' : '' ?>>24 (CX/Operacional)</option>
                    <option value="21" <?= $hdCobCfg['grupo_canal'] === '21' ? 'selected' : '' ?>>21 (Comercial)</option>
                </select></label>
            <label style="font-size:.72rem;font-weight:700;flex:1;min-width:220px;">ID do grupo WhatsApp (…@g.us)<br>
                <input type="text" name="grupo_id" value="<?= e($hdCobCfg['grupo_id']) ?>" placeholder="opcional — sem isso, só notifica no sino/push" style="width:100%;padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:.82rem;"></label>
        </div>
        <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Salvar</button>
            <a href="<?= url('cron/helpdesk_cobranca.php') ?>?key=fsa-hub-deploy-2026&dry=1&forcar=1" target="_blank" style="font-size:.72rem;color:#6b21a8;font-weight:700;text-decoration:none;">🧪 Simular (dry-run, não envia)</a>
            <span style="font-size:.66rem;color:var(--text-muted);">⚠️ Ativar também exige agendar o cron no cPanel (1×/hora).</span>
        </div>
    </form>
</details>
<?php endif; ?>

<!-- Abas Equipe vs Clientes -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:1rem;">
    <a href="<?= module_url('helpdesk') ?>?origem=equipe" style="padding:.7rem 1.4rem;font-size:.85rem;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $filterOrigem === 'equipe' ? '#B87333' : 'transparent' ?>;color:<?= $filterOrigem === 'equipe' ? '#B87333' : 'var(--text-muted)' ?>;margin-bottom:-2px;display:flex;align-items:center;gap:.4rem;">
        👥 Chamados Internos
        <?php if ($countEquipe > 0): ?>
            <span style="background:<?= $filterOrigem === 'equipe' ? '#B87333' : 'var(--text-muted)' ?>;color:#fff;padding:1px 8px;border-radius:9999px;font-size:.65rem;font-weight:700;"><?= $countEquipe ?></span>
        <?php endif; ?>
    </a>
    <a href="<?= module_url('helpdesk') ?>?origem=clientes" style="padding:.7rem 1.4rem;font-size:.85rem;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $filterOrigem === 'clientes' ? '#B87333' : 'transparent' ?>;color:<?= $filterOrigem === 'clientes' ? '#B87333' : 'var(--text-muted)' ?>;margin-bottom:-2px;display:flex;align-items:center;gap:.4rem;">
        🌟 Chamados de Clientes (Central VIP)
        <?php if ($countClientes > 0): ?>
            <span style="background:<?= $filterOrigem === 'clientes' ? '#B87333' : '#dc2626' ?>;color:#fff;padding:1px 8px;border-radius:9999px;font-size:.65rem;font-weight:700;"><?= $countClientes ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- Top bar -->
<div class="hd-topbar">
    <div style="display:flex;align-items:center;gap:1rem;">
        <div style="font-size:.82rem;color:var(--text-muted);"><?= $totalTickets ?> chamado<?= $totalTickets !== 1 ? 's' : '' ?></div>
        <?php if ($filterStatus || $filterPriority || $filterCategory || $filterAssignee || $search): ?>
            <a href="<?= module_url('helpdesk') ?>?origem=<?= e($filterOrigem) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Limpar filtros</a>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <?php
        // Amanda 10/06/2026: atalho rapido pra ver finalizados (resolvido + cancelado)
        $_finalCnt = ($statusCounts['resolvido'] ?? 0) + ($statusCounts['cancelado'] ?? 0);
        $_jaNoFinalizados = ($filterStatus === 'resolvido' || $filterStatus === 'cancelado');
        ?>
        <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'status'=>'resolvido','sort'=>'atualizado','dir'=>'desc'))) ?>"
           class="btn btn-sm"
           style="font-size:.75rem;background:<?= $_jaNoFinalizados ? '#10b981' : '#fff' ?>;color:<?= $_jaNoFinalizados ? '#fff' : '#059669' ?>;border:1.5px solid #10b981;font-weight:700;"
           title="Mostra chamados resolvidos (mais recentes primeiro)">
           ✅ Finalizados <span style="background:rgba(0,0,0,.15);padding:1px 7px;border-radius:8px;margin-left:4px;"><?= $_finalCnt ?></span>
        </a>
        <?php if ($showArquivados): ?>
            <a href="<?= module_url('helpdesk') ?>" class="btn btn-outline btn-sm" style="font-size:.75rem;">✕ Ocultar arquivados</a>
        <?php else: ?>
            <a href="<?= module_url('helpdesk') ?>?arquivados=1" class="btn btn-outline btn-sm" style="font-size:.75rem;">📦 Mostrar arquivados</a>
        <?php endif; ?>
        <a href="<?= module_url('helpdesk', 'novo.php') ?>" class="btn btn-primary btn-sm">+ Novo Chamado</a>
    </div>
</div>
<!-- Amanda 10/06/2026: timestamp pra debug do cache PWA (se os numeros nao mudam apos resolver, eh sw.js cacheando) -->
<div style="font-size:.7rem;color:#94a3b8;text-align:right;margin:-.5rem 0 .8rem;">
    📅 Atualizado em <strong><?= date('d/m/Y H:i:s') ?></strong>
    · <a href="javascript:location.reload(true)" style="color:#0ea5e9;text-decoration:underline;">🔄 forçar atualização</a>
</div>

<!-- Filtros -->
<div class="hd-filters">
    <!-- Status -->
    <div class="hd-filter-row">
        <span class="hd-filter-label">Status</span>
        <?php
        // Relatorio Nilce 31/05/2026: 'Todos 4' mostrava lista vazia porque
        // o default escondia resolvidos/cancelados. Agora pill 'Todos' envia
        // arquivados=1 explicitamente -> mostra TUDO, batendo com o contador.
        $_paramsTodos = array_filter(array('origem'=>$filterOrigem,'q'=>$search,'priority'=>$filterPriority,'category'=>$filterCategory,'assignee'=>$filterAssignee,'arquivados'=>'1'));
        ?>
        <a href="<?= module_url('helpdesk') ?>?<?= http_build_query($_paramsTodos) ?>" class="hd-pill <?= (!$filterStatus && $showArquivados) ? 'active' : '' ?>" title="Mostra TODOS os chamados (inclui resolvidos, cancelados e arquivados)">📦 Todos (c/ arquivados) <span class="cnt"><?= $totalTickets ?></span></a>
        <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'q'=>$search,'priority'=>$filterPriority,'category'=>$filterCategory,'assignee'=>$filterAssignee))) ?>" class="hd-pill <?= (!$filterStatus && !$showArquivados) ? 'active' : '' ?>" title="Esconde resolvidos e cancelados (padrão)">Ativos <span class="cnt"><?= ($totalTickets - ($statusCounts['resolvido'] ?? 0) - ($statusCounts['cancelado'] ?? 0)) ?></span></a>
        <?php foreach ($statusLabels as $sk => $sv): ?>
            <?php $cnt = $statusCounts[$sk] ?? 0; if ($cnt === 0 && $sk !== $filterStatus) continue; ?>
            <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'status'=>$sk,'q'=>$search,'priority'=>$filterPriority,'category'=>$filterCategory,'assignee'=>$filterAssignee))) ?>" class="hd-pill <?= $filterStatus === $sk ? 'active' : '' ?>"><?= $statusIcons[$sk] ?? '' ?> <?= $sv ?> <span class="cnt"><?= $cnt ?></span></a>
        <?php endforeach; ?>
    </div>

    <!-- Prioridade -->
    <div class="hd-filter-row">
        <span class="hd-filter-label">Prioridade</span>
        <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'status'=>$filterStatus,'q'=>$search,'category'=>$filterCategory,'assignee'=>$filterAssignee))) ?>" class="hd-pill <?= !$filterPriority ? 'active' : '' ?>">Todas</a>
        <?php foreach (array('urgente'=>'🔴 Urgente','normal'=>'🟢 Normal','baixa'=>'⚪ Baixa') as $pk => $pv): ?>
            <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'status'=>$filterStatus,'priority'=>$pk,'q'=>$search,'category'=>$filterCategory,'assignee'=>$filterAssignee))) ?>" class="hd-pill <?= $filterPriority === $pk ? 'active' : '' ?>"><?= $pv ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Categoria + Responsável + Busca -->
    <div class="hd-filter-row">
        <span class="hd-filter-label">Mais</span>
        <?php if (!empty($categorias)): ?>
        <select onchange="filtrarHelpdesk('category',this.value)" style="font-size:.78rem;padding:4px 8px;border:1.5px solid var(--border);border-radius:100px;background:var(--bg-card);cursor:pointer;">
            <option value="">Categoria</option>
            <?php foreach ($categorias as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select onchange="filtrarHelpdesk('assignee',this.value)" style="font-size:.78rem;padding:4px 8px;border:1.5px solid var(--border);border-radius:100px;background:var(--bg-card);cursor:pointer;">
            <option value="">Responsável</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filterAssignee === (int)$u['id'] ? 'selected' : '' ?>><?= e(explode(' ',$u['name'])[0]) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="hd-search">
            <form method="GET">
                <input type="hidden" name="origem" value="<?= e($filterOrigem) ?>">
                <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
                <?php if ($filterPriority): ?><input type="hidden" name="priority" value="<?= e($filterPriority) ?>"><?php endif; ?>
                <?php if ($filterCategory): ?><input type="hidden" name="category" value="<?= e($filterCategory) ?>"><?php endif; ?>
                <?php if ($filterAssignee): ?><input type="hidden" name="assignee" value="<?= $filterAssignee ?>"><?php endif; ?>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar título, cliente, processo...">
                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;">🔍</button>
            </form>
        </div>
    </div>
</div>

<!-- KPIs rapidos (refletem a aba selecionada — Nilce r7) -->
<div style="font-size:.65rem;color:#64748b;margin:0 0 .3rem;font-weight:600;letter-spacing:.3px;text-transform:uppercase;">
    Panorama da aba <strong style="color:#B87333;"><?= e($kpiContexto) ?></strong>:
</div>
<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:.5rem 1rem;display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1.1rem;">🎫</span>
        <div><div style="font-size:1.2rem;font-weight:800;color:var(--petrol-900);"><?= $kpi['abertos'] ?? 0 ?></div><div style="font-size:.65rem;color:var(--text-muted);">Abertos</div></div>
    </div>
    <?php if (($kpi['urgentes'] ?? 0) > 0): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius);padding:.5rem 1rem;display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1.1rem;">🔴</span>
        <div><div style="font-size:1.2rem;font-weight:800;color:#dc2626;"><?= $kpi['urgentes'] ?></div><div style="font-size:.65rem;color:#dc2626;">Urgentes</div></div>
    </div>
    <?php endif; ?>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:.5rem 1rem;display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1.1rem;">✅</span>
        <div><div style="font-size:1.2rem;font-weight:800;color:#059669;"><?= $kpi['resolvidos_mes'] ?? 0 ?></div><div style="font-size:.65rem;color:var(--text-muted);">Resolvidos mês</div></div>
    </div>
    <button type="button" onclick="hdToggleGraficos()" id="hdBtnGraf" style="margin-left:auto;background:#fff;border:1.5px solid #B87333;color:#B87333;border-radius:8px;padding:.4rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;">📊 Ver gráficos</button>
</div>

<!-- Seção de gráficos colapsável (Amanda 08/06/2026 - ajustado 10/06) -->
<div id="hdSecaoGraficos" style="display:none;background:#fff;border:1px solid var(--border);border-radius:10px;padding:1rem;margin-bottom:1rem;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem;">
        <div id="boxGrafStatus" style="background:#fafafa;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;<?= empty($grafDados['status']) ? 'display:none;' : '' ?>">
            <h4 style="margin:0 0 .5rem;font-size:.82rem;color:var(--petrol-900);">📊 Distribuição por status</h4>
            <div style="height:240px;position:relative;"><canvas id="grafStatus"></canvas></div>
        </div>
        <div id="boxGrafPrior" style="background:#fafafa;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;<?= empty($grafDados['prioridade']) ? 'display:none;' : '' ?>">
            <h4 style="margin:0 0 .5rem;font-size:.82rem;color:var(--petrol-900);">🎯 Prioridade dos abertos</h4>
            <div style="height:240px;position:relative;"><canvas id="grafPrior"></canvas></div>
        </div>
        <div id="boxGrafCat" style="background:#fafafa;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;<?= empty($grafDados['categoria']) ? 'display:none;' : '' ?>">
            <h4 style="margin:0 0 .5rem;font-size:.82rem;color:var(--petrol-900);">📂 Categorias dos pendentes</h4>
            <div style="height:240px;position:relative;"><canvas id="grafCat"></canvas></div>
        </div>
        <div id="boxGrafResolv" style="background:#fafafa;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;<?= empty($grafDados['resolvedores']) ? 'display:none;' : '' ?>">
            <h4 style="margin:0 0 .5rem;font-size:.82rem;color:var(--petrol-900);">🏆 Top 10 — quem resolveu mais (últimos 60 dias)</h4>
            <div style="height:280px;position:relative;"><canvas id="grafResolv"></canvas></div>
        </div>
    </div>
</div>

<script>
window.hdGrafDados = {
    status: <?= json_encode($grafDados['status'], JSON_UNESCAPED_UNICODE) ?>,
    prioridade: <?= json_encode($grafDados['prioridade'], JSON_UNESCAPED_UNICODE) ?>,
    categoria: <?= json_encode($grafDados['categoria'], JSON_UNESCAPED_UNICODE) ?>,
    resolvedores: <?= json_encode($grafDados['resolvedores'], JSON_UNESCAPED_UNICODE) ?>
};
window.hdGrafInstancias = {};
window.hdGrafRenderizado = false;

function hdToggleGraficos() {
    var sec = document.getElementById('hdSecaoGraficos');
    var btn = document.getElementById('hdBtnGraf');
    if (sec.style.display === 'none' || !sec.style.display) {
        sec.style.display = 'block';
        btn.innerHTML = '🔼 Ocultar gráficos';
        if (!window.hdGrafRenderizado) hdRenderGraficos();
    } else {
        sec.style.display = 'none';
        btn.innerHTML = '📊 Ver gráficos';
    }
}

function hdRenderGraficos() {
    var sec = document.getElementById('hdSecaoGraficos');
    var d = window.hdGrafDados;
    var temDados = d && (Object.keys(d.status||{}).length || Object.keys(d.prioridade||{}).length || Object.keys(d.categoria||{}).length || Object.keys(d.resolvedores||{}).length);
    if (!temDados) {
        sec.innerHTML = '<div style="padding:1.2rem;text-align:center;color:#94a3b8;font-size:.85rem;">📊 Ainda não há dados suficientes nesta aba pra gerar gráficos.<br><span style="font-size:.75rem;">Resolva alguns chamados nos últimos 60 dias e os gráficos vão aparecer.</span></div>';
        return;
    }
    if (typeof Chart === 'undefined') {
        // Indicador "carregando..."
        sec.insertAdjacentHTML('afterbegin', '<div id="hdGrafLoading" style="padding:.6rem;text-align:center;color:#0ea5e9;font-size:.78rem;">⏳ Carregando biblioteca de gráficos...</div>');
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
        s.onload = function(){
            var ld = document.getElementById('hdGrafLoading'); if (ld) ld.remove();
            hdDesenharTodos();
        };
        s.onerror = function(){
            var ld = document.getElementById('hdGrafLoading');
            if (ld) ld.innerHTML = '⚠️ Não foi possível carregar a biblioteca de gráficos (CDN bloqueado pela rede do escritório?). Tente recarregar a página ou peça pra TI liberar <code>cdn.jsdelivr.net</code>.';
            if (ld) ld.style.color = '#dc2626';
        };
        document.head.appendChild(s);
    } else {
        hdDesenharTodos();
    }
}

function hdDesenharTodos() {
    window.hdGrafRenderizado = true;
    var d = window.hdGrafDados;
    var statusLabels = {aberto:'🟡 Aberto', em_andamento:'🔵 Em andamento', aguardando:'🟠 Aguardando', resolvido:'✅ Resolvido', cancelado:'❌ Cancelado',
                        aberta:'🟡 Aberta', respondida:'✅ Respondida', fechada:'⚫ Fechada'};
    var statusColors = {aberto:'#f59e0b', em_andamento:'#0ea5e9', aguardando:'#fb923c', resolvido:'#10b981', cancelado:'#dc2626',
                        aberta:'#f59e0b', respondida:'#10b981', fechada:'#6b7280'};
    var priorLabels = {urgente:'🔴 Urgente', normal:'🟢 Normal', baixa:'⚪ Baixa'};
    var priorColors = {urgente:'#dc2626', normal:'#10b981', baixa:'#94a3b8'};

    // Status (pizza)
    var cStatus = document.getElementById('grafStatus');
    if (cStatus && Object.keys(d.status).length) {
        var ks = Object.keys(d.status);
        window.hdGrafInstancias.status = new Chart(cStatus.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ks.map(function(k){ return (statusLabels[k] || k) + ' (' + d.status[k] + ')'; }),
                datasets: [{ data: ks.map(function(k){ return d.status[k]; }), backgroundColor: ks.map(function(k){ return statusColors[k] || '#9ca3af'; }), borderWidth:2, borderColor:'#fff' }]
            },
            options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'right', labels:{font:{size:11}, boxWidth:12}}}}
        });
    }

    // Prioridade
    var cPrior = document.getElementById('grafPrior');
    if (cPrior && Object.keys(d.prioridade).length) {
        var kp = Object.keys(d.prioridade);
        window.hdGrafInstancias.prior = new Chart(cPrior.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: kp.map(function(k){ return (priorLabels[k] || k) + ' (' + d.prioridade[k] + ')'; }),
                datasets: [{ data: kp.map(function(k){ return d.prioridade[k]; }), backgroundColor: kp.map(function(k){ return priorColors[k] || '#9ca3af'; }), borderWidth:2, borderColor:'#fff' }]
            },
            options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'right', labels:{font:{size:11}, boxWidth:12}}}}
        });
    }

    // Categorias (barras horizontal)
    var cCat = document.getElementById('grafCat');
    if (cCat && Object.keys(d.categoria).length) {
        var kc = Object.keys(d.categoria);
        window.hdGrafInstancias.cat = new Chart(cCat.getContext('2d'), {
            type: 'bar',
            data: {
                labels: kc,
                datasets: [{ label:'Pendentes', data: kc.map(function(k){ return d.categoria[k]; }), backgroundColor:'#3B4FA0', borderRadius:6 }]
            },
            options: { responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{ legend:{display:false}}, scales:{x:{beginAtZero:true, ticks:{precision:0}}}}
        });
    }

    // Ranking de resolvedores (barras)
    var cR = document.getElementById('grafResolv');
    if (cR && Object.keys(d.resolvedores).length) {
        var kr = Object.keys(d.resolvedores);
        window.hdGrafInstancias.resolv = new Chart(cR.getContext('2d'), {
            type: 'bar',
            data: {
                labels: kr.map(function(n){ return n.split(' ').slice(0,2).join(' '); }),
                datasets: [{ label:'Resolvidos', data: kr.map(function(k){ return d.resolvedores[k]; }), backgroundColor:'#B87333', borderRadius:6 }]
            },
            options: { responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{ legend:{display:false}, tooltip:{callbacks:{label:function(ctx){ return ctx.parsed.x + ' chamado(s) resolvido(s)';}}}}, scales:{x:{beginAtZero:true, ticks:{precision:0}}}}
        });
    }
}
</script>

<script>
function filtrarHelpdesk(param, value) {
    var url = new URL(window.location.href);
    if (value) url.searchParams.set(param, value);
    else url.searchParams.delete(param);
    window.location.href = url.toString();
}
</script>

<!-- Helper paginacao (topo + rodape) -->
<?php
$_hdPag = function($pos) use ($hdPageNum, $hdTotalPag, $hdTotalLista, $hdPerPage, $hdOffset) {
    if ($hdTotalLista === 0) return;
    $qsBase = $_GET;
    $ini = $hdTotalLista ? ($hdOffset + 1) : 0;
    $fim = min($hdOffset + $hdPerPage, $hdTotalLista);
    $css = 'padding:5px 10px;border-radius:5px;text-decoration:none;font-size:.74rem;font-weight:600;border:1px solid var(--border);';
    $mb  = $pos === 'topo' ? 'margin:.2rem 0 .6rem;' : 'margin:.8rem 0 .2rem;';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap;'.$mb.'">';
    echo '<span style="font-size:.74rem;color:#475569;">Mostrando <strong>'.$ini.'–'.$fim.'</strong> de <strong>'.$hdTotalLista.'</strong>';
    if ($hdTotalPag > 1) echo ' <span style="color:#0f7c66;font-weight:700;">(página '.$hdPageNum.' de '.$hdTotalPag.')</span>';
    echo '</span>';
    if ($hdTotalPag > 1) {
        echo '<div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">';
        $qsBase['page'] = max(1, $hdPageNum - 1);
        $offPrev = $hdPageNum === 1 ? 'opacity:.4;pointer-events:none;' : '';
        echo '<a href="?'.htmlspecialchars(http_build_query($qsBase)).'" style="'.$css.'background:#fff;color:#052228;'.$offPrev.'" title="Página anterior">« Anterior</a>';
        for ($p = 1; $p <= $hdTotalPag; $p++) {
            if ($p > 1 && $p < $hdTotalPag && abs($p - $hdPageNum) > 2) continue;
            $qsBase['page'] = $p;
            $ativo = $p === $hdPageNum ? 'background:#052228;color:#fff;' : 'background:#fff;color:#052228;';
            echo '<a href="?'.htmlspecialchars(http_build_query($qsBase)).'" style="'.$css.$ativo.'">'.$p.'</a>';
        }
        $qsBase['page'] = min($hdTotalPag, $hdPageNum + 1);
        $offNext = $hdPageNum === $hdTotalPag ? 'opacity:.4;pointer-events:none;' : '';
        echo '<a href="?'.htmlspecialchars(http_build_query($qsBase)).'" style="'.$css.'background:#fff;color:#052228;'.$offNext.'" title="Próxima página">Próxima »</a>';
        echo '</div>';
    }
    echo '</div>';
};
?>

<!-- Lista -->
<div class="card">
    <div class="table-wrapper">
        <?php $_hdPag('topo'); ?>
        <table>
            <thead><tr>
                <?php
                function sortLink($col, $label, $currentSort, $currentDir, $params) {
                    $newDir = ($currentSort === $col && $currentDir === 'ASC') ? 'desc' : 'asc';
                    $arrow = '';
                    if ($currentSort === $col) $arrow = $currentDir === 'ASC' ? ' ▲' : ' ▼';
                    $p = array_merge($params, array('sort' => $col, 'dir' => $newDir));
                    return '<a href="?' . http_build_query(array_filter($p)) . '" style="color:inherit;text-decoration:none;white-space:nowrap;">' . $label . $arrow . '</a>';
                }
                $sp = array('status'=>$filterStatus,'priority'=>$filterPriority,'q'=>$search,'category'=>$filterCategory,'assignee'=>$filterAssignee);
                ?>
                <th><?= sortLink('id','#',$sortParam,$sortDir,$sp) ?></th>
                <th><?= sortLink('titulo','Título',$sortParam,$sortDir,$sp) ?></th>
                <th>Categoria</th>
                <th><?= sortLink('prioridade','Prioridade',$sortParam,$sortDir,$sp) ?></th>
                <th><?= sortLink('status','Status',$sortParam,$sortDir,$sp) ?></th>
                <th>Solicitante</th>
                <th>Responsáveis</th>
                <th>Msgs</th>
                <th><?= sortLink('data','Abertura',$sortParam,$sortDir,$sp) ?></th>
                <th><?= sortLink('atualizado','Atualizado',$sortParam,$sortDir,$sp) ?></th>
            </tr></thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr><td colspan="10" class="text-center text-muted" style="padding:2rem;">Nenhum chamado encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($tickets as $t):
                        // Badge "NOVO" pra chamados criados nas ultimas 24h — chama atencao
                        $_ehNovo = !empty($t['created_at']) && (time() - strtotime($t['created_at'])) < 86400;
                    ?>
                    <tr<?= $_ehNovo ? ' style="background:rgba(251,191,36,.08);"' : '' ?>>
                        <td class="text-sm text-muted"><?= $t['id'] ?></td>
                        <td>
                            <?php if (!empty($t['pinned'])): ?><span title="Fixado no topo" style="margin-right:3px;">📌</span><?php endif; ?>
                            <?php if ($_ehNovo): ?><span style="background:#fbbf24;color:#78350f;font-size:.6rem;font-weight:800;padding:1px 5px;border-radius:3px;margin-right:5px;letter-spacing:.5px;">🆕 NOVO</span><?php endif; ?>
                            <a href="<?= ($t['origem'] ?? '') === 'salavip' ? url('modules/salavip/ver_mensagem.php?thread_id=' . $t['id']) : module_url('helpdesk', 'ver.php?id=' . $t['id']) ?>" class="font-bold" style="color:var(--petrol-900);"><?= e($t['title']) ?></a>
                        </td>
                        <td class="text-sm"><?= e($t['category'] ?: '—') ?></td>
                        <td><span class="badge badge-<?= $priorityBadge[$t['priority']] ?? 'gestao' ?>"><?= e($t['priority']) ?></span></td>
                        <td><span class="badge badge-<?= $statusBadge[$t['status']] ?? 'gestao' ?>"><?= $statusLabels[$t['status']] ?? $t['status'] ?></span></td>
                        <td class="text-sm"><?= e($t['requester_name'] ?? '—') ?></td>
                        <td class="text-sm"><?= e($t['assignees'] ?: '—') ?></td>
                        <td class="text-sm text-center"><?= $t['msg_count'] ?></td>
                        <td class="text-sm text-muted"><?= data_br($t['created_at']) ?></td>
                        <td class="text-sm text-muted"><?= data_br($t['updated_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php $_hdPag('rodape'); ?>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
