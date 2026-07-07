<?php
/**
 * Central de ferramentas admin. Amanda 06/07/2026: criado pra evitar
 * 403 quando alguem entra em /modules/admin/ direto (LiteSpeed bloqueia
 * listing de pasta). Lista os modulos admin agrupados por finalidade.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('admin')) {
    flash_set('error', 'Área restrita ao Admin.');
    redirect(url('modules/dashboard/index.php'));
}

$pageTitle = 'Central Admin';

$grupos = array(
    '🔔 WhatsApp & Comunicação' => array(
        array('jorjao.php',              '🐻', 'Jorjão — Sinos WhatsApp',       'Configurar tocadas automáticas no grupo (petição distribuída, prazo cumprido, novidade no Hub, resumo diário)'),
        array('comemorar_contrato.php',  '🔔', 'Comemorar Contrato',            'Configuração original do sino de contrato assinado'),
        array('wa_pendentes.php',        '📞', 'WA Backup Pendente',            'Arquivos WhatsApp sem cliente/case pra vincular manualmente'),
        array('diag_wa.php',             '🩺', 'WhatsApp Saúde',                'Diagnóstico da instância Z-API'),
        array('diag_wa_config.php',      '🔍', 'WhatsApp Config',               'Ver/editar configuração da Z-API'),
    ),
    '🤖 IA & Automação' => array(
        array('ia_custo.php',            '💰', 'IA — Custo & Killswitches',    'Ligar/desligar features de IA e ver quanto foi gasto'),
        array('claudin_dashboard.php',   '🤖', 'Claudin (Monitor DJEN)',        'Painel do Claudin — classificação de publicações'),
        array('claudin_backfill.php',    '🔄', 'Claudin — Backfill',            'Reprocessar publicações antigas'),
        array('claudin_diag.php',        '🔬', 'Claudin — Diag',                'Diagnóstico do classificador'),
        array('reconciliar_kanbans.php', '⚖️', 'Reconciliar Kanbans',           'Ferramenta pra alinhar Kanban Comercial ↔ Operacional'),
    ),
    '📇 Cadastros & Onboarding' => array(
        array('onboarding.php',          '👋', 'Onboarding F&S',                'Painel do onboarding de colaboradores'),
        array('onboarding_avisos.php',   '📰', 'Mural de Avisos',               'Comunicados internos'),
        array('onboarding_solicitacoes.php','📩','Solicitações',                 'Solicitações de colaboradores'),
        array('onboarding_indicacoes.php','💸','Indicações',                    'Programa de indicação de novos clientes'),
        array('onboarding_daily.php',    '📓', 'Daily Planner',                  'Planejamento diário dos colaboradores'),
        array('seguro_vida.php',         '🛡️', 'Seguro de Vida',                'Ficha do seguro de vida da equipe'),
    ),
    '⚖️ Judicial & Processos' => array(
        array('datajud.php',             '🔄', 'DataJud',                       'Sincronização com o DataJud CNJ'),
        array('datajud_monitor.php',     '📡', 'DataJud Monitor',               'Painel de monitoramento da sincronização'),
        array('djen_importar.php',       '📥', 'Importar DJEN',                 'Importação manual do Diário de Justiça Eletrônico'),
    ),
    '🔧 Sistema & Segurança' => array(
        array('permissoes.php',          '🔐', 'Permissões',                    'Gerenciar acesso a módulos por usuário'),
        array('health.php',              '❤️', 'Saúde do Sistema',              'Status geral do Hub'),
        array('importar_enderecos.php',  '📍', 'Importar Endereços',            'Import em lote de endereços de clientes'),
        array('asaas_config.php',        '💳', 'Asaas — Configuração',          'Configurar integração com Asaas (cobrança)'),
    ),
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ca-wrap { max-width: 1100px; margin: 0 auto; }
.ca-hero { background: linear-gradient(135deg,#052228,#0d3640); color:#fff; padding: 1.4rem 1.6rem; border-radius: 14px; margin-bottom: 1.5rem; }
.ca-hero h1 { font-family: 'Cormorant Garamond', serif; font-size: 1.9rem; margin: 0 0 .3rem; font-weight: 600; color:#fff; }
.ca-hero p { margin: 0; font-size: .88rem; opacity: .85; }

.ca-secao { margin-bottom: 2rem; }
.ca-secao h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.35rem; color:#052228; margin: 0 0 .8rem; font-weight: 600; border-bottom: 1px solid #e5e7eb; padding-bottom: .4rem; }

.ca-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: .85rem; }
.ca-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding: 1rem 1.15rem; text-decoration:none; color:inherit; transition: all .15s; display:flex; gap:.7rem; align-items:flex-start; }
.ca-card:hover { border-color:#B87333; box-shadow:0 6px 16px rgba(184,115,51,.12); transform: translateY(-2px); }
.ca-card .ico { font-size: 1.6rem; line-height: 1; flex-shrink: 0; }
.ca-card .info { flex:1; min-width: 0; }
.ca-card h3 { font-family: 'Outfit', sans-serif; font-size: .93rem; margin: 0 0 .25rem; color:#052228; font-weight: 700; }
.ca-card p { margin: 0; font-size: .74rem; color:#6b7280; line-height: 1.4; }
</style>

<div class="ca-wrap">
    <div class="ca-hero">
        <h1>Central Admin</h1>
        <p>Ferramentas administrativas do Hub — só quem é admin vê. Escolhe abaixo.</p>
    </div>

    <?php foreach ($grupos as $titulo => $tools): ?>
    <div class="ca-secao">
        <h2><?= e($titulo) ?></h2>
        <div class="ca-grid">
            <?php foreach ($tools as $t):
                list($file, $ico, $nome, $desc) = $t;
                if (!file_exists(__DIR__ . '/' . $file)) continue;
            ?>
            <a href="<?= url('modules/admin/' . $file) ?>" class="ca-card">
                <span class="ico"><?= e($ico) ?></span>
                <div class="info">
                    <h3><?= e($nome) ?></h3>
                    <p><?= e($desc) ?></p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
