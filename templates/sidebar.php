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

// Contar mensagens WhatsApp não lidas por canal
$_waNaoLidas21 = 0; $_waNaoLidas24 = 0; $_waFilaPendente = 0;
try {
    $_waNaoLidas21 = (int)db()->query("SELECT IFNULL(SUM(nao_lidas),0) FROM zapi_conversas WHERE canal='21' AND status != 'arquivado'")->fetchColumn();
    $_waNaoLidas24 = (int)db()->query("SELECT IFNULL(SUM(nao_lidas),0) FROM zapi_conversas WHERE canal='24' AND status != 'arquivado'")->fetchColumn();
    $_waFilaPendente = (int)db()->query("SELECT COUNT(*) FROM zapi_fila_envio WHERE status='pendente'")->fetchColumn();
} catch (Exception $e) {}

// Módulos de treinamento pendentes pro perfil do usuário
$_treinaPendentes = 0;
try {
    $uid = current_user_id();
    $roleU = current_user_role();
    if ($uid && $roleU) {
        // Total de módulos aplicáveis ao perfil (respeita whitelist financeira)
        $slugsFinanceiros = array('financeiro', 'cobranca-honorarios');
        $podeFinanceiro = function_exists('can_access_financeiro') && can_access_financeiro();
        $st = db()->prepare("SELECT slug, perfis_alvo FROM treinamento_modulos WHERE ativo = 1");
        $st->execute();
        $slugsAplicaveis = array();
        foreach ($st->fetchAll() as $m) {
            if (!$podeFinanceiro && in_array($m['slug'], $slugsFinanceiros, true)) continue;
            $perfis = json_decode($m['perfis_alvo'], true) ?: array();
            if (in_array('todos', $perfis, true) || in_array($roleU, $perfis, true)) {
                $slugsAplicaveis[] = $m['slug'];
            }
        }
        if ($slugsAplicaveis) {
            $ph = implode(',', array_fill(0, count($slugsAplicaveis), '?'));
            $st2 = db()->prepare("SELECT COUNT(*) FROM treinamento_progresso WHERE user_id = ? AND concluido = 1 AND modulo_slug IN ($ph)");
            $st2->execute(array_merge(array($uid), $slugsAplicaveis));
            $concluidos = (int)$st2->fetchColumn();
            $_treinaPendentes = max(0, count($slugsAplicaveis) - $concluidos);
        }
    }
} catch (Exception $e) {}

$all = array('admin','gestao','comercial','cx','operacional','estagiario','colaborador');
$equipe = array('admin','gestao','comercial','cx','operacional','estagiario'); // todos exceto colaborador
$menuItems = array(
    array('section' => 'Principal'),
    array('label' => 'Painel do Dia',   'icon' => '🌅', 'href' => url('modules/painel/'),          'id' => 'painel',          'roles' => $all),
    array('label' => 'Dashboard',       'icon' => '📊', 'href' => url('modules/dashboard/'),       'id' => 'dashboard',       'roles' => $all),
    array('label' => 'Portal de Links', 'icon' => '🔗', 'href' => url('modules/portal/'),          'id' => 'portal',          'roles' => $all),

    array('section' => '💬 WhatsApp'),
    array('label' => 'Comercial (21)',    'icon' => '💬', 'href' => url('modules/whatsapp/?canal=21'),   'id' => 'whatsapp_21',     'roles' => $all, 'badge' => $_waNaoLidas21),
    array('label' => 'CX / Operac. (24)', 'icon' => '💬', 'href' => url('modules/whatsapp/?canal=24'),   'id' => 'whatsapp_24',     'roles' => $all, 'badge' => $_waNaoLidas24),
    array('label' => 'Caixa de Envios',   'icon' => '📬', 'href' => url('modules/whatsapp/fila.php'),    'id' => 'whatsapp_fila',   'roles' => $all, 'badge' => $_waFilaPendente, 'badgeCor' => '#b45309'),
    array('label' => 'Dashboard WhatsApp', 'icon' => '📊', 'href' => url('modules/whatsapp/dashboard.php'), 'id' => 'whatsapp_dashboard', 'roles' => array('admin','gestao')),
    array('label' => 'Configurações',     'icon' => '⚙️', 'href' => url('modules/whatsapp/central.php'),  'id' => 'whatsapp_config', 'roles' => array('admin','gestao')),

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

    array('section' => '📖 Conhecimento'),
    array('label' => 'Wiki',            'icon' => '📚', 'href' => url('modules/wiki/'),             'id' => 'wiki',            'roles' => $all),

    array('section' => 'Equipe'),
    array('label' => 'Ranking',         'icon' => '🏆', 'href' => url('modules/gamificacao/'),      'id' => 'gamificacao',     'roles' => $all),

    array('section' => '🌟 Central VIP F&S'),
    array('label' => 'Central VIP',     'icon' => '🌟', 'href' => url('modules/salavip/'),            'id' => 'salavip',         'roles' => $all),
    array('label' => 'GED (Docs)',      'icon' => '📁', 'href' => url('modules/salavip/ged.php'),      'id' => 'salavip_ged',     'roles' => $all),
    array('label' => 'Acessos',         'icon' => '🔑', 'href' => url('modules/salavip/acessos.php'),  'id' => 'salavip_acessos', 'roles' => array('admin','gestao')),
    array('label' => 'FAQ',             'icon' => '❓', 'href' => url('modules/salavip/faq_admin.php'), 'id' => 'salavip_faq',     'roles' => array('admin','gestao')),
    array('label' => 'Log Acessos',     'icon' => '📋', 'href' => url('modules/salavip/log.php'),      'id' => 'salavip_log',     'roles' => array('admin','gestao')),

    array('section' => 'Sistema'),
    array('label' => 'Treinamento',     'icon' => '🎓', 'href' => url('modules/treinamento/'),      'id' => 'treinamento',     'roles' => $all, 'badge' => $_treinaPendentes, 'badgeCor' => '#B87333'),
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
@keyframes sidebarPulse { 0%,100% { box-shadow:0 0 0 2px rgba(220,38,38,.2); } 50% { box-shadow:0 0 0 5px rgba(220,38,38,0); } }
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

    <!-- Busca no menu -->
    <div class="sidebar-search-wrap">
        <input id="sidebarSearch" type="text" placeholder="🔍 Buscar no menu..." autocomplete="off"
               onkeydown="if(event.key==='Escape'){this.value='';sidebarFiltrar('');}">
        <span id="sidebarSearchClear" onclick="document.getElementById('sidebarSearch').value='';sidebarFiltrar('');this.style.display='none';" title="Limpar">✕</span>
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
                <?php foreach ($group['items'] as $item):
                    // Highlight especial para WhatsApp (21 vs 24) baseado no ?canal=
                    $isActive = false;
                    if ($item['id'] === 'whatsapp_21' || $item['id'] === 'whatsapp_24') {
                        $canalItem  = ($item['id'] === 'whatsapp_24') ? '24' : '21';
                        $onWhatsApp = strpos($_SERVER['REQUEST_URI'] ?? '', '/modules/whatsapp') !== false;
                        $canalAtual = $_GET['canal'] ?? '21';
                        $isActive   = $onWhatsApp && $canalAtual === $canalItem;
                    } else {
                        $isActive = is_current_module($item['id']);
                    }
                ?>
                    <div class="sidebar-item-row">
                        <a href="<?= $item['href'] ?>"
                           class="sidebar-link <?= $isActive ? 'active' : '' ?>"
                           title="<?= e($item['label']) ?>">
                            <span class="icon"><?= $item['icon'] ?></span>
                            <span class="sidebar-link-label"><?= e($item['label']) ?></span>
                            <?php if ($item['id'] === 'salavip' && $_svMsgsNaoLidas > 0): ?>
                                <span style="background:#dc2626;color:#fff;font-size:.6rem;padding:1px 5px;border-radius:9px;margin-left:auto;font-weight:700;"><?= $_svMsgsNaoLidas ?></span>
                            <?php elseif (!empty($item['badge']) && (int)$item['badge'] > 0):
                                $bdgCor = $item['badgeCor'] ?? '#dc2626';
                            ?>
                                <span style="background:<?= e($bdgCor) ?>;color:#fff;font-size:.6rem;padding:1px 6px;border-radius:9px;margin-left:auto;font-weight:700;box-shadow:0 0 0 2px rgba(220,38,38,.2);animation:sidebarPulse 2s ease-in-out infinite;"><?= (int)$item['badge'] ?></span>
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

    <!-- Instalar como App (sempre visível — substitui o botão flutuante) -->
    <button id="btnInstalarApp" onclick="fsaAbrirInstalar()" style="margin:0 .6rem .5rem;padding:.5rem .7rem;background:rgba(184,115,51,.15);border:1px solid rgba(184,115,51,.35);color:#fff;border-radius:8px;cursor:pointer;font-size:.78rem;font-weight:600;text-align:left;display:flex;align-items:center;gap:.5rem;width:calc(100% - 1.2rem);">
        📲 <span>Instalar como App</span>
    </button>

    <div class="sidebar-footer">
        <div class="user-avatar"><?= e(mb_strtoupper($userInitials)) ?></div>
        <div class="user-info">
            <div class="user-name"><?= e($user['name'] ?? '') ?></div>
            <div class="user-role"><?= role_label($userRole) ?></div>
        </div>
        <a href="<?= url('auth/logout.php') ?>" class="btn-logout" title="Sair">⏻</a>
    </div>
</aside>

<!-- Modal Instalar como App — instruções por plataforma -->
<div id="fsaModalInstalar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:14px;padding:1.4rem;max-width:440px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;">
            <h3 style="margin:0;color:#052228;font-size:1rem;">📲 Instalar F&amp;S Hub</h3>
            <button onclick="document.getElementById('fsaModalInstalar').style.display='none'" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div id="fsaInstalarBody" style="font-size:.86rem;color:#334155;line-height:1.55;"></div>
    </div>
</div>

<script>
function fsaAbrirInstalar() {
    var body = document.getElementById('fsaInstalarBody');
    var modal = document.getElementById('fsaModalInstalar');

    // Detecta estado atual
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true
                    || document.referrer.indexOf('android-app://') === 0;
    var ua = navigator.userAgent || '';
    var isiOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    var isAndroid = /Android/i.test(ua);

    // Bloco de instrução "onde achar" por plataforma — usado quando já instalado
    var ondeAchar = '';
    if (isAndroid) {
        ondeAchar = '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.7rem .9rem;margin-bottom:.8rem;font-size:.78rem;color:#1e40af;">'
                  + '<strong>📍 Onde achar no Android:</strong><br>'
                  + '1. Tela inicial — role e procure o ícone F&amp;S Hub<br>'
                  + '2. Gaveta de apps (arraste de baixo pra cima) → role ou busque por "F&amp;S" ou "Hub"<br>'
                  + '3. Se não aparecer em lugar nenhum, a instalação pode ter falhado — reinstale pelas instruções abaixo.'
                  + '</div>';
    } else if (isiOS) {
        ondeAchar = '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.7rem .9rem;margin-bottom:.8rem;font-size:.78rem;color:#1e40af;">'
                  + '<strong>📍 Onde achar no iPhone/iPad:</strong><br>'
                  + 'Tela inicial — procure o ícone F&amp;S Hub. Se não aparecer, deslize pra esquerda até a Biblioteca de Apps ou use a busca (deslize pra baixo na tela inicial). Se ainda não achar, reinstale pelas instruções abaixo.'
                  + '</div>';
    }

    if (isStandalone) {
        body.innerHTML = '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.8rem 1rem;color:#166534;margin-bottom:.8rem;">✅ <strong>Você está no modo app agora!</strong> Essa janela é a versão instalada do Hub.</div>'
            + ondeAchar;
    } else if (window._fsaDeferredPrompt) {
        // Android Chrome/Edge — evento disponível, dispara nativo
        body.innerHTML = '<p style="margin:0 0 .8rem;">Clique abaixo pra instalar o Hub como aplicativo no seu dispositivo:</p>'
            + '<button id="fsaInstalarGo" style="background:#B87333;color:#fff;border:none;padding:.7rem 1.2rem;border-radius:8px;font-weight:700;cursor:pointer;width:100%;font-size:.88rem;">📲 Instalar agora</button>';
        document.getElementById('fsaInstalarGo').onclick = function() {
            window._fsaDeferredPrompt.prompt();
            window._fsaDeferredPrompt.userChoice.then(function(choice) {
                if (choice.outcome === 'accepted') {
                    body.innerHTML = '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.8rem 1rem;color:#166534;">✅ Instalado! Procure o ícone na tela inicial ou gaveta de apps.</div>';
                    localStorage.removeItem('fsa_install_dispensado');
                }
                window._fsaDeferredPrompt = null;
            });
        };
    } else if (isiOS) {
        body.innerHTML = '<p style="margin:0 0 .8rem;"><strong>iPhone/iPad:</strong></p>'
            + '<ol style="padding-left:1.2rem;margin:0;">'
            + '<li>Toque no botão de <strong>Compartilhar</strong> do Safari (quadrado com seta pra cima, na barra inferior)</li>'
            + '<li>Role até encontrar <strong>"Adicionar à Tela de Início"</strong></li>'
            + '<li>Toque em <strong>Adicionar</strong> no canto superior direito</li>'
            + '</ol>'
            + '<p style="margin:.8rem 0 0;font-size:.78rem;color:#64748b;">Depois de instalado, o Hub abrirá como app (sem barras do navegador) e poderá receber notificações.</p>';
    } else if (isAndroid) {
        body.innerHTML = '<p style="margin:0 0 .8rem;"><strong>Android (Chrome):</strong></p>'
            + '<ol style="padding-left:1.2rem;margin:0;">'
            + '<li>Toque no menu do Chrome (<strong>⋮</strong> no canto superior direito)</li>'
            + '<li>Toque em <strong>"Instalar app"</strong> ou <strong>"Adicionar à tela inicial"</strong></li>'
            + '<li>Confirme tocando em <strong>Instalar</strong></li>'
            + '</ol>'
            + '<p style="margin:.8rem 0 0;font-size:.78rem;color:#64748b;">Se a opção não aparecer, navegue no Hub por alguns minutos e tente de novo — o Chrome precisa de um mínimo de interação antes de liberar o prompt.</p>';
    } else {
        // Desktop
        body.innerHTML = '<p style="margin:0 0 .8rem;"><strong>Computador (Chrome/Edge):</strong></p>'
            + '<ol style="padding-left:1.2rem;margin:0;">'
            + '<li>Na barra de endereço, clique no ícone <strong>⊕ Instalar</strong> à direita (ou no menu ⋮ → "Instalar F&amp;S Hub")</li>'
            + '<li>Confirme tocando em <strong>Instalar</strong></li>'
            + '</ol>'
            + '<p style="margin:.8rem 0 0;font-size:.78rem;color:#64748b;">Depois de instalado, o Hub abre em janela própria como qualquer outro programa do computador.</p>';
    }

    modal.style.display = 'flex';
    // Reset flag de dispensar — se ela está abrindo o modal manual, quer ver o prompt
    localStorage.removeItem('fsa_install_dispensado');
}
</script>

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

/* Barra de favoritos no topo (sticky logo abaixo do topbar) */
.fav-bar { display:flex; align-items:center; gap:.4rem; padding:.35rem .8rem; background:var(--bg-card); border-bottom:1px solid var(--border); flex-wrap:wrap; font-size:.72rem; min-height:32px; position:sticky; top:var(--topbar-h); z-index:40; box-shadow:var(--shadow-sm); }
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
.sidebar.collapsed .sidebar-link-label,
.sidebar.collapsed .sidebar-search-wrap { display:none !important; }
.sidebar.collapsed .sidebar-section-items { max-height:none !important; }
.sidebar.collapsed .sidebar-item-row .sidebar-link { padding-right:0; }

/* Busca no sidebar */
.sidebar-search-wrap { position:relative; padding:.5rem .8rem .4rem; }
.sidebar-search-wrap input { width:100%; padding:6px 28px 6px 10px; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12); color:#fff; border-radius:8px; font-size:.78rem; outline:none; transition:background .15s, border-color .15s; }
.sidebar-search-wrap input::placeholder { color:rgba(255,255,255,.45); }
.sidebar-search-wrap input:focus { background:rgba(255,255,255,.14); border-color:rgba(255,255,255,.3); }
.sidebar-search-wrap #sidebarSearchClear { position:absolute; right:16px; top:50%; transform:translateY(-50%); color:rgba(255,255,255,.55); font-size:.72rem; cursor:pointer; display:none; padding:2px 5px; border-radius:3px; }
.sidebar-search-wrap #sidebarSearchClear:hover { color:#fff; background:rgba(255,255,255,.1); }
.sidebar-search-noresult { color:rgba(255,255,255,.55); font-style:italic; font-size:.75rem; text-align:center; padding:.75rem; display:none; }
.sidebar-search-active .sidebar-section-items { max-height:2000px !important; }
.sidebar-search-active .sidebar-section-header.collapsed .sidebar-section-chevron { transform:rotate(0deg); }
/* Highlight do termo buscado */
.sidebar-highlight { background:rgba(251,191,36,.45); color:#fff; border-radius:3px; padding:0 1px; }
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

// ── Busca no menu lateral ──
function sidebarFiltrar(q) {
    q = (q || '').trim().toLowerCase();
    var nav = document.getElementById('sidebarNav');
    var btnClear = document.getElementById('sidebarSearchClear');
    if (btnClear) btnClear.style.display = q ? 'inline-block' : 'none';

    // Sem busca: restaura tudo
    if (!q) {
        nav.classList.remove('sidebar-search-active');
        nav.querySelectorAll('.sidebar-item-row').forEach(function(row){
            row.style.display = '';
            var label = row.querySelector('.sidebar-link-label');
            if (label && label.dataset.original) {
                label.innerHTML = label.dataset.original;
            }
        });
        nav.querySelectorAll('.sidebar-section-header, .sidebar-section-items').forEach(function(el){
            el.style.display = '';
        });
        var nr = document.getElementById('sidebarNoResult');
        if (nr) nr.style.display = 'none';
        return;
    }

    // Com busca: força seções abertas, filtra itens por label
    nav.classList.add('sidebar-search-active');
    var totalVisiveis = 0;
    nav.querySelectorAll('.sidebar-item-row').forEach(function(row){
        var label = row.querySelector('.sidebar-link-label');
        if (!label) return;
        if (!label.dataset.original) label.dataset.original = label.textContent;
        var texto = label.dataset.original.toLowerCase();
        var bate = texto.indexOf(q) !== -1;
        row.style.display = bate ? '' : 'none';
        if (bate) {
            totalVisiveis++;
            // Highlight do termo
            var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
            label.innerHTML = label.dataset.original.replace(re, '<span class="sidebar-highlight">$1</span>');
        }
    });

    // Esconde seção cujos itens ficaram todos invisíveis
    nav.querySelectorAll('.sidebar-section-items').forEach(function(items){
        var visiveis = items.querySelectorAll('.sidebar-item-row:not([style*="display: none"])').length;
        var header = nav.querySelector('[data-section="' + items.id.replace('items_','') + '"]');
        if (visiveis === 0) {
            items.style.display = 'none';
            if (header) header.style.display = 'none';
        } else {
            items.style.display = '';
            if (header) header.style.display = '';
        }
    });

    // Mensagem "sem resultados"
    var nr = document.getElementById('sidebarNoResult');
    if (!nr) {
        nr = document.createElement('div');
        nr.id = 'sidebarNoResult';
        nr.className = 'sidebar-search-noresult';
        nr.textContent = 'Nada encontrado. Tente outro termo.';
        nav.appendChild(nr);
    }
    nr.style.display = totalVisiveis === 0 ? 'block' : 'none';
}

// Listener do input com debounce leve + Enter abre o primeiro resultado
(function(){
    var input = document.getElementById('sidebarSearch');
    if (!input) return;
    var t;
    input.addEventListener('input', function(e){
        clearTimeout(t);
        t = setTimeout(function(){ sidebarFiltrar(e.target.value); }, 120);
    });
    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            var first = document.querySelector('#sidebarNav .sidebar-item-row:not([style*="display: none"]) .sidebar-link');
            if (first) first.click();
        }
    });
})();

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

// ── PRESERVAR SCROLL DA SIDEBAR AO NAVEGAR ──────────────
(function(){
    var sidebarNav = document.getElementById('sidebarNav');
    if (!sidebarNav) return;
    var KEY = 'fsa_sidebar_scroll';

    function salvar() {
        try { localStorage.setItem(KEY, String(sidebarNav.scrollTop)); } catch(_) {}
    }
    function restaurar() {
        try {
            var saved = parseInt(localStorage.getItem(KEY) || '0', 10);
            if (saved > 0 && sidebarNav.scrollTop !== saved) sidebarNav.scrollTop = saved;
        } catch(_) {}
    }

    // Salvar ao clicar em qualquer link da sidebar
    sidebarNav.addEventListener('click', function(e){
        var link = e.target.closest('a.sidebar-link');
        if (!link) return;
        salvar();
    });
    // Salvar também no capture phase (antes do navegador começar a navegar)
    document.addEventListener('click', function(e){
        if (e.target.closest && e.target.closest('#sidebarNav a.sidebar-link')) salvar();
    }, true);
    // Também antes de sair da página (fallback)
    window.addEventListener('beforeunload', salvar);
    // E ao scrollar (debounced)
    var scrollTimer;
    sidebarNav.addEventListener('scroll', function(){
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(salvar, 150);
    });

    // Restaurar em múltiplos pontos pra vencer layout tardio
    restaurar();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restaurar);
    }
    window.addEventListener('load', function(){
        restaurar();
        // Após carregar TUDO (fonts, imgs), última tentativa
        setTimeout(restaurar, 50);
        setTimeout(restaurar, 200);
    });
})();
</script>
