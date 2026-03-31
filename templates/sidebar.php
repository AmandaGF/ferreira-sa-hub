<?php
/**
 * Sidebar — Menu lateral com navegação baseada em roles
 */
$user = current_user();
$userRole = $user['role'] ?? 'colaborador';
$userInitials = mb_substr($user['name'] ?? '?', 0, 2, 'UTF-8');

// Definir itens do menu com controle de acesso por role
// all = todos os perfis
$all = array('admin','gestao','comercial','cx','operacional','colaborador');
$menuItems = array(
    array('section' => 'Principal'),
    array('label' => 'Dashboard',       'icon' => '📊', 'href' => url('modules/dashboard/'),       'id' => 'dashboard',       'roles' => $all),
    array('label' => 'Portal de Links', 'icon' => '🔗', 'href' => url('modules/portal/'),          'id' => 'portal',          'roles' => $all),

    array('section' => 'Atendimento'),
    array('label' => 'Helpdesk',        'icon' => '🎫', 'href' => url('modules/helpdesk/'),        'id' => 'helpdesk',        'roles' => $all),

    array('section' => 'Comercial'),
    array('label' => 'CRM',             'icon' => '🎯', 'href' => url('modules/crm/'),             'id' => 'crm',             'roles' => array('admin','gestao','comercial','cx')),
    array('label' => 'Pipeline',        'icon' => '📈', 'href' => url('modules/pipeline/'),         'id' => 'pipeline',        'roles' => array('admin','gestao','comercial','cx')),

    array('section' => 'Cadastros'),
    array('label' => 'Clientes',        'icon' => '👥', 'href' => url('modules/clientes/'),         'id' => 'clientes',        'roles' => $all),

    array('section' => 'Demandas'),
    array('label' => 'Pré-Processual',  'icon' => '📂', 'href' => url('modules/pre_processual/'),  'id' => 'pre_processual',  'roles' => array('admin','gestao','operacional')),
    array('label' => 'Processos',       'icon' => '⚖️', 'href' => url('modules/processos/'),       'id' => 'processos',       'roles' => array('admin','gestao','operacional')),
    array('label' => 'Extrajudicial',   'icon' => '📋', 'href' => url('modules/servicos/'),         'id' => 'servicos',        'roles' => array('admin','gestao','operacional')),

    array('section' => 'Execução'),
    array('label' => 'Operacional',     'icon' => '⚙️', 'href' => url('modules/operacional/'),     'id' => 'operacional',     'roles' => array('admin','gestao','operacional','comercial','cx')),

    array('section' => 'Controle'),
    array('label' => 'Prazos',          'icon' => '⏰', 'href' => url('modules/prazos/'),           'id' => 'prazos',          'roles' => array('admin','gestao','operacional')),
    array('label' => 'Ofícios',         'icon' => '📬', 'href' => url('modules/oficios/'),          'id' => 'oficios',         'roles' => array('admin','gestao','operacional')),
    array('label' => 'Alvarás',         'icon' => '💰', 'href' => url('modules/alvaras/'),          'id' => 'alvaras',         'roles' => array('admin','gestao','operacional')),
    array('label' => 'Parceiros',       'icon' => '🤝', 'href' => url('modules/parceiros/'),        'id' => 'parceiros',       'roles' => array('admin','gestao')),

    array('section' => 'Dados'),
    array('label' => 'Documentos',      'icon' => '📜', 'href' => url('modules/documentos/'),      'id' => 'documentos',      'roles' => array('admin','gestao','operacional')),
    array('label' => 'Formulários',     'icon' => '📋', 'href' => url('modules/formularios/'),      'id' => 'formularios',     'roles' => array('admin','gestao')),
    array('label' => 'Relatórios',      'icon' => '📉', 'href' => url('modules/relatorios/'),       'id' => 'relatorios',      'roles' => array('admin','gestao')),

    array('section' => 'Comunicação'),
    array('label' => 'Mensagens Prontas','icon' => '💬', 'href' => url('modules/mensagens/'),       'id' => 'mensagens',       'roles' => $all),
    array('label' => 'Notificações',    'icon' => '🔔', 'href' => url('modules/notificacoes/'),     'id' => 'notificacoes',    'roles' => $all),
    array('label' => 'Notif. Clientes', 'icon' => '📲', 'href' => url('modules/notificacoes/log_cliente.php'), 'id' => 'notif_clientes', 'roles' => array('admin','gestao','comercial','cx')),
    array('label' => 'Datas Especiais', 'icon' => '🎂', 'href' => url('modules/aniversarios/'),     'id' => 'aniversarios',    'roles' => $all),

    array('section' => 'Sistema'),
    array('label' => 'Usuários',        'icon' => '🛡️', 'href' => url('modules/usuarios/'),        'id' => 'usuarios',        'roles' => array('admin')),
);
?>

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
            <?php elseif (in_array($userRole, $item['roles'], true)): ?>
                <a href="<?= $item['href'] ?>"
                   class="sidebar-link <?= is_current_module($item['id']) ? 'active' : '' ?>">
                    <span class="icon"><?= $item['icon'] ?></span>
                    <?= e($item['label']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-avatar"><?= e(mb_strtoupper($userInitials)) ?></div>
        <div class="user-info">
            <div class="user-name"><?= e($user['name'] ?? '') ?></div>
            <div class="user-role"><?= role_label($userRole) ?></div>
        </div>
        <a href="<?= url('auth/logout.php') ?>" class="btn-logout" title="Sair">⏻</a>
    </div>
</aside>
