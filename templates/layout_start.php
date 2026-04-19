<?php
/**
 * Layout Start — inclui header + sidebar + abre main content
 *
 * Variáveis disponíveis (definir antes do require):
 *   $pageTitle  — título da página (obrigatório)
 *   $extraCss   — CSS inline adicional (opcional)
 */

require_once APP_ROOT . '/templates/header.php';
require_once APP_ROOT . '/templates/sidebar.php';
?>

<div class="app-layout">
    <main class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="btn-sidebar-toggle" id="sidebarToggle">☰</button>
                <h1 class="topbar-title"><?= e($pageTitle ?? 'Painel') ?></h1>
            </div>
            <div class="topbar-right">
                <!-- Links Jurídicos -->
                <div style="position:relative;" id="ljWrap">
                    <button id="ljBtn" onclick="document.getElementById('ljDrop').classList.toggle('lj-open');document.getElementById('ljBusca').value='';ljFiltrar('');" style="background:none;border:none;cursor:pointer;font-size:1rem;padding:4px 8px;border-radius:6px;color:var(--petrol-900);display:flex;align-items:center;gap:4px;" title="Links Jurídicos">
                        ⚖️ <span style="font-size:.72rem;font-weight:600;display:none;" class="lj-label-desk">Links</span>
                    </button>
                    <div id="ljDrop" style="display:none;position:absolute;right:0;top:calc(100% + 6px);width:380px;max-height:520px;background:#052228;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.4);z-index:9999;overflow:hidden;flex-direction:column;">
                        <div style="padding:.6rem .8rem;border-bottom:1px solid rgba(255,255,255,.1);">
                            <div style="position:relative;">
                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:.8rem;opacity:.5;">🔍</span>
                                <input type="text" id="ljBusca" placeholder="Buscar tribunal..." oninput="ljFiltrar(this.value)" style="width:100%;padding:.5rem .6rem .5rem 30px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:8px;color:#fff;font-size:.8rem;outline:none;" autocomplete="off">
                            </div>
                        </div>
                        <div id="ljBody" style="overflow-y:auto;max-height:440px;padding:.4rem 0;"></div>
                    </div>
                </div>
                <style>
                #ljDrop.lj-open{display:flex!important}
                .lj-cat{padding:.3rem .8rem;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#B87333;margin-top:.3rem;}
                .lj-link{display:block;padding:.4rem .8rem .4rem 1.4rem;color:rgba(255,255,255,.85);text-decoration:none;font-size:.78rem;transition:all .12s;}
                .lj-link:hover{background:#B87333;color:#fff;}
                .lj-link::before{content:'•';margin-right:6px;opacity:.4;}
                .lj-none{padding:1.5rem;text-align:center;color:rgba(255,255,255,.4);font-size:.8rem;}
                @media(max-width:600px){#ljDrop{width:calc(100vw - 20px);right:-50px;}}
                @media(min-width:768px){.lj-label-desk{display:inline!important;}}
                </style>
                <script>
                var _ljData=[
                {c:'Rio de Janeiro',n:'Comunica PJE',u:'https://comunica.pje.jus.br/'},
                {c:'Rio de Janeiro',n:'EProc TJRJ',u:'https://eproc1g.tjrj.jus.br/eproc/'},
                {c:'Rio de Janeiro',n:'TJRJ - DCP',u:'https://www3.tjrj.jus.br/idserverjus-front/#/login?indGet=true&sgSist=PORTALSERVICOS'},
                {c:'Rio de Janeiro',n:'TJRJ - PJe',u:'https://tjrj.pje.jus.br/1g/login.seam?loginComCertificado=false'},
                {c:'Rio de Janeiro',n:'Fóruns Regionais RJ',u:'https://www.tjrj.jus.br/web/cgj/foruns-regionais-capital'},
                {c:'Rio de Janeiro',n:'Balcão Virtual TJRJ',u:'https://www.tjrj.jus.br/web/guest/balcao-virtual'},
                {c:'Rio de Janeiro',n:'Regionais Infância TJRJ',u:'https://cgj.tjrj.jus.br/abrangencia-contato-vijis-comissarios'},
                {c:'Rio de Janeiro',n:'Mediação e Conciliação Pré-Processual',u:'https://www.tjrj.jus.br/web/guest/institucional/mediacao/pre-processual'},
                {c:'Federal / TRF',n:'TRF 2ª Região - RJ',u:'https://eproc.trf2.jus.br/eproc/'},
                {c:'Federal / TRF',n:'Justiça Federal 2ª Região - RJ',u:'https://eproc.jfrj.jus.br/eproc/controlador.php?acao=painel_adv_listar&acao_origem=principal&hash=df59f03e0b8579a4d0fd7ee2a0677c7b'},
                {c:'Federal / TRF',n:'TRF 3ª Região - SP',u:'https://www.trf3.jus.br/pje'},
                {c:'Federal / TRF',n:'TRF 3ª Região SP - JEF',u:'https://pje1g.trf3.jus.br/pje/login.seam'},
                {c:'Federal / TRF',n:'TRF 5ª Região - Ceará JEF',u:'https://pje1g.trf5.jus.br/pje/login.seam'},
                {c:'Federal / TRF',n:'Balcão Virtual Federal Ceará',u:'https://painelcentralsistemas.jfce.jus.br/painelcentralsistemas/'},
                {c:'Outros Estados',n:'TJMG - eProc',u:'https://eproc1g.tjmg.jus.br/eproc/'},
                {c:'Outros Estados',n:'TJMG - PJe 1ª Instância',u:'https://pje.tjmg.jus.br/pje/login.seam'},
                {c:'Outros Estados',n:'TJMG - PJe 2ª Instância',u:'https://www.tjmg.jus.br/portal-tjmg/processos/jpe-themis-processo-eletronico-de-2-instancia/'},
                {c:'Outros Estados',n:'TJMG - Projud Juizados BH',u:'https://www.tjmg.jus.br/portal-tjmg/processos/projudi-processo-eletronico-de-juizados-especiais/'},
                {c:'Outros Estados',n:'TJSP - Sistema',u:'https://esaj.tjsp.jus.br/esaj/portal.do?servico=820000'},
                {c:'Outros Estados',n:'TJPR - PROJUD',u:'https://projudi.tjpr.jus.br/projudi/'},
                {c:'Outros Estados',n:'TJRN',u:'https://pje1g.tjrn.jus.br/pje/login.seam'},
                {c:'Outros Estados',n:'TJSE',u:'https://www.tjse.jus.br/portaldoadvogado/'},
                {c:'Outros Estados',n:'TJES',u:'https://pje.tjes.jus.br/pje/login.seam'},
                {c:'Outros Estados',n:'TJRS',u:'https://eproc1g.tjrs.jus.br/eproc/externo_controlador.php?acao=principal&sigla_orgao_sistema=TJRS&sigla_sistema=Eproc'},
                {c:'Portais Gerais',n:'Portal Concentração de Prazos',u:'https://comunica.pje.jus.br/'},
                {c:'Portais Gerais',n:'JUS.BR',u:'https://jus.br'},
                {c:'Portais Gerais',n:'STJ',u:'https://cpe.web.stj.jus.br/'}
                ];
                function ljFiltrar(q) {
                    var body = document.getElementById('ljBody');
                    q = q.toLowerCase();
                    var cats = {};
                    _ljData.forEach(function(l) {
                        if (q && l.n.toLowerCase().indexOf(q) === -1 && l.c.toLowerCase().indexOf(q) === -1) return;
                        if (!cats[l.c]) cats[l.c] = [];
                        cats[l.c].push(l);
                    });
                    var html = '';
                    var keys = Object.keys(cats);
                    if (keys.length === 0) { body.innerHTML = '<div class="lj-none">Nenhum resultado</div>'; return; }
                    keys.forEach(function(cat) {
                        html += '<div class="lj-cat">📍 ' + cat + '</div>';
                        cats[cat].forEach(function(l) {
                            html += '<a class="lj-link" href="' + l.u + '" target="_blank" rel="noopener">' + l.n + '</a>';
                        });
                    });
                    body.innerHTML = html;
                }
                ljFiltrar('');
                document.addEventListener('click', function(e) {
                    var wrap = document.getElementById('ljWrap');
                    if (wrap && !wrap.contains(e.target)) {
                        document.getElementById('ljDrop').classList.remove('lj-open');
                    }
                });
                </script>

                <?php $unreadCount = count_unread_notifications(); ?>
                <div class="notif-wrapper" id="notifWrapper">
                    <button class="notif-bell" id="notifBell" title="Notificações">
                        🔔
                        <?php if ($unreadCount > 0): ?>
                            <span class="notif-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-dropdown-header">
                            <strong>Notificações</strong>
                            <?php if ($unreadCount > 0): ?>
                                <a href="<?= url('modules/notificacoes/api.php?action=read_all') ?>" class="notif-mark-all">Marcar todas como lidas</a>
                            <?php endif; ?>
                        </div>
                        <div class="notif-dropdown-body">
                            <?php
                            $recentNotifs = get_notifications(8);
                            if (empty($recentNotifs)):
                            ?>
                                <div class="notif-empty">Nenhuma notificação</div>
                            <?php else: ?>
                                <?php foreach ($recentNotifs as $n):
                                    $typeIcons = array('info' => '💬', 'alerta' => '⚠️', 'sucesso' => '✅', 'pendencia' => '📋', 'urgencia' => '🔴');
                                    $nIcon = $n['icon'] ? $n['icon'] : (isset($typeIcons[$n['type']]) ? $typeIcons[$n['type']] : '💬');
                                    $nClass = $n['is_read'] ? 'notif-item read' : 'notif-item';
                                    $diff = time() - strtotime($n['created_at']);
                                    if ($diff < 60) $ago = 'agora';
                                    elseif ($diff < 3600) $ago = floor($diff/60) . 'min';
                                    elseif ($diff < 86400) $ago = floor($diff/3600) . 'h';
                                    else $ago = floor($diff/86400) . 'd';
                                ?>
                                <a href="<?= $n['link'] ? e($n['link']) . (strpos($n['link'],'?') !== false ? '&' : '?') . 'notif_id=' . $n['id'] : url('modules/notificacoes/?read=' . $n['id']) ?>" class="<?= $nClass ?>">
                                    <span class="notif-icon"><?= $nIcon ?></span>
                                    <div class="notif-content">
                                        <div class="notif-title"><?= e($n['title']) ?></div>
                                        <?php if ($n['message']): ?>
                                            <div class="notif-msg"><?= e(mb_substr($n['message'], 0, 60, 'UTF-8')) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="notif-time"><?= $ago ?></span>
                                </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="notif-dropdown-footer">
                            <a href="<?= url('modules/notificacoes/') ?>">Ver todas</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barra de favoritos (populada via JS / localStorage) -->
        <div class="fav-bar" id="favBar"></div>

        <div class="page-content">
            <?= flash_html() ?>
<?php
// Banner de prazos urgentes (próximos 3 dias) — visível em todas as páginas
try {
    $__userId = current_user_id();
    $__role = current_user_role();
    $__prazosUrgentes = array();
    if (in_array($__role, array('admin','gestao','operacional'))) {
        $__stmtPz = db()->prepare(
            "SELECT p.id, p.descricao_acao, p.prazo_fatal, p.numero_processo, p.case_id,
                    cs.title as case_title, cl.name as client_name
             FROM prazos_processuais p
             LEFT JOIN cases cs ON cs.id = p.case_id
             LEFT JOIN clients cl ON cl.id = p.client_id
             WHERE p.concluido = 0 AND p.prazo_fatal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
             ORDER BY p.prazo_fatal ASC LIMIT 10"
        );
        $__stmtPz->execute();
        $__prazosUrgentes = $__stmtPz->fetchAll();
    }
} catch (Exception $e) { $__prazosUrgentes = array(); }
if (!empty($__prazosUrgentes)):
?>
<div style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:10px;padding:.6rem 1rem;margin-bottom:.75rem;font-size:.78rem;">
    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.3rem;">
        <span style="font-size:1rem;">🚨</span>
        <strong><?= count($__prazosUrgentes) ?> prazo(s) nos próximos 3 dias!</strong>
        <a href="<?= url('modules/prazos/') ?>" style="color:#fecaca;margin-left:auto;font-size:.7rem;text-decoration:underline;">Ver todos →</a>
    </div>
    <?php foreach ($__prazosUrgentes as $__pz):
        $__diasPz = (int)((strtotime($__pz['prazo_fatal']) - strtotime(date('Y-m-d'))) / 86400);
        $__urgLabel = $__diasPz <= 0 ? '🔴 HOJE' : ($__diasPz === 1 ? '🟡 AMANHÃ' : '⚠️ ' . $__diasPz . 'd');
    ?>
    <div style="display:flex;align-items:center;gap:.5rem;padding:.2rem 0;border-top:1px solid rgba(255,255,255,.15);">
        <span style="font-weight:700;min-width:70px;"><?= $__urgLabel ?></span>
        <span style="font-weight:600;"><?= e($__pz['descricao_acao']) ?></span>
        <?php if ($__pz['case_id']): ?><a href="<?= url('modules/operacional/caso_ver.php?id=' . $__pz['case_id']) ?>" style="color:#fecaca;text-decoration:none;">— <?= e($__pz['case_title'] ?: $__pz['numero_processo'] ?: '') ?></a><?php endif; ?>
        <?php if ($__pz['client_name']): ?><span style="opacity:.7;">(<?= e($__pz['client_name']) ?>)</span><?php endif; ?>
        <span style="margin-left:auto;font-family:monospace;font-size:.72rem;opacity:.8;"><?= date('d/m', strtotime($__pz['prazo_fatal'])) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ── Alerta de expiração do token Asaas — visível SÓ PRA AMANDA ──
try {
    $__cu = current_user();
    $__isAmanda = $__cu && strtolower($__cu['email'] ?? '') === 'amandaguedesferreira@gmail.com';
    if ($__isAmanda) {
        $__exp = db()->query("SELECT valor FROM configuracoes WHERE chave = 'asaas_api_key_expires_at'")->fetchColumn();
        if ($__exp) {
            $__diasRest = (int)floor((strtotime($__exp) - time()) / 86400);
            if ($__diasRest <= 15):
?>
<div style="background:linear-gradient(135deg,<?= $__diasRest < 0 ? '#7f1d1d,#991b1b' : '#d97706,#b45309' ?>);color:#fff;border-radius:10px;padding:.7rem 1rem;margin-bottom:.75rem;font-size:.82rem;display:flex;align-items:center;gap:.7rem;">
    <span style="font-size:1.3rem;"><?= $__diasRest < 0 ? '⛔' : '⚠️' ?></span>
    <div style="flex:1;">
        <strong>
            <?php if ($__diasRest < 0): ?>Chave Asaas VENCIDA há <?= abs($__diasRest) ?> dia(s)!<?php elseif ($__diasRest === 0): ?>Chave Asaas vence HOJE!<?php else: ?>Chave Asaas vence em <?= $__diasRest ?> dia(s) (<?= date('d/m', strtotime($__exp)) ?>)<?php endif; ?>
        </strong>
        <div style="font-size:.75rem;opacity:.9;margin-top:2px;">Gera uma nova em asaas.com → Integrações → Chaves de API e atualiza aqui.</div>
    </div>
    <a href="<?= url('modules/admin/asaas_config.php') ?>" style="background:#fff;color:#b45309;padding:.4rem .8rem;border-radius:6px;text-decoration:none;font-weight:700;font-size:.78rem;">Atualizar agora →</a>
</div>
<?php
            endif;
        }
    }
} catch (Exception $e) {}
?>
