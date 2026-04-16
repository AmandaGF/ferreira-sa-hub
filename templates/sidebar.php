<?php
/**
 * Sidebar — Menu lateral com navegação baseada em roles
 * Suporte: colapsar (só ícones) + modo noturno
 */
$user = current_user();
$userRole = $user['role'] ?? 'colaborador';
$userInitials = mb_substr($user['name'] ?? '?', 0, 2, 'UTF-8');

// Contar mensagens não lidas da Central VIP
$_svMsgsNaoLidas = 0;
try {
    $_svMsgsNaoLidas = (int)db()->query("SELECT COUNT(*) FROM salavip_mensagens WHERE origem='salavip' AND lida_equipe=0")->fetchColumn();
} catch (Exception $e) {}

$all = array('admin','gestao','comercial','cx','operacional','estagiario','colaborador');
$equipe = array('admin','gestao','comercial','cx','operacional','estagiario'); // todos exceto colaborador
$menuItems = array(
    array('section' => 'Principal'),
    array('label' => 'Dashboard',       'icon' => '📊', 'href' => url('modules/dashboard/'),       'id' => 'dashboard',       'roles' => $all),
    array('label' => 'Portal de Links', 'icon' => '🔗', 'href' => url('modules/portal/'),          'id' => 'portal',          'roles' => $all),

    array('section' => 'Atendimento'),
    array('label' => 'Helpdesk',        'icon' => '🎫', 'href' => url('modules/helpdesk/'),        'id' => 'helpdesk',        'roles' => $all),

    array('label' => 'Agenda',          'icon' => '📅', 'href' => url('modules/agenda/'),           'id' => 'agenda',          'roles' => $all),

    array('section' => '💼 Comercial'),
    array('label' => 'CRM',             'icon' => '🎯', 'href' => url('modules/crm/'),             'id' => 'crm',             'roles' => $equipe),
    array('label' => 'Kanban Comercial','icon' => '📈', 'href' => url('modules/pipeline/'),         'id' => 'pipeline',        'roles' => $equipe),

    array('section' => '⚙️ Operacional'),
    array('label' => 'Kanban Operacional','icon' => '📋', 'href' => url('modules/operacional/'),    'id' => 'operacional',     'roles' => $equipe),
    array('label' => 'Kanban PREV',    'icon' => '🏛️', 'href' => url('modules/prev/'),             'id' => 'prev',            'roles' => $all),
    array('label' => 'Processos',       'icon' => '⚖️', 'href' => url('modules/processos/'),       'id' => 'processos',       'roles' => $equipe),
    array('label' => 'Tarefas',         'icon' => '✅', 'href' => url('modules/tarefas/'),        'id' => 'tarefas',         'roles' => array('admin','gestao','operacional')),
    array('label' => 'Calc. Prazos',    'icon' => '📅', 'href' => url('modules/operacional/prazos_calc.php'), 'id' => 'prazos_calc', 'roles' => $equipe),
    array('label' => 'Extrajudicial',   'icon' => '📝', 'href' => url('modules/servicos/'),         'id' => 'servicos',        'roles' => $equipe),
    array('label' => 'Pré-Processual',  'icon' => '📂', 'href' => url('modules/pre_processual/'),  'id' => 'pre_processual',  'roles' => $equipe),
    array('label' => 'Fáb. Petições',  'icon' => '📝', 'href' => url('modules/peticoes/'),         'id' => 'peticoes',        'roles' => $equipe),
    array('label' => 'Planilha Débito','icon' => '📊', 'href' => url('modules/planilha_debito/'), 'id' => 'planilha_debito', 'roles' => $equipe),

    array('section' => '📇 Cadastros'),
    array('label' => 'Agenda de Contatos','icon' => '👥', 'href' => url('modules/clientes/'),       'id' => 'clientes',        'roles' => $all),

    array('section' => '💰 Financeiro'),
    array('label' => 'Financeiro',      'icon' => '💰', 'href' => url('modules/financeiro/'),       'id' => 'financeiro',      'roles' => array('admin','gestao','comercial')),
    array('label' => 'Cobrança Honor.', 'icon' => '⚠️', 'href' => url('modules/cobranca_honorarios/'), 'id' => 'cobranca_honorarios', 'roles' => array('admin','gestao')),

    array('section' => 'Controle'),
    array('label' => 'Prazos',          'icon' => '⏰', 'href' => url('modules/prazos/'),           'id' => 'prazos',          'roles' => $equipe),
    array('label' => 'Ofícios',         'icon' => '📬', 'href' => url('modules/oficios/'),          'id' => 'oficios',         'roles' => $equipe),
    array('label' => 'Alvarás',         'icon' => '💰', 'href' => url('modules/alvaras/'),          'id' => 'alvaras',         'roles' => $equipe),
    array('label' => 'Parceiros',       'icon' => '🤝', 'href' => url('modules/parceiros/'),        'id' => 'parceiros',       'roles' => array('admin','gestao')),

    array('section' => 'Dados'),
    array('label' => 'Documentos',      'icon' => '📜', 'href' => url('modules/documentos/'),      'id' => 'documentos',      'roles' => $equipe),
    array('label' => 'Formulários',     'icon' => '📋', 'href' => url('modules/formularios/'),      'id' => 'formularios',     'roles' => array('admin','gestao')),
    array('label' => 'Relatórios',      'icon' => '📉', 'href' => url('modules/relatorios/'),       'id' => 'relatorios',      'roles' => array('admin','gestao')),
    array('label' => 'Planilha',        'icon' => '📊', 'href' => url('modules/planilha/'),         'id' => 'planilha',        'roles' => $equipe),

    array('section' => 'Comunicação'),
    array('label' => 'Mensagens',       'icon' => '💬', 'href' => url('modules/mensagens/'),        'id' => 'mensagens',       'roles' => $all),
    array('label' => 'Notificações',    'icon' => '🔔', 'href' => url('modules/notificacoes/'),     'id' => 'notificacoes',    'roles' => $all),
    array('label' => 'Notif. Clientes', 'icon' => '📲', 'href' => url('modules/notificacoes/log_cliente.php'), 'id' => 'notif_clientes', 'roles' => $equipe),
    array('label' => 'Newsletter',     'icon' => '📧', 'href' => url('modules/newsletter/'),        'id' => 'newsletter',      'roles' => array('admin','gestao')),
    array('label' => 'Aniversariantes', 'icon' => '🎂', 'href' => url('modules/aniversarios/'),     'id' => 'aniversarios',    'roles' => $all),

    array('section' => 'Equipe'),
    array('label' => 'Ranking',         'icon' => '🏆', 'href' => url('modules/gamificacao/'),      'id' => 'gamificacao',     'roles' => $all),

    array('section' => '🌟 Central VIP F&S'),
    array('label' => 'Central VIP',     'icon' => '🌟', 'href' => url('modules/salavip/'),            'id' => 'salavip',         'roles' => $all),
    array('label' => 'GED (Docs)',      'icon' => '📁', 'href' => url('modules/salavip/ged.php'),      'id' => 'salavip_ged',     'roles' => $all),
    array('label' => 'Acessos',         'icon' => '🔑', 'href' => url('modules/salavip/acessos.php'),  'id' => 'salavip_acessos', 'roles' => array('admin','gestao')),
    array('label' => 'FAQ',             'icon' => '❓', 'href' => url('modules/salavip/faq_admin.php'), 'id' => 'salavip_faq',     'roles' => array('admin','gestao')),
    array('label' => 'Log Acessos',     'icon' => '📋', 'href' => url('modules/salavip/log.php'),      'id' => 'salavip_log',     'roles' => array('admin','gestao')),

    array('section' => 'Sistema'),
    array('label' => 'Treinamento',     'icon' => '🎓', 'href' => url('modules/treinamento/'),      'id' => 'treinamento',     'roles' => $all),
    array('label' => 'Usuários',        'icon' => '🛡️', 'href' => url('modules/usuarios/'),        'id' => 'usuarios',        'roles' => array('admin')),
    array('label' => 'Permissões',     'icon' => '🔐', 'href' => url('modules/admin/permissoes.php'), 'id' => 'permissoes',   'roles' => array('admin')),
    array('label' => 'DataJud',         'icon' => '🔄', 'href' => url('modules/admin/datajud_monitor.php'), 'id' => 'datajud',  'roles' => array('admin','gestao')),
    array('label' => 'Importar DJen',   'icon' => '📢', 'href' => url('modules/admin/djen_importar.php'),  'id' => 'djen_importar', 'roles' => array('admin','gestao','operacional')),
    array('label' => 'Importar Endereços', 'icon' => '📍', 'href' => url('modules/admin/importar_enderecos.php'), 'id' => 'importar_enderecos', 'roles' => array('admin','gestao')),
    array('label' => 'Health Check',    'icon' => '🩺', 'href' => url('modules/admin/health.php'),  'id' => 'admin',           'roles' => array('admin')),
);
?>

<style>
/* Sidebar colapsável */
.sidebar.collapsed { width:60px !important; }
.sidebar.collapsed .sidebar-brand-text,
.sidebar.collapsed .sidebar-section,
.sidebar.collapsed .sidebar-link span:not(.icon),
.sidebar.collapsed .user-info,
.sidebar.collapsed .sidebar-controls span { display:none !important; }
.sidebar.collapsed .sidebar-link { padding:.6rem .7rem;justify-content:center; }
.sidebar.collapsed .sidebar-link .icon { margin:0;font-size:1.1rem; }
.sidebar.collapsed .sidebar-brand { padding:.8rem .5rem;justify-content:center; }
.sidebar.collapsed .sidebar-brand img { width:30px !important;height:30px !important; }
.sidebar.collapsed .sidebar-footer { padding:.5rem;flex-direction:column;gap:.3rem; }
.sidebar.collapsed .btn-logout { font-size:.7rem; }
.sidebar.collapsed + .app-layout { margin-left:60px !important; }
.sidebar.collapsed .sidebar-controls { flex-direction:column;padding:.3rem; }
.sidebar.collapsed .sidebar-controls button { padding:4px;font-size:.85rem; }
/* Controles do sidebar */
.sidebar-controls { display:flex;gap:.25rem;padding:.4rem .8rem;border-top:1px solid rgba(255,255,255,.1); }
.sidebar-controls button { background:rgba(255,255,255,.08);border:none;color:rgba(255,255,255,.7);padding:4px 8px;border-radius:6px;cursor:pointer;font-size:.75rem;flex:1;transition:all .2s; }
.sidebar-controls button:hover { background:rgba(255,255,255,.15);color:#fff; }
/* Dark mode */
body.dark-mode { --bg:#1a1a2e;--bg-card:#16213e;--bg-secondary:#0f3460;--text:#e0e0e0;--text-muted:#8899aa;--text-secondary:#7788aa;--border:#2a3a5e;--shadow-sm:0 1px 3px rgba(0,0,0,.4);--shadow-md:0 4px 12px rgba(0,0,0,.5); }
body.dark-mode .topbar { background:var(--bg-card) !important;border-color:var(--border) !important; }
body.dark-mode .card,.dark-mode .stat-card { background:var(--bg-card) !important;border-color:var(--border) !important;color:var(--text) !important; }
body.dark-mode .card-header { background:var(--bg-secondary) !important;border-color:var(--border) !important; }
body.dark-mode .page-content { background:var(--bg) !important; }
body.dark-mode .main-content { background:var(--bg) !important; }
body.dark-mode h1,body.dark-mode h2,body.dark-mode h3,body.dark-mode .topbar-title { color:var(--text) !important; }
body.dark-mode .form-input,body.dark-mode .form-select,body.dark-mode select,body.dark-mode input,body.dark-mode textarea { background:var(--bg-secondary) !important;color:var(--text) !important;border-color:var(--border) !important; }
body.dark-mode table th { background:var(--bg-secondary) !important; }
body.dark-mode table td { background:var(--bg-card) !important;color:var(--text) !important;border-color:var(--border) !important; }
body.dark-mode table tr:nth-child(even) td { background:rgba(255,255,255,.03) !important; }
body.dark-mode table tr:hover td { background:rgba(215,171,144,.1) !important; }
body.dark-mode .tbl-toolbar { background:var(--bg-card) !important;border-color:var(--border) !important; }
body.dark-mode .tbl-grid th { background:linear-gradient(180deg,#1a2744,#16213e) !important; }
body.dark-mode .tbl-grid td { border-color:var(--border) !important; }
body.dark-mode .btn-outline { color:var(--text) !important;border-color:var(--border) !important; }
body.dark-mode .lead-card,.dark-mode .op-card { background:var(--bg-card) !important;border-color:var(--border) !important; }
body.dark-mode .lead-name,.dark-mode .op-card-name { color:var(--text) !important; }
body.dark-mode .kanban-body,.dark-mode .op-col-body { background:var(--bg) !important;border-color:var(--border) !important; }
body.dark-mode .pipeline-stats .stat-card,.dark-mode .op-kpi { background:var(--bg-card) !important;border-color:var(--border) !important; }
body.dark-mode a { color:var(--rose); }
</style>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="<?= url('assets/img/logo-sidebar.png') ?>" alt="Logo" style="width:38px;height:38px;border-radius:10px;object-fit:cover;" onerror="this.style.display='none'">
        <div class="sidebar-brand-text">
            <h2>Ferreira &amp; Sá</h2>
            <small>Hub</small>
        </div>
    </div>

    <nav class="sidebar-nav" id="sidebarNav">
        <?php
        // Processar os itens em seções agrupadas (para colapsar)
        $sectionIdx = -1;
        $sectionGroups = array(); // [ ['name' => X, 'items' => []], ... ]
        foreach ($menuItems as $item) {
            if (isset($item['section'])) {
                $sectionIdx++;
                $sectionGroups[$sectionIdx] = array('name' => $item['section'], 'items' => array());
            } else {
                if ($sectionIdx < 0) { $sectionIdx = 0; $sectionGroups[0] = array('name' => 'Menu', 'items' => array()); }
                // Verificar permissão
                $showItem = false;
                if (function_exists('can_access') && isset($item['id'])) {
                    $defaults = _permission_defaults();
                    $showItem = isset($defaults[$item['id']]) ? can_access($item['id']) : in_array($userRole, $item['roles'], true);
                } else {
                    $showItem = in_array($userRole, $item['roles'], true);
                }
                if ($showItem) {
                    $sectionGroups[$sectionIdx]['items'][] = $item;
                }
            }
        }
        // Renderizar cada seção colapsável (pular seções vazias por permissão)
        foreach ($sectionGroups as $si => $group):
            if (empty($group['items'])) continue;
            $sectionSlug = 'sec_' . $si;
        ?>
            <div class="sidebar-section sidebar-section-header" data-section="<?= $sectionSlug ?>" onclick="toggleSidebarSection('<?= $sectionSlug ?>')">
                <span><?= e($group['name']) ?></span>
                <span class="sidebar-section-chevron" id="chv_<?= $sectionSlug ?>">▾</span>
            </div>
            <div class="sidebar-section-items" id="items_<?= $sectionSlug ?>">
                <?php foreach ($group['items'] as $item): ?>
                    <div class="sidebar-item-row">
                        <a href="<?= $item['href'] ?>"
                           class="sidebar-link <?= is_current_module($item['id']) ? 'active' : '' ?>"
                           title="<?= e($item['label']) ?>">
                            <span class="icon"><?= $item['icon'] ?></span>
                            <span class="sidebar-link-label"><?= e($item['label']) ?></span>
                            <?php if ($item['id'] === 'salavip' && $_svMsgsNaoLidas > 0): ?>
                                <span style="background:#dc2626;color:#fff;font-size:.6rem;padding:1px 5px;border-radius:9px;margin-left:auto;font-weight:700;"><?= $_svMsgsNaoLidas ?></span>
                            <?php endif; ?>
                        </a>
                        <button type="button" class="sidebar-fav-btn" data-fav-id="<?= e($item['id']) ?>" data-fav-label="<?= e($item['label']) ?>" data-fav-icon="<?= e($item['icon']) ?>" data-fav-href="<?= e($item['href']) ?>" onclick="toggleFavorito(this, event)" title="Fixar nos favoritos">☆</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <!-- Controles: Colapsar + Dark Mode -->
    <div class="sidebar-controls">
        <button onclick="toggleSidebarCollapse()" title="Recolher menu" id="btnCollapse">
            <span>◀ Recolher</span>
        </button>
        <button onclick="toggleDarkMode()" title="Modo noturno" id="btnDarkMode">
            🌙
        </button>
    </div>

    <div class="sidebar-footer">
        <div class="user-avatar"><?= e(mb_strtoupper($userInitials)) ?></div>
        <div class="user-info">
            <div class="user-name"><?= e($user['name'] ?? '') ?></div>
            <div class="user-role"><?= role_label($userRole) ?></div>
        </div>
        <a href="<?= url('auth/logout.php') ?>" class="btn-logout" title="Sair">⏻</a>
    </div>
</aside>

<style>
/* Seções colapsáveis */
.sidebar-section-header { cursor:pointer; display:flex; align-items:center; justify-content:space-between; user-select:none; transition:background .15s; }
.sidebar-section-header:hover { background:rgba(255,255,255,.05); }
.sidebar-section-chevron { font-size:.65rem; transition:transform .2s; opacity:.6; }
.sidebar-section-header.collapsed .sidebar-section-chevron { transform:rotate(-90deg); }
.sidebar-section-items { overflow:hidden; max-height:2000px; transition:max-height .25s ease; }
.sidebar-section-items.collapsed { max-height:0 !important; }

/* Linha com botão de favorito */
.sidebar-item-row { position:relative; display:flex; align-items:center; }
.sidebar-item-row .sidebar-link { flex:1; padding-right:26px; }
.sidebar-fav-btn { position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:rgba(255,255,255,.3); font-size:.85rem; cursor:pointer; padding:2px 5px; border-radius:3px; transition:all .15s; }
.sidebar-item-row:hover .sidebar-fav-btn { color:rgba(255,255,255,.55); }
.sidebar-fav-btn:hover { color:#fbbf24 !important; background:rgba(255,255,255,.08); }
.sidebar-fav-btn.active { color:#fbbf24 !important; }

/* Barra de favoritos no topo */
.fav-bar { display:flex; align-items:center; gap:.4rem; padding:.35rem .8rem; background:var(--bg-card); border-bottom:1px solid var(--border); flex-wrap:wrap; font-size:.72rem; min-height:32px; }
.fav-bar-label { font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); margin-right:.3rem; }
.fav-bar-link { display:inline-flex; align-items:center; gap:3px; padding:3px 9px; border-radius:100px; background:rgba(184,115,51,.08); color:var(--petrol-900); text-decoration:none; font-weight:600; font-size:.72rem; border:1px solid rgba(184,115,51,.2); transition:all .15s; white-space:nowrap; }
.fav-bar-link:hover { background:#B87333; color:#fff; border-color:#B87333; }
.fav-bar-empty { color:var(--text-muted); font-style:italic; font-size:.7rem; }
body.dark-mode .fav-bar { background:var(--bg-card); border-color:var(--border); }
body.dark-mode .fav-bar-link { background:rgba(201,169,78,.12); color:#e0e0e0; border-color:rgba(201,169,78,.3); }
body.dark-mode .fav-bar-link:hover { background:#c9a94e; color:#1a1a2e; }

/* Collapsed sidebar: esconder chevrons e botões favoritos */
.sidebar.collapsed .sidebar-section-chevron,
.sidebar.collapsed .sidebar-fav-btn,
.sidebar.collapsed .sidebar-link-label { display:none !important; }
.sidebar.collapsed .sidebar-section-items { max-height:none !important; }
.sidebar.collapsed .sidebar-item-row .sidebar-link { padding-right:0; }
</style>

<script>
// ── Colapsar sidebar ──
function toggleSidebarCollapse() {
    var sb = document.getElementById('sidebar');
    sb.classList.toggle('collapsed');
    var btn = document.getElementById('btnCollapse');
    if (sb.classList.contains('collapsed')) {
        btn.innerHTML = '▶';
        btn.title = 'Expandir menu';
    } else {
        btn.innerHTML = '<span>◀ Recolher</span>';
        btn.title = 'Recolher menu';
    }
    try { localStorage.setItem('sidebar_collapsed', sb.classList.contains('collapsed') ? '1' : '0'); } catch(e) {}
}

// ── Dark Mode ──
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    var isDark = document.body.classList.contains('dark-mode');
    var btn = document.getElementById('btnDarkMode');
    btn.textContent = isDark ? '☀️' : '🌙';
    btn.title = isDark ? 'Modo claro' : 'Modo noturno';
    try { localStorage.setItem('dark_mode', isDark ? '1' : '0'); } catch(e) {}
}

// ── Seções colapsáveis ──
function toggleSidebarSection(slug) {
    var header = document.querySelector('[data-section="' + slug + '"]');
    var items = document.getElementById('items_' + slug);
    if (!header || !items) return;
    var collapsed = items.classList.toggle('collapsed');
    header.classList.toggle('collapsed', collapsed);
    // Salvar estado
    try {
        var state = JSON.parse(localStorage.getItem('sidebar_sections') || '{}');
        state[slug] = collapsed ? 1 : 0;
        localStorage.setItem('sidebar_sections', JSON.stringify(state));
    } catch(e) {}
}

// ── Favoritos ──
function getFavoritos() {
    try { return JSON.parse(localStorage.getItem('sidebar_favoritos') || '[]'); } catch(e) { return []; }
}
function saveFavoritos(list) {
    try { localStorage.setItem('sidebar_favoritos', JSON.stringify(list)); } catch(e) {}
}
function toggleFavorito(btn, evt) {
    if (evt) { evt.preventDefault(); evt.stopPropagation(); }
    var favs = getFavoritos();
    var id = btn.getAttribute('data-fav-id');
    var label = btn.getAttribute('data-fav-label');
    var icon = btn.getAttribute('data-fav-icon');
    var href = btn.getAttribute('data-fav-href');
    var idx = -1;
    for (var i = 0; i < favs.length; i++) { if (favs[i].id === id) { idx = i; break; } }
    if (idx >= 0) {
        favs.splice(idx, 1);
        btn.classList.remove('active');
        btn.textContent = '☆';
    } else {
        if (favs.length >= 10) { alert('Você já tem 10 favoritos. Remova um antes de adicionar.'); return; }
        favs.push({ id: id, label: label, icon: icon, href: href });
        btn.classList.add('active');
        btn.textContent = '★';
    }
    saveFavoritos(favs);
    renderFavBar();
}
function renderFavBar() {
    var bar = document.getElementById('favBar');
    if (!bar) return;
    var favs = getFavoritos();
    var html = '<span class="fav-bar-label">⭐ Favoritos:</span>';
    if (favs.length === 0) {
        html += '<span class="fav-bar-empty">Clique na estrela ☆ ao lado de qualquer item da sidebar para adicionar aqui</span>';
    } else {
        favs.forEach(function(f) {
            html += '<a href="' + f.href + '" class="fav-bar-link" title="' + f.label + '"><span>' + f.icon + '</span><span>' + f.label + '</span></a>';
        });
    }
    bar.innerHTML = html;
}

// ── Restaurar preferências ──
function _sidebarRestorePrefs() {
    try {
        if (localStorage.getItem('sidebar_collapsed') === '1') {
            var sb = document.getElementById('sidebar');
            if (sb) sb.classList.add('collapsed');
            var bc = document.getElementById('btnCollapse');
            if (bc) { bc.innerHTML = '▶'; bc.title = 'Expandir menu'; }
        }
        if (localStorage.getItem('dark_mode') === '1') {
            document.body.classList.add('dark-mode');
            var bd = document.getElementById('btnDarkMode');
            if (bd) { bd.textContent = '☀️'; bd.title = 'Modo claro'; }
        }
        // Seções colapsadas
        var state = JSON.parse(localStorage.getItem('sidebar_sections') || '{}');
        Object.keys(state).forEach(function(slug) {
            if (state[slug] === 1) {
                var header = document.querySelector('[data-section="' + slug + '"]');
                var items = document.getElementById('items_' + slug);
                if (header && items) {
                    items.classList.add('collapsed');
                    header.classList.add('collapsed');
                }
            }
        });
        // Marcar favoritos ativos
        var favs = getFavoritos();
        var favIds = favs.map(function(f) { return f.id; });
        document.querySelectorAll('.sidebar-fav-btn').forEach(function(btn) {
            if (favIds.indexOf(btn.getAttribute('data-fav-id')) >= 0) {
                btn.classList.add('active');
                btn.textContent = '★';
            }
        });
        renderFavBar();
    } catch(e) {}
}
// Rodar após DOM completo (favBar é emitido depois da sidebar)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _sidebarRestorePrefs);
} else {
    _sidebarRestorePrefs();
}
</script>
