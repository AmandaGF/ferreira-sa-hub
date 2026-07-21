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
$_waNaoLidas21 = 0; $_waNaoLidas24 = 0; $_waFilaPendente = 0; $_waAgendaPend = 0;
try {
    $_waNaoLidas21 = (int)db()->query("SELECT IFNULL(SUM(nao_lidas),0) FROM zapi_conversas WHERE canal='21' AND status != 'arquivado'")->fetchColumn();
    $_waNaoLidas24 = (int)db()->query("SELECT IFNULL(SUM(nao_lidas),0) FROM zapi_conversas WHERE canal='24' AND status != 'arquivado'")->fetchColumn();
    $_waFilaPendente = (int)db()->query("SELECT COUNT(*) FROM zapi_fila_envio WHERE status='pendente'")->fetchColumn();
    $_waAgendaPend = (int)db()->query("SELECT COUNT(*) FROM wa_agendamentos WHERE status='pendente'")->fetchColumn();
} catch (Exception $e) {}

// Onboarding vinculado ao usuario logado (match por email institucional)
$_onboardingToken = null;
if (!empty($user['email'])) {
    try {
        $_stmtOb = db()->prepare("SELECT token FROM colaboradores_onboarding
                                  WHERE email_institucional = ? AND status != 'arquivado'
                                  ORDER BY created_at DESC LIMIT 1");
        $_stmtOb->execute(array($user['email']));
        $_onboardingToken = $_stmtOb->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// Contagens pra badges do menu (relatorio Nilce 31/05): chamados abertos + prazos urgentes
$_audPendentes = 0;
try {
    // Solicitações de audiencista sem audiencista designada (status='aberta')
    $_audPendentes = (int)db()->query("SELECT COUNT(*) FROM audiencias WHERE status = 'aberta'")->fetchColumn();
} catch (Exception $e) {}

// Renúncia/Desistência: tarefas operacionais ainda abertas (mesmo criterio do
// modulo, na aba Operacional). Pendente = renuncia com case_task vinculada
// nao concluida.
$_renunciasPendentes = 0;
try {
    $_renunciasPendentes = (int)db()->query("SELECT COUNT(*) FROM renuncias r JOIN case_tasks t ON t.id = r.task_id WHERE t.status <> 'concluido'")->fetchColumn();
} catch (Exception $e) {}

// Pesquisa FBI $: pesquisas com status='pendente' (Luiz ainda nao concluiu)
$_fbiVinculoPendentes = 0;
try {
    $_fbiVinculoPendentes = (int)db()->query("SELECT COUNT(*) FROM fbi_vinculo_pesquisas WHERE status = 'pendente'")->fetchColumn();
} catch (Exception $e) {}

// Msg diária de acompanhamento: quantas configs ativas
$_acompDiarioAtivos = 0;
try {
    $_acompDiarioAtivos = (int)db()->query("SELECT COUNT(*) FROM acompanhamento_msg_diario WHERE ativo = 1")->fetchColumn();
} catch (Exception $e) {}

$_helpdeskAbertos = 0;
$_prazosUrgentes = 0;
try {
    $_helpdeskAbertos = (int)db()->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolvido','cancelado') AND (origem IS NULL OR origem != 'salavip')")->fetchColumn();
} catch (Exception $e) {}
try {
    // Prazos vencidos + dos proximos 3 dias nao concluidos (mesmo criterio do banner global)
    $_prazosUrgentes = (int)db()->query("SELECT COUNT(*) FROM prazos_processuais WHERE concluido = 0 AND prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn();
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
$_rolesEquipe = array('admin','gestao','comercial','cx','operacional','estagiario'); // todos exceto colaborador
$menuItems = array(
    array('section' => 'Principal'),
    array('label' => 'Painel do Dia',   'icon' => '🌅', 'href' => url('modules/painel/'),          'id' => 'painel',          'roles' => $all),
    array('label' => 'Dashboard',       'icon' => '📊', 'href' => url('modules/dashboard/'),       'id' => 'dashboard',       'roles' => $all),
    array('label' => 'Portal de Informações Rápidas', 'icon' => '⚡', 'href' => url('modules/portal/'), 'id' => 'portal', 'roles' => $all),

    array('section' => '💬 WhatsApp'),
    array('label' => 'Comercial (21)',    'icon' => '💬', 'href' => url('modules/whatsapp/?canal=21'),   'id' => 'whatsapp_21',     'roles' => $all, 'badge' => $_waNaoLidas21),
    array('label' => 'CX / Operac. (24)', 'icon' => '💬', 'href' => url('modules/whatsapp/?canal=24'),   'id' => 'whatsapp_24',     'roles' => $all, 'badge' => $_waNaoLidas24),
    array('label' => 'Caixa de Envios',   'icon' => '📬', 'href' => url('modules/whatsapp/fila.php'),    'id' => 'whatsapp_fila',   'roles' => $all, 'badge' => $_waFilaPendente, 'badgeCor' => '#b45309'),
    array('label' => 'Agendar Mensagem',  'icon' => '📅', 'href' => url('modules/agendar_msg/'),         'id' => 'agendar_msg',     'roles' => array('admin','gestao','comercial','cx','operacional','estagiario'), 'badge' => $_waAgendaPend, 'badgeCor' => '#0891b2'),
    array('label' => 'Dashboard WhatsApp', 'icon' => '📊', 'href' => url('modules/whatsapp/dashboard.php'), 'id' => 'whatsapp_dashboard', 'roles' => array('admin','gestao')),
    array('label' => 'Relatórios - CPA',  'icon' => '📈', 'href' => url('modules/whatsapp/conversas_novas.php'), 'id' => 'whatsapp_convnovas', 'roles' => array('admin','gestao')),
    array('label' => 'Configurações',     'icon' => '⚙️', 'href' => url('modules/whatsapp/central.php'),  'id' => 'whatsapp_config', 'roles' => array('admin','gestao')),

    // PAUSADO 07/06/2026 — Meta exige status Tech Provider (irreversível, R$ 20k+/ano de compliance)
    // pra Advanced Access. Amanda decidiu não seguir. Schema, módulo e webhook seguem deployados.
    // Pra ressuscitar: descomentar este bloco e ligar meta_config.meta_webhook_active=1.
    // array('section' => '📲 Redes Sociais'),
    // array('label' => 'Inbox Instagram',  'icon' => '📷', 'href' => url('modules/redes_sociais/inbox_instagram.php'),   'id' => 'redes_sociais_instagram', 'roles' => $all),
    // array('label' => 'Inbox Facebook',   'icon' => '📘', 'href' => url('modules/redes_sociais/inbox_facebook.php'),    'id' => 'redes_sociais_facebook',  'roles' => $all),
    // array('label' => 'Comentários FB',   'icon' => '💬', 'href' => url('modules/redes_sociais/comentarios_facebook.php'), 'id' => 'redes_sociais_comentarios', 'roles' => $all),
    // array('label' => 'Configuração',     'icon' => '⚙️', 'href' => url('modules/redes_sociais/setup.php'), 'id' => 'redes_sociais_config', 'roles' => array('admin','gestao')),

    array('section' => 'Atendimento'),
    array('label' => 'Helpdesk',        'icon' => '🎫', 'href' => url('modules/helpdesk/'),        'id' => 'helpdesk',        'roles' => $all, 'badge' => $_helpdeskAbertos, 'badgeCor' => '#f59e0b'),

    array('label' => 'Agenda',          'icon' => '📅', 'href' => url('modules/agenda/'),           'id' => 'agenda',          'roles' => $all),

    array('section' => '💼 Comercial'),
    array('label' => 'CRM',             'icon' => '🎯', 'href' => url('modules/crm/'),             'id' => 'crm',             'roles' => $_rolesEquipe),
    array('label' => 'CRM Comercial',   'icon' => '🔥', 'href' => url('modules/crm_comercial/'),    'id' => 'crm_comercial',   'roles' => $all),
    array('label' => 'Kanban Comercial','icon' => '📈', 'href' => url('modules/pipeline/'),         'id' => 'pipeline',        'roles' => $_rolesEquipe),
    array('label' => 'Formulários',     'icon' => '📋', 'href' => url('modules/formularios/'),      'id' => 'formularios',     'roles' => array('admin','gestao')),

    array('section' => '⚙️ Operacional'),
    array('label' => 'Kanban Operacional','icon' => '📋', 'href' => url('modules/operacional/'),    'id' => 'operacional',     'roles' => $_rolesEquipe),
    array('label' => 'Entregas Pendentes', 'icon' => '⏳', 'href' => url('modules/entregas_pendentes/'), 'id' => 'entregas_pendentes', 'roles' => array('admin','gestao','operacional','cx','estagiario')),
    array('label' => 'CRM Operacional/CX', 'icon' => '🛠️', 'href' => url('modules/crm_operacional/'), 'id' => 'crm_operacional', 'roles' => array('admin','gestao','operacional','cx','estagiario')),
    array('label' => 'Kanban PREV',    'icon' => '🏛️', 'href' => url('modules/prev/'),             'id' => 'prev',            'roles' => $all),
    array('label' => 'Processos',       'icon' => '⚖️', 'href' => url('modules/processos/'),       'id' => 'processos',       'roles' => $_rolesEquipe),
    array('label' => 'Renúncia/Desistência','icon' => '📤', 'href' => url('modules/processos/renuncias.php'), 'id' => 'processos_renuncias', 'roles' => array('admin','gestao','comercial','cx','operacional','estagiario'), 'badge' => $_renunciasPendentes, 'badgeCor' => '#b91c1c'),
    array('label' => 'Audiencistas',    'icon' => '👩‍⚖️', 'href' => url('modules/audiencistas/'),       'id' => 'audiencistas',    'roles' => array('admin','gestao','operacional','cx','estagiario'), 'badge' => $_audPendentes, 'badgeCor' => '#b87333'),
    array('label' => 'Pesquisa FBI $',  'icon' => '🔎', 'href' => url('modules/fbi_vinculo/'),              'id' => 'fbi_vinculo',           'roles' => array('admin','gestao','comercial','cx','operacional','estagiario','colaborador'), 'badge' => $_fbiVinculoPendentes, 'badgeCor' => '#0c4a6e'),
    array('label' => 'Central Intimações','icon' => '📢', 'href' => url('modules/intimacoes/'),    'id' => 'intimacoes',      'roles' => array('admin','gestao','operacional')),
    array('label' => 'Tarefas',         'icon' => '✅', 'href' => url('modules/tarefas/'),        'id' => 'tarefas',         'roles' => array('admin','gestao','operacional')),
    array('label' => 'Msg diária p/ clientes', 'icon' => '🔁', 'href' => url('modules/operacional/acomp_diario.php'), 'id' => 'acomp_diario', 'roles' => array('admin','gestao','operacional','cx','estagiario'), 'badge' => $_acompDiarioAtivos, 'badgeCor' => '#0891b2'),
    array('label' => 'Notas Pessoais',  'icon' => '📝', 'href' => url('modules/notas/'),          'id' => 'notas',           'roles' => $all),
    array('label' => 'Calc. Prazos',    'icon' => '📅', 'href' => url('modules/operacional/prazos_calc.php'), 'id' => 'prazos_calc', 'roles' => $_rolesEquipe),
    array('label' => 'Extrajudicial',   'icon' => '📝', 'href' => url('modules/servicos/'),         'id' => 'servicos',        'roles' => $_rolesEquipe),
    array('label' => 'Pré-Processual',  'icon' => '📂', 'href' => url('modules/pre_processual/'),  'id' => 'pre_processual',  'roles' => $_rolesEquipe),
    array('label' => 'Fáb. Petições',  'icon' => '📝', 'href' => url('modules/peticoes/'),         'id' => 'peticoes',        'roles' => $_rolesEquipe),
    array('label' => 'Planilha de Cálculo','icon' => '📊', 'href' => url('modules/planilha_debito/'), 'id' => 'planilha_debito', 'roles' => $_rolesEquipe),

    array('section' => '📇 Cadastros'),
    array('label' => 'Agenda de Contatos','icon' => '👥', 'href' => url('modules/clientes/'),       'id' => 'clientes',        'roles' => $all),

    array('section' => '💰 Financeiro'),
    array('label' => 'Setor Financeiro', 'icon' => '🏦', 'href' => url('modules/financeiro_interno/'), 'id' => 'financeiro_interno', 'roles' => array('admin'), 'check' => 'can_access_financeiro_interno'),
    array('label' => 'Cobrança Clientes', 'icon' => '💰', 'href' => url('modules/financeiro/'),      'id' => 'financeiro',      'roles' => array('admin','gestao','comercial')),
    array('label' => 'Cobrança Honor.', 'icon' => '⚠️', 'href' => url('modules/cobranca_honorarios/'), 'id' => 'cobranca_honorarios', 'roles' => array('admin','gestao')),

    array('section' => 'Relacionamento'),
    array('label' => 'Presença',        'icon' => '🎁', 'href' => url('modules/presenca/'),         'id' => 'presenca',        'roles' => array('admin','gestao','comercial','cx')),

    array('section' => 'Controle'),
    array('label' => 'Prazos',          'icon' => '⏰', 'href' => url('modules/prazos/'),           'id' => 'prazos',          'roles' => $_rolesEquipe, 'badge' => $_prazosUrgentes, 'badgeCor' => '#dc2626'),
    array('label' => 'Ofícios',         'icon' => '📬', 'href' => url('modules/oficios/'),          'id' => 'oficios',         'roles' => $_rolesEquipe),
    array('label' => 'Alvarás',         'icon' => '💰', 'href' => url('modules/alvaras/'),          'id' => 'alvaras',         'roles' => $_rolesEquipe),
    array('label' => 'Parceiros',       'icon' => '🤝', 'href' => url('modules/parceiros/'),        'id' => 'parceiros',       'roles' => array('admin','gestao')),
    array('label' => 'Códigos 2FA',     'icon' => '🔐', 'href' => url('modules/codigos_2fa/'),      'id' => 'codigos_2fa',     'roles' => array('admin','gestao'), 'check' => 'can_access_codigos_2fa'),

    array('section' => 'Dados'),
    array('label' => 'Documentos',      'icon' => '📜', 'href' => url('modules/documentos/'),      'id' => 'documentos',      'roles' => $_rolesEquipe),
    array('label' => 'Painel Executivo','icon' => '📈', 'href' => url('modules/executivo/'),        'id' => 'executivo',       'roles' => array('admin','gestao')),
    array('label' => 'Ranking Clientes','icon' => '🏆', 'href' => url('modules/ranking_clientes/'), 'id' => 'ranking_clientes','roles' => $_rolesEquipe),
    array('label' => 'Relatórios',      'icon' => '📉', 'href' => url('modules/relatorios/'),       'id' => 'relatorios',      'roles' => array('admin','gestao')),
    array('label' => 'Planilha',        'icon' => '📊', 'href' => url('modules/planilha/'),         'id' => 'planilha',        'roles' => $_rolesEquipe),

    array('section' => 'Comunicação'),
    array('label' => 'Mensagens',       'icon' => '💬', 'href' => url('modules/mensagens/'),        'id' => 'mensagens',       'roles' => $all),
    array('label' => 'Notificações',    'icon' => '🔔', 'href' => url('modules/notificacoes/'),     'id' => 'notificacoes',    'roles' => $all),
    array('label' => 'Notif. Clientes', 'icon' => '📲', 'href' => url('modules/notificacoes/log_cliente.php'), 'id' => 'notif_clientes', 'roles' => $_rolesEquipe),
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
    array('label' => 'Log Acessos',     'icon' => '📋', 'href' => url('modules/salavip/log.php'),      'id' => 'salavip_log',     'roles' => array('admin')),

    array('section' => 'Sistema'),
    array('label' => 'Treinamento',     'icon' => '🎓', 'href' => url('modules/treinamento/'),      'id' => 'treinamento',     'roles' => $all, 'badge' => $_treinaPendentes, 'badgeCor' => '#B87333'),
    array('label' => 'Usuários',        'icon' => '🛡️', 'href' => url('modules/usuarios/'),        'id' => 'usuarios',        'roles' => array('admin')),
    array('label' => 'Onboarding F&S', 'icon' => '👋', 'href' => url('modules/admin/onboarding.php'), 'id' => 'onboarding',   'roles' => array('admin')),
    array('label' => 'Mural Avisos',   'icon' => '📰', 'href' => url('modules/admin/onboarding_avisos.php'), 'id' => 'onboarding_avisos', 'roles' => array('admin')),
    array('label' => 'Solicitações',   'icon' => '📩', 'href' => url('modules/admin/onboarding_solicitacoes.php'), 'id' => 'onboarding_solicitacoes', 'roles' => array('admin')),
    array('label' => 'Indicações',     'icon' => '💸', 'href' => url('modules/admin/onboarding_indicacoes.php'), 'id' => 'onboarding_indicacoes', 'roles' => array('admin')),
    array('label' => 'Daily Planner',  'icon' => '📓', 'href' => url('modules/admin/onboarding_daily.php'), 'id' => 'onboarding_daily', 'roles' => array('admin')),
    array('label' => 'Seguro de Vida', 'icon' => '🛡️', 'href' => url('modules/admin/seguro_vida.php'), 'id' => 'seguro_vida', 'roles' => array('admin')),
    array('label' => 'Permissões',     'icon' => '🔐', 'href' => url('modules/admin/permissoes.php'), 'id' => 'permissoes',   'roles' => array('admin')),
    array('label' => 'Jorjão (Sinos WhatsApp)', 'icon' => '🐻', 'href' => url('modules/admin/jorjao.php'), 'id' => 'jorjao', 'roles' => array('admin'), 'keywords' => 'sino sinos grupo whatsapp comemorar contrato peticao prazo novidade resumo diario'),
    array('label' => 'Duplicatas de Cases', 'icon' => '📁', 'href' => url('modules/admin/duplicatas_cases.php'), 'id' => 'duplicatas_cases', 'roles' => array('admin','gestao'), 'keywords' => 'duplicata duplicado merge case pasta processo repetido igual mesmo'),
    array('label' => 'Comemorar Contrato', 'icon' => '🔔', 'href' => url('modules/admin/comemorar_contrato.php'), 'id' => 'comemorar_contrato', 'roles' => array('admin'), 'keywords' => 'sino jorjao grupo'),
    array('label' => 'Rastreio de Cliques', 'icon' => '🔗', 'href' => url('modules/admin/shortlinks.php'), 'id' => 'shortlinks', 'roles' => array('admin','gestao','comercial','cx','operacional','estagiario','colaborador'), 'keywords' => 'shortlinks encurtador link tracking rastreio cliques abriu engajamento lead'),
    array('label' => 'DataJud',         'icon' => '🔄', 'href' => url('modules/admin/datajud_monitor.php'), 'id' => 'datajud',  'roles' => array('admin')),
    array('label' => 'Importar DJen',   'icon' => '📥', 'href' => url('modules/admin/djen_importar.php'),  'id' => 'djen_importar', 'roles' => array('admin')),
    array('label' => 'Andamentos Monitor', 'icon' => '📧', 'href' => url('modules/email_monitor.php'), 'id' => 'email_monitor', 'roles' => array('admin')),
    array('label' => 'Claudin (Monitor DJEN)', 'icon' => '🤖', 'href' => url('modules/admin/claudin_dashboard.php'), 'id' => 'claudin_dashboard', 'roles' => array('admin')),
    array('label' => 'Claudin — Backfill', 'icon' => '🔄', 'href' => url('modules/admin/claudin_backfill.php'), 'id' => 'claudin_backfill', 'roles' => array('admin')),
    array('label' => 'Claudin — Diag', 'icon' => '🔍', 'href' => url('modules/admin/claudin_diag.php'), 'id' => 'claudin_diag', 'roles' => array('admin')),
    array('label' => 'Importar Endereços', 'icon' => '📍', 'href' => url('modules/admin/importar_enderecos.php'), 'id' => 'importar_enderecos', 'roles' => array('admin')),
    array('label' => 'WhatsApp dedup',  'icon' => '🔀', 'href' => url('modules/admin/whatsapp_dedup.php'), 'id' => 'whatsapp_dedup', 'roles' => array('admin')),
    array('label' => 'WhatsApp Saúde',  'icon' => '🩺', 'href' => url('modules/admin/diag_wa.php'),       'id' => 'diag_wa',        'roles' => array('admin')),
    array('label' => 'WA Backup Pendente', 'icon' => '📞', 'href' => url('modules/admin/wa_pendentes.php'), 'id' => 'wa_pendentes',   'roles' => array('admin','gestao')),
    array('label' => 'WhatsApp Config',  'icon' => '🔍', 'href' => url('modules/admin/diag_wa_config.php'), 'id' => 'diag_wa_config', 'roles' => array('admin')),
    array('label' => 'IA — Custo',      'icon' => '🤖', 'href' => url('modules/admin/ia_custo.php'),       'id' => 'ia_custo',       'roles' => array('admin')),
    array('label' => 'Health Check',    'icon' => '🩺', 'href' => url('modules/admin/health.php'),  'id' => 'admin',           'roles' => array('admin')),
);

// Insercao dinamica do item "Boas-Vindas" — aparece para QUALQUER usuario que
// tenha cadastro ativo de onboarding (vinculado pelo email institucional).
// Inserido logo apos o item "Treinamento" pra ficar na secao Sistema.
if (!empty($_onboardingToken)) {
    $itemOnb = array(
        'label' => 'Boas-Vindas', 'icon' => '👋',
        'href' => url('modules/onboarding/'),
        'id' => 'onboarding_pessoal', 'roles' => $all,
    );
    // Acha posicao do "Treinamento" e insere logo depois
    $insIdx = null;
    foreach ($menuItems as $i => $it) {
        if (isset($it['id']) && $it['id'] === 'treinamento') { $insIdx = $i + 1; break; }
    }
    if ($insIdx !== null) {
        array_splice($menuItems, $insIdx, 0, array($itemOnb));
    } else {
        $menuItems[] = $itemOnb;
    }
}
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
/* Fix principal: quando sidebar colapsa, main-content precisa reduzir margin-left.
   Antes: ficava com margin-left:260px fixo, deixando gap entre sidebar (60px) e conteúdo. */
body.sidebar-collapsed .main-content { margin-left:60px !important; transition: margin-left .15s; }
.main-content { transition: margin-left .15s; }
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
/* Fix leitura no dark mode: cards/blocos com inline style de fundo branco viram escuros */
body.dark-mode [style*="background:#fff"],
body.dark-mode [style*="background: #fff"],
body.dark-mode [style*="background:#ffffff"],
body.dark-mode [style*="background:white"],
body.dark-mode [style*="background-color:#fff"],
body.dark-mode [style*="background-color:#ffffff"] { background:var(--bg-card) !important; color:var(--text) !important; }
body.dark-mode [style*="background:#f9fafb"],
body.dark-mode [style*="background:#f3f4f6"],
body.dark-mode [style*="background:#f4f4f5"],
body.dark-mode [style*="background:#fafbfc"],
body.dark-mode [style*="background:#f5f5f5"] { background:var(--bg-secondary) !important; color:var(--text) !important; }
body.dark-mode [style*="background:#fafafa"] { background:var(--bg-secondary) !important; color:var(--text) !important; }
/* Textos cinza muito claros no light ficam invisíveis no dark — forçar claros */
body.dark-mode [style*="color:#374151"],
body.dark-mode [style*="color:#1f2937"],
body.dark-mode [style*="color:#111827"],
body.dark-mode [style*="color:#052228"],
body.dark-mode [style*="color:#1e293b"],
body.dark-mode [style*="color:#0f172a"],
body.dark-mode [style*="color:#18181b"] { color:var(--text) !important; }
body.dark-mode [style*="color:var(--petrol-900)"] { color:#d1d5db !important; }
body.dark-mode [style*="color:#6b7280"],
body.dark-mode [style*="color:#64748b"] { color:#a0aec0 !important; }
/* Tabelas com thead/tbody sem classe tbl-grid */
body.dark-mode thead tr[style*="background:#f9fafb"] { background:var(--bg-secondary) !important; }
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
                // Suporte a função custom de check (precede roles) — usado por features com
                // whitelist específica (ex.: Códigos 2FA permite Naiara/Carina mesmo sem ser admin/gestao)
                if (isset($item['check']) && function_exists($item['check'])) {
                    $showItem = call_user_func($item['check']);
                } elseif (function_exists('can_access') && isset($item['id'])) {
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
            <div class="sidebar-section sidebar-section-header" data-section="<?= $sectionSlug ?>" onclick="expandirSidebarSection('<?= $sectionSlug ?>')" title="Clique para expandir esta seção (use o ▾ para recolher)">
                <span><?= e($group['name']) ?></span>
                <span class="sidebar-section-chevron" id="chv_<?= $sectionSlug ?>" onclick="event.stopPropagation(); toggleSidebarSection('<?= $sectionSlug ?>')" title="Recolher / expandir" style="cursor:pointer;padding:.15rem .35rem;">▾</span>
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
                    <div class="sidebar-item-row" data-keywords="<?= e($item['keywords'] ?? '') ?>">
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
        <a href="<?= url('modules/admin/meu_2fa.php') ?>" class="btn-logout" title="Meu 2FA — autenticação em 2 etapas" style="margin-right:.25rem;">🔐</a>
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

    // Detecta estado
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true
                    || document.referrer.indexOf('android-app://') === 0;
    var ua = navigator.userAgent || '';
    var isiOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    var isAndroid = /Android/i.test(ua);
    var hasPrompt = !!window._fsaDeferredPrompt;

    var html = '';

    // 1) Alerta inicial se a detecção reportou "já instalado" mas usuária não acha
    if (isStandalone) {
        html += '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:.7rem .9rem;margin-bottom:.8rem;color:#9a3412;font-size:.8rem;">'
             + '⚠️ <strong>Detectei que está em modo app agora.</strong> Se não acha o ícone no dispositivo, a instalação anterior pode estar "fantasma". Siga as instruções abaixo pra reinstalar direito.'
             + '</div>';
    }

    // 2) Botão nativo (se disponível) — sempre oferecer como primeira opção
    if (hasPrompt) {
        html += '<p style="margin:0 0 .5rem;font-weight:600;">Instalação rápida:</p>'
             + '<button id="fsaInstalarGo" style="background:#B87333;color:#fff;border:none;padding:.7rem 1.2rem;border-radius:8px;font-weight:700;cursor:pointer;width:100%;font-size:.88rem;margin-bottom:1rem;">📲 Instalar agora</button>';
    }

    // 3) Instruções manuais por plataforma — sempre visíveis
    if (isiOS) {
        html += '<p style="margin:.5rem 0 .4rem;font-weight:600;">📱 iPhone/iPad (Safari):</p>'
             + '<ol style="padding-left:1.2rem;margin:0 0 .8rem;font-size:.85rem;">'
             + '<li>Toque no ícone <strong>Compartilhar</strong> (quadrado com seta pra cima, barra inferior do Safari)</li>'
             + '<li>Role até <strong>"Adicionar à Tela de Início"</strong></li>'
             + '<li>Toque em <strong>Adicionar</strong> (canto superior direito)</li>'
             + '</ol>'
             + '<p style="margin:.5rem 0 .4rem;font-weight:600;">Se já instalou antes mas não acha:</p>'
             + '<ol style="padding-left:1.2rem;margin:0;font-size:.85rem;">'
             + '<li>Tela inicial → deslize pra esquerda até a <strong>Biblioteca de Apps</strong></li>'
             + '<li>Ou na tela inicial: deslize pra baixo → busque <strong>"F&amp;S"</strong> ou <strong>"Hub"</strong> ou <strong>"Ferreira"</strong></li>'
             + '<li>Se encontrar, segure o ícone → <strong>"Adicionar à Tela de Início"</strong></li>'
             + '</ol>';
    } else if (isAndroid) {
        html += '<p style="margin:.5rem 0 .4rem;font-weight:600;">🤖 Android (Chrome):</p>'
             + '<ol style="padding-left:1.2rem;margin:0 0 .8rem;font-size:.85rem;">'
             + '<li>Toque no menu do Chrome (<strong>⋮</strong> no canto superior direito)</li>'
             + '<li>Toque em <strong>"Instalar app"</strong> ou <strong>"Adicionar à tela inicial"</strong></li>'
             + '<li>Confirme em <strong>Instalar</strong></li>'
             + '</ol>'
             + '<p style="margin:.5rem 0 .4rem;font-weight:600;">Se já instalou mas não acha:</p>'
             + '<ol style="padding-left:1.2rem;margin:0 0 .8rem;font-size:.85rem;">'
             + '<li>Deslize pra cima da tela inicial pra abrir a <strong>gaveta de apps</strong></li>'
             + '<li>Na busca no topo, digite <strong>"F&amp;S"</strong> ou <strong>"Hub"</strong></li>'
             + '<li>Se aparecer, segure e arraste pra tela inicial</li>'
             + '</ol>'
             + '<p style="margin:.5rem 0 .4rem;font-weight:600;color:#dc2626;">Se realmente não existe (instalação fantasma):</p>'
             + '<ol style="padding-left:1.2rem;margin:0;font-size:.85rem;">'
             + '<li><strong>Configurações → Apps</strong> → procure "Hub" ou "Chrome" → veja apps conectados</li>'
             + '<li>Ou: Chrome → <strong>chrome://apps</strong> → se aparecer F&amp;S Hub, toque e segure → <strong>Desinstalar</strong></li>'
             + '<li>Depois volte aqui e clique em <strong>Instalar agora</strong> de novo</li>'
             + '</ol>';
    } else {
        html += '<p style="margin:.5rem 0 .4rem;font-weight:600;">💻 Computador (Chrome/Edge):</p>'
             + '<ol style="padding-left:1.2rem;margin:0;font-size:.85rem;">'
             + '<li>Barra de endereço: clique em <strong>⊕ Instalar</strong> à direita da URL</li>'
             + '<li>Ou menu <strong>⋮</strong> → <strong>"Instalar F&amp;S Hub"</strong></li>'
             + '<li>Confirme em <strong>Instalar</strong></li>'
             + '</ol>';
    }

    // 4) Rodapé: estado detectado (ajuda pra diagnóstico)
    html += '<details style="margin-top:1rem;font-size:.72rem;color:#64748b;">'
         + '<summary style="cursor:pointer;color:#94a3b8;">Estado detectado</summary>'
         + '<div style="padding:.4rem 0 0;font-family:monospace;">'
         + 'Plataforma: ' + (isiOS ? 'iOS' : (isAndroid ? 'Android' : 'Desktop')) + '<br>'
         + 'Standalone: ' + (isStandalone ? 'sim' : 'não') + '<br>'
         + 'Prompt nativo disponível: ' + (hasPrompt ? 'sim' : 'não') + '<br>'
         + 'UA: ' + ua.substring(0, 80)
         + '</div></details>';

    body.innerHTML = html;

    if (hasPrompt && document.getElementById('fsaInstalarGo')) {
        document.getElementById('fsaInstalarGo').onclick = function() {
            this.textContent = 'Aguardando...';
            this.disabled = true;
            window._fsaDeferredPrompt.prompt();
            window._fsaDeferredPrompt.userChoice.then(function(choice) {
                if (choice.outcome === 'accepted') {
                    body.innerHTML = '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.8rem 1rem;color:#166534;">✅ Instalado! Procure o ícone na tela inicial ou gaveta de apps do seu dispositivo.</div>';
                    localStorage.removeItem('fsa_install_dispensado');
                } else {
                    body.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:.8rem 1rem;color:#991b1b;">Instalação cancelada. Clique de novo no botão "Instalar como App" se quiser tentar outra vez.</div>';
                }
                window._fsaDeferredPrompt = null;
            });
        };
    }

    modal.style.display = 'flex';
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

/* Barra de favoritos no topo (sticky logo abaixo do topbar)
   Amanda 09/07/2026: quando ha muitos favoritos, wrap manda pra 2a linha.
   Row-gap explicito + padding vertical maior deixa claro que eh intencional
   (Amanda achou que 'Prazos'/'Agenda' em 2a linha parecia bug — na verdade
   sao os ultimos favoritos que nao couberam na 1a linha). */
.fav-bar { display:flex; align-items:center; column-gap:.4rem; row-gap:.45rem; padding:.45rem .8rem; background:var(--bg-card); border-bottom:1px solid var(--border); flex-wrap:wrap; font-size:.72rem; min-height:32px; position:sticky; top:var(--topbar-h); z-index:40; box-shadow:var(--shadow-sm); }
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
<?php
// ── Favoritos do usuário vindos do SERVIDOR (seguem em qualquer PC) ──
$__favsServidor = array();
try {
    $__uidFav = current_user_id();
    if ($__uidFav) {
        $__stFav = db()->prepare("SELECT fav_id AS id, label, icon, href FROM user_favoritos WHERE user_id = ? ORDER BY ordem, id");
        $__stFav->execute(array($__uidFav));
        $__favsServidor = $__stFav->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $__e) { /* tabela pode não existir ainda */ }
?>
window.FSA_FAVORITOS = <?= json_encode($__favsServidor ?: array(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.FSA_FAVS_URL  = '<?= url('api/favoritos.php') ?>';

// ── Colapsar sidebar ──
function toggleSidebarCollapse() {
    var sb = document.getElementById('sidebar');
    sb.classList.toggle('collapsed');
    // Sincroniza classe no body — usado pro CSS do .main-content ajustar margin
    document.body.classList.toggle('sidebar-collapsed', sb.classList.contains('collapsed'));
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
// Clique no NOME da secao SO expande (nunca recolhe). Recolher = clicar
// no ▾ explicitamente. Antes, clicar acidentalmente no label do PRINCIPAL
// recolhia tudo e confundia (Amanda relatou 30/05/2026).
function expandirSidebarSection(slug) {
    var header = document.querySelector('[data-section="' + slug + '"]');
    var items = document.getElementById('items_' + slug);
    if (!header || !items) return;
    items.classList.remove('collapsed');
    header.classList.remove('collapsed');
    try {
        var state = JSON.parse(localStorage.getItem('sidebar_sections') || '{}');
        state[slug] = 0;
        localStorage.setItem('sidebar_sections', JSON.stringify(state));
    } catch(e) {}
}

// Amanda 10/06/2026: normaliza pra remover acentos — 'calculo' acha 'Cálculo'
function sbNorm(s) {
    // ̀-ͯ = bloco "Combining Diacritical Marks" (cedilha + acentos separados pelo NFD)
    return (s || '').toString().toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '');
}

// ── Busca no menu lateral ──
function sidebarFiltrar(q) {
    q = (q || '').trim().toLowerCase();
    var qNorm = sbNorm(q);
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
        var textoNorm = sbNorm(label.dataset.original);
        // Amanda 07/07/2026: casa também nas keywords ocultas (data-keywords).
        // Útil pra sinônimos técnicos ('shortlinks' encontra 'Rastreio de Cliques').
        var keywords = (row.dataset.keywords || '').toLowerCase();
        // Casa COM ou SEM acentos: 'cal' acha 'Cálculo', 'cál' tambem
        var bate = texto.indexOf(q) !== -1 || textoNorm.indexOf(qNorm) !== -1
                || (keywords && (keywords.indexOf(q) !== -1 || sbNorm(keywords).indexOf(qNorm) !== -1));
        row.style.display = bate ? '' : 'none';
        if (bate) {
            totalVisiveis++;
            // Highlight: tenta com o termo original primeiro, senao casa por posicao no normalizado
            var reOrig = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
            if (texto.indexOf(q) !== -1) {
                label.innerHTML = label.dataset.original.replace(reOrig, '<span class="sidebar-highlight">$1</span>');
            } else {
                // Match foi por normalizacao — pega a posicao e marca a fatia equivalente do original
                var pos = textoNorm.indexOf(qNorm);
                if (pos !== -1) {
                    var antes = label.dataset.original.slice(0, pos);
                    var meio  = label.dataset.original.slice(pos, pos + qNorm.length);
                    var depois = label.dataset.original.slice(pos + qNorm.length);
                    label.innerHTML = antes + '<span class="sidebar-highlight">' + meio + '</span>' + depois;
                } else {
                    label.innerHTML = label.dataset.original;
                }
            }
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

// ── Favoritos (persistidos no SERVIDOR, por usuário) ──
// Fonte da verdade: window.FSA_FAVORITOS (vem do banco no load). localStorage
// vira só cache local (e fallback de migração do modelo antigo).
function getFavoritos() {
    if (window.FSA_FAVORITOS && Array.isArray(window.FSA_FAVORITOS)) return window.FSA_FAVORITOS;
    try { return JSON.parse(localStorage.getItem('sidebar_favoritos') || '[]'); } catch(e) { return []; }
}
function saveFavoritos(list) {
    window.FSA_FAVORITOS = list;
    try { localStorage.setItem('sidebar_favoritos', JSON.stringify(list)); } catch(e) {} // cache
    // Persiste no servidor pra seguir o usuário em qualquer máquina
    persistirFavoritosServidor(list);
}
function persistirFavoritosServidor(list) {
    if (!window.FSA_FAVS_URL) return;
    try {
        var body = new URLSearchParams();
        body.set('csrf_token', window._FSA_CSRF || window.FSA_CSRF || '');
        body.set('favoritos', JSON.stringify(list));
        fetch(window.FSA_FAVS_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).catch(function(){ /* offline: fica o cache local, sincroniza no próximo toggle */ });
    } catch(e) {}
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
        if (favs.length >= 20) { alert('Você já tem 20 favoritos (limite máximo). Remova um antes de adicionar.'); return; }
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
            document.body.classList.add('sidebar-collapsed');
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
        // Migração 1x: servidor vazio + favoritos antigos no localStorage -> sobe
        // pro servidor (preserva quem já tinha favoritos nesta máquina).
        try {
            var srv = (window.FSA_FAVORITOS && Array.isArray(window.FSA_FAVORITOS)) ? window.FSA_FAVORITOS : [];
            if (srv.length === 0) {
                var local = JSON.parse(localStorage.getItem('sidebar_favoritos') || '[]');
                if (local && local.length) {
                    window.FSA_FAVORITOS = local;
                    persistirFavoritosServidor(local);
                }
            }
        } catch(e) {}
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
