<?php
/**
 * Ferreira & Sá Conecta — Permissões e Roles
 *
 * Sistema de permissões por módulo com defaults por role
 * e overrides individuais por usuário (tabela user_permissions).
 */

// ─── Roles ──────────────────────────────────────────────
// Perfis: admin, gestao, comercial, cx, operacional, colaborador, estagiario
function role_label(string $role): string
{
    $labels = array('admin' => 'Administrador', 'gestao' => 'Gestão', 'comercial' => 'Comercial', 'cx' => 'CX', 'operacional' => 'Operacional', 'colaborador' => 'Colaborador', 'estagiario' => 'Estagiário');
    return isset($labels[$role]) ? $labels[$role] : 'Desconhecido';
}

function role_level(string $role): int
{
    // admin > gestao > comercial/cx/operacional > estagiario > colaborador
    $levels = array('admin' => 5, 'gestao' => 4, 'comercial' => 3, 'cx' => 3, 'operacional' => 3, 'estagiario' => 2, 'colaborador' => 1);
    return isset($levels[$role]) ? $levels[$role] : 0;
}

function role_badge(string $role): string
{
    $label = role_label($role);
    $badgeClass = $role;
    if (in_array($role, array('comercial', 'cx', 'operacional'))) $badgeClass = 'gestao';
    return '<span class="badge badge-' . e($badgeClass) . '">' . $label . '</span>';
}

// ═══════════════════════════════════════════════════════
// SISTEMA DE PERMISSÕES POR MÓDULO
// ═══════════════════════════════════════════════════════

// Módulos e quais roles TÊM acesso por padrão
function _permission_defaults()
{
    return array(
        'dashboard'           => array('admin','gestao','comercial','cx','operacional','colaborador','estagiario'),
        'dashboard_comercial' => array('admin','gestao','comercial','cx'),
        'dashboard_operacional' => array('admin','gestao','operacional'),
        'portal'              => array('admin','gestao','comercial','cx','operacional','colaborador','estagiario'),
        'helpdesk'            => array('admin','gestao','comercial','cx','operacional','colaborador','estagiario'),
        'agenda'              => array('admin','gestao','comercial','cx','operacional','colaborador','estagiario'),
        'crm'                 => array('admin','gestao','comercial','cx'),
        'pipeline'            => array('admin','gestao','comercial','cx'),
        'pipeline_mover_comercial' => array('admin','gestao','comercial'),
        'pipeline_mover_cx'   => array('admin','gestao','cx'),
        'operacional'         => array('admin','gestao','operacional','comercial','cx'),
        'operacional_mover'   => array('admin','gestao','operacional','comercial','cx'),
        'processos'           => array('admin','gestao','operacional','comercial','cx'),
        'prazos'              => array('admin','gestao','operacional'),
        'documentos'          => array('admin','gestao','operacional'),
        'peticoes'            => array('admin','gestao','operacional'),
        'formularios'         => array('admin','gestao'),
        'relatorios'          => array('admin','gestao'),
        'usuarios'            => array('admin'),
        'financeiro'          => array('admin','gestao','comercial'),
        'faturamento'         => array('admin'),
    );
}

/**
 * Verifica se o usuário atual pode acessar um módulo.
 * 1. Admin sempre pode tudo
 * 2. Verifica override individual (user_permissions)
 * 3. Fallback para default do role
 */
function can_access($module)
{
    static $overrides = null;

    $role = current_user_role();
    if (!$role) return false;
    if ($role === 'admin') return true;

    $userId = current_user_id();

    // Carregar overrides do banco (uma vez por request)
    if ($overrides === null) {
        $overrides = array();
        try {
            $rows = db()->prepare("SELECT module, allowed FROM user_permissions WHERE user_id = ?");
            $rows->execute(array($userId));
            foreach ($rows->fetchAll() as $r) {
                $overrides[$r['module']] = (int)$r['allowed'];
            }
        } catch (Exception $e) {
            // Tabela pode não existir ainda
        }
    }

    // Override individual tem prioridade
    if (isset($overrides[$module])) {
        return (bool)$overrides[$module];
    }

    // Default do role
    $defaults = _permission_defaults();
    if (isset($defaults[$module])) {
        return in_array($role, $defaults[$module], true);
    }

    return false;
}

/**
 * Retorna as permissões de um usuário específico (para UI admin)
 */
function get_user_permissions($userId, $userRole)
{
    $defaults = _permission_defaults();
    $result = array();

    // Carregar overrides
    $overrides = array();
    try {
        $rows = db()->prepare("SELECT module, allowed FROM user_permissions WHERE user_id = ?");
        $rows->execute(array($userId));
        foreach ($rows->fetchAll() as $r) $overrides[$r['module']] = (int)$r['allowed'];
    } catch (Exception $e) {}

    foreach ($defaults as $module => $roles) {
        $defaultAllowed = in_array($userRole, $roles, true);
        $hasOverride = isset($overrides[$module]);
        $effectiveAllowed = $hasOverride ? (bool)$overrides[$module] : $defaultAllowed;

        $result[$module] = array(
            'default' => $defaultAllowed,
            'override' => $hasOverride ? (int)$overrides[$module] : null,
            'effective' => $effectiveAllowed,
        );
    }
    return $result;
}

// Labels amigáveis dos módulos
function module_permission_labels()
{
    return array(
        'dashboard' => 'Dashboard (Geral)',
        'dashboard_comercial' => 'Dashboard Comercial',
        'dashboard_operacional' => 'Dashboard Operacional',
        'portal' => 'Portal de Links',
        'helpdesk' => 'Helpdesk',
        'agenda' => 'Agenda',
        'crm' => 'CRM (Clientes)',
        'pipeline' => 'Kanban Comercial',
        'pipeline_mover_comercial' => 'Mover Pipeline (Comercial)',
        'pipeline_mover_cx' => 'Mover Pipeline (CX)',
        'operacional' => 'Kanban Operacional',
        'operacional_mover' => 'Mover Operacional',
        'processos' => 'Processos',
        'prazos' => 'Prazos',
        'documentos' => 'Documentos',
        'peticoes' => 'Fábrica de Petições',
        'formularios' => 'Formulários',
        'relatorios' => 'Relatórios',
        'financeiro' => 'Módulo Financeiro',
        'usuarios' => 'Gestão de Usuários',
        'faturamento' => 'Ver Faturamento (R$)',
    );
}
