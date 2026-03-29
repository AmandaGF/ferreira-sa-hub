<?php
/**
 * Sidebar — Menu lateral com navegação baseada em roles
 */
$user = current_user();
$userRole = $user['role'] ?? 'colaborador';
$userInitials = mb_substr($user['name'] ?? '?', 0, 2, 'UTF-8');

// Definir itens do menu com controle de acesso por role
$menuItems = [
    ['section' => 'Principal'],
    [
        'label' => 'Dashboard',
        'icon'  => '📊',
        'href'  => url('modules/dashboard/'),
        'id'    => 'dashboard',
        'roles' => ['admin', 'gestao', 'colaborador'],
    ],
    [
        'label' => 'Portal de Links',
        'icon'  => '🔗',
        'href'  => url('modules/portal/'),
        'id'    => 'portal',
        'roles' => ['admin', 'gestao', 'colaborador'],
    ],

    ['section' => 'Atendimento'],
    [
        'label' => 'Helpdesk',
        'icon'  => '🎫',
        'href'  => url('modules/helpdesk/'),
        'id'    => 'helpdesk',
        'roles' => ['admin', 'gestao', 'colaborador'],
    ],

    ['section' => 'Comercial'],
    [
        'label' => 'Pipeline',
        'icon'  => '📈',
        'href'  => url('modules/pipeline/'),
        'id'    => 'pipeline',
        'roles' => ['admin', 'gestao'],
    ],

    ['section' => 'Cadastros'],
    [
        'label' => 'Clientes',
        'icon'  => '👥',
        'href'  => url('modules/crm/'),
        'id'    => 'crm',
        'roles' => ['admin', 'gestao', 'colaborador'],
    ],
    [
        'label' => 'Processos',
        'icon'  => '📁',
        'href'  => url('modules/processos/'),
        'id'    => 'processos',
        'roles' => ['admin', 'gestao', 'colaborador'],
    ],

    ['section' => 'Execução'],
    [
        'label' => 'Operacional',
        'icon'  => '⚙️',
        'href'  => url('modules/operacional/'),
        'id'    => 'operacional',
        'roles' => ['admin', 'gestao', 'colaborador'],
    ],

    ['section' => 'Dados'],
    [
        'label' => 'Documentos',
        'icon'  => '📜',
        'href'  => url('modules/documentos/'),
        'id'    => 'documentos',
        'roles' => ['admin', 'gestao'],
    ],
    [
        'label' => 'Formulários',
        'icon'  => '📋',
        'href'  => url('modules/formularios/'),
        'id'    => 'formularios',
        'roles' => ['admin', 'gestao'],
    ],
    [
        'label' => 'Relatórios',
        'icon'  => '📉',
        'href'  => url('modules/relatorios/'),
        'id'    => 'relatorios',
        'roles' => ['admin', 'gestao'],
    ],

    ['section' => 'Comunicação'],
    [
        'label' => 'Notificações',
        'icon'  => '🔔',
        'href'  => url('modules/notificacoes/'),
        'id'    => 'notificacoes',
        'roles' => ['admin', 'gestao', 'colaborador'],
    ],
    [
        'label' => 'Datas Especiais',
        'icon'  => '🎂',
        'href'  => url('modules/aniversarios/'),
        'id'    => 'aniversarios',
        'roles' => ['admin', 'gestao', 'colaborador'],
    ],

    ['section' => 'Sistema'],
    [
        'label' => 'Usuários',
        'icon'  => '🛡️',
        'href'  => url('modules/usuarios/'),
        'id'    => 'usuarios',
        'roles' => ['admin'],
    ],
];
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
