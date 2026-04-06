<?php
/**
 * Sidebar — Menu lateral com navegação baseada em roles
 * Suporte: colapsar (só ícones) + modo noturno
 */
$user = current_user();
$userRole = $user['role'] ?? 'colaborador';
$userInitials = mb_substr($user['name'] ?? '?', 0, 2, 'UTF-8');

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
    array('label' => 'Processos',       'icon' => '⚖️', 'href' => url('modules/processos/'),       'id' => 'processos',       'roles' => $equipe),
    array('label' => 'Tarefas',         'icon' => '✅', 'href' => url('modules/tarefas/'),        'id' => 'tarefas',         'roles' => array('admin','gestao','operacional')),
    array('label' => 'Calc. Prazos',    'icon' => '📅', 'href' => url('modules/operacional/prazos_calc.php'), 'id' => 'prazos_calc', 'roles' => $equipe),
    array('label' => 'Extrajudicial',   'icon' => '📝', 'href' => url('modules/servicos/'),         'id' => 'servicos',        'roles' => $equipe),
    array('label' => 'Pré-Processual',  'icon' => '📂', 'href' => url('modules/pre_processual/'),  'id' => 'pre_processual',  'roles' => $equipe),
    array('label' => 'Fáb. Petições',  'icon' => '📝', 'href' => url('modules/peticoes/'),         'id' => 'peticoes',        'roles' => $equipe),

    array('section' => '📇 Cadastros'),
    array('label' => 'Agenda de Contatos','icon' => '👥', 'href' => url('modules/clientes/'),       'id' => 'clientes',        'roles' => $all),

    array('section' => '💰 Financeiro'),
    array('label' => 'Financeiro',      'icon' => '💰', 'href' => url('modules/financeiro/'),       'id' => 'financeiro',      'roles' => array('admin','gestao','comercial')),

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
    array('label' => 'Datas Especiais', 'icon' => '🎂', 'href' => url('modules/aniversarios/'),     'id' => 'aniversarios',    'roles' => $all),

    array('section' => 'Equipe'),
    array('label' => 'Ranking',         'icon' => '🏆', 'href' => url('modules/gamificacao/'),      'id' => 'gamificacao',     'roles' => $all),

    array('section' => 'Sistema'),
    array('label' => 'Treinamento',     'icon' => '🎓', 'href' => url('modules/treinamento/'),      'id' => 'treinamento',     'roles' => $all),
    array('label' => 'Usuários',        'icon' => '🛡️', 'href' => url('modules/usuarios/'),        'id' => 'usuarios',        'roles' => array('admin')),
    array('label' => 'Permissões',     'icon' => '🔐', 'href' => url('modules/admin/permissoes.php'), 'id' => 'permissoes',   'roles' => array('admin')),
    array('label' => 'DataJud',         'icon' => '🔄', 'href' => url('modules/admin/datajud_monitor.php'), 'id' => 'datajud',  'roles' => array('admin','gestao')),
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

    <nav class="sidebar-nav">
        <?php foreach ($menuItems as $item): ?>
            <?php if (isset($item['section'])): ?>
                <div class="sidebar-section"><?= e($item['section']) ?></div>
            <?php else:
                // Verificar permissão
                $showItem = false;
                if (function_exists('can_access') && isset($item['id'])) {
                    $defaults = _permission_defaults();
                    $showItem = isset($defaults[$item['id']]) ? can_access($item['id']) : in_array($userRole, $item['roles'], true);
                } else {
                    $showItem = in_array($userRole, $item['roles'], true);
                }
                if ($showItem): ?>
                <a href="<?= $item['href'] ?>"
                   class="sidebar-link <?= is_current_module($item['id']) ? 'active' : '' ?>"
                   title="<?= e($item['label']) ?>">
                    <span class="icon"><?= $item['icon'] ?></span>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endif; // showItem ?>
            <?php endif; // section vs item ?>
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

<script>
// Colapsar sidebar
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

// Dark Mode
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    var isDark = document.body.classList.contains('dark-mode');
    var btn = document.getElementById('btnDarkMode');
    btn.textContent = isDark ? '☀️' : '🌙';
    btn.title = isDark ? 'Modo claro' : 'Modo noturno';
    try { localStorage.setItem('dark_mode', isDark ? '1' : '0'); } catch(e) {}
}

// Restaurar preferências
(function() {
    try {
        if (localStorage.getItem('sidebar_collapsed') === '1') {
            document.getElementById('sidebar').classList.add('collapsed');
            document.getElementById('btnCollapse').innerHTML = '▶';
            document.getElementById('btnCollapse').title = 'Expandir menu';
        }
        if (localStorage.getItem('dark_mode') === '1') {
            document.body.classList.add('dark-mode');
            document.getElementById('btnDarkMode').textContent = '☀️';
            document.getElementById('btnDarkMode').title = 'Modo claro';
        }
    } catch(e) {}
})();
</script>
