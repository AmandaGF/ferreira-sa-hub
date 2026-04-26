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
                <!-- Busca Global -->
                <div style="position:relative;" id="bgWrap">
                    <div style="position:relative;display:flex;align-items:center;">
                        <span style="position:absolute;left:10px;font-size:.85rem;opacity:.5;pointer-events:none;">🔍</span>
                        <input type="text" id="bgInput" placeholder="Buscar no Hub... ( / )" autocomplete="off"
                               style="padding:.4rem .6rem .4rem 30px;background:rgba(5,34,40,.05);border:1px solid rgba(5,34,40,.15);border-radius:8px;color:var(--petrol-900);font-size:.8rem;outline:none;width:180px;transition:width .15s;"
                               onfocus="this.style.width='280px';this.style.background='#fff';"
                               onblur="setTimeout(function(){document.getElementById('bgInput').style.width='180px';document.getElementById('bgInput').style.background='rgba(5,34,40,.05)';document.getElementById('bgDrop').style.display='none';},200);">
                    </div>
                    <div id="bgDrop" style="display:none;position:absolute;right:0;top:calc(100% + 6px);width:360px;max-height:520px;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.15);z-index:9999;overflow-y:auto;"></div>
                </div>
                <style>
                .bg-grupo{padding:.3rem .8rem;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#B87333;margin-top:.3rem;}
                .bg-item{display:flex;align-items:center;gap:.6rem;padding:.45rem .8rem;color:var(--text);text-decoration:none;font-size:.8rem;transition:background .1s;cursor:pointer;}
                .bg-item:hover{background:#f1f5f9;}
                .bg-item-ico{font-size:1.05rem;flex-shrink:0;}
                .bg-item-tit{font-weight:600;color:var(--petrol-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
                .bg-item-sub{font-size:.68rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
                .bg-empty{padding:1.5rem;text-align:center;color:#94a3b8;font-size:.8rem;}
                @media(max-width:700px){#bgInput{width:140px!important;}#bgInput:focus{width:200px!important;}#bgDrop{width:calc(100vw - 20px);right:-10px;}}
                </style>
                <script>
                (function(){
                    var bgTimer = null;
                    var bgInput = document.getElementById('bgInput');
                    var bgDrop  = document.getElementById('bgDrop');
                    if (!bgInput) return;

                    bgInput.addEventListener('input', function() {
                        var q = this.value.trim();
                        if (bgTimer) clearTimeout(bgTimer);
                        if (q.length < 3) { bgDrop.style.display = 'none'; return; }
                        bgTimer = setTimeout(function(){ bgExecutar(q); }, 250);
                    });

                    // Previne o blur do input quando o usuário clica em um resultado —
                    // assim o dropdown não fecha antes do clique no <a> registrar
                    bgDrop.addEventListener('mousedown', function(ev) {
                        ev.preventDefault();
                    });

                    bgInput.addEventListener('keydown', function(ev) {
                        if (ev.key === 'Escape') { this.value = ''; bgDrop.style.display = 'none'; this.blur(); }
                    });

                    // Atalho "/" global
                    document.addEventListener('keydown', function(ev) {
                        if (ev.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA' && !document.activeElement.isContentEditable) {
                            ev.preventDefault();
                            bgInput.focus();
                        }
                    });

                    function bgExecutar(q) {
                        var x = new XMLHttpRequest();
                        x.open('GET', '<?= url('api/busca_global.php') ?>?q=' + encodeURIComponent(q));
                        x.onload = function() {
                            try {
                                var r = JSON.parse(x.responseText);
                                if (!r.ok) return;
                                bgRender(r.grupos || {});
                            } catch(e) {}
                        };
                        x.send();
                    }

                    function bgRender(grupos) {
                        var base = '<?= rtrim(url(''), '/') ?>';
                        var labels = {
                            clientes:   'Clientes',
                            processos:  'Processos',
                            leads:      'Leads',
                            tarefas:    'Tarefas',
                            chamados:   'Chamados',
                            andamentos: 'Andamentos',
                            intimacoes: 'Intimações / Publicações',
                            wiki:       'Wiki'
                        };
                        var ordem = ['clientes','processos','leads','intimacoes','tarefas','chamados','andamentos','wiki'];
                        var html = '';
                        var total = 0;
                        ordem.forEach(function(k){
                            var list = grupos[k];
                            if (!list || !list.length) return;
                            total += list.length;
                            html += '<div class="bg-grupo">' + labels[k] + '</div>';
                            list.forEach(function(it){
                                html += '<a class="bg-item" href="' + base + '/' + it.url + '">'
                                     +  '<span class="bg-item-ico">' + it.icon + '</span>'
                                     +  '<div style="min-width:0;flex:1;">'
                                     +  '<div class="bg-item-tit">' + bgEsc(it.titulo) + '</div>'
                                     +  (it.subtitulo ? '<div class="bg-item-sub">' + bgEsc(it.subtitulo) + '</div>' : '')
                                     +  '</div></a>';
                            });
                        });
                        if (!total) html = '<div class="bg-empty">Nenhum resultado encontrado.</div>';
                        bgDrop.innerHTML = html;
                        bgDrop.style.display = 'block';
                    }

                    function bgEsc(s) {
                        if (s == null) return '';
                        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                    }
                })();
                </script>

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
                // ────── Rio de Janeiro (atuação principal) ──────
                {c:'Rio de Janeiro',n:'TJRJ — PJe 1º Grau',u:'https://tjrj.pje.jus.br/1g/login.seam'},
                {c:'Rio de Janeiro',n:'TJRJ — PJe 2º Grau',u:'https://tjrj.pje.jus.br/2g/login.seam'},
                {c:'Rio de Janeiro',n:'TJRJ — eproc / Portal TJ',u:'https://portaltj.tjrj.jus.br/login'},
                {c:'Rio de Janeiro',n:'TJRJ — Portal de Serviços (DCP/legado)',u:'https://www3.tjrj.jus.br/portalservicos/'},
                {c:'Rio de Janeiro',n:'TJRJ — IdServerJus (autenticação)',u:'https://www3.tjrj.jus.br/idserverjus-front/'},
                {c:'Rio de Janeiro',n:'TJRJ — EProc 1º Grau (legado)',u:'https://eproc1g.tjrj.jus.br/eproc/'},
                {c:'Rio de Janeiro',n:'Balcão Virtual TJRJ',u:'https://www.tjrj.jus.br/web/guest/balcao-virtual'},
                {c:'Rio de Janeiro',n:'Fóruns Regionais RJ',u:'https://www.tjrj.jus.br/web/cgj/foruns-regionais-capital'},
                {c:'Rio de Janeiro',n:'Regionais Infância TJRJ',u:'https://cgj.tjrj.jus.br/abrangencia-contato-vijis-comissarios'},
                {c:'Rio de Janeiro',n:'Mediação e Conciliação Pré-Processual',u:'https://www.tjrj.jus.br/web/guest/institucional/mediacao/pre-processual'},
                // ────── Federal / TRF ──────
                {c:'Federal / TRF',n:'TRF 2ª Região (RJ/ES)',u:'https://eproc.trf2.jus.br/eproc/'},
                {c:'Federal / TRF',n:'Justiça Federal 2ª Região - RJ',u:'https://eproc.jfrj.jus.br/eproc/'},
                {c:'Federal / TRF',n:'TRF 3ª Região - SP',u:'https://www.trf3.jus.br/pje'},
                {c:'Federal / TRF',n:'TRF 3ª Região SP - JEF',u:'https://pje1g.trf3.jus.br/pje/login.seam'},
                {c:'Federal / TRF',n:'TRF 5ª Região - Ceará JEF',u:'https://pje1g.trf5.jus.br/pje/login.seam'},
                {c:'Federal / TRF',n:'Balcão Virtual Federal Ceará',u:'https://painelcentralsistemas.jfce.jus.br/painelcentralsistemas/'},
                // ────── Sudeste (ES, MG, SP) ──────
                {c:'Sudeste',n:'TJES — Espírito Santo — PJe 1º Grau',u:'https://pje.tjes.jus.br/pje/login.seam'},
                {c:'Sudeste',n:'TJES — Espírito Santo — PJe 2º Grau',u:'https://pje.tjes.jus.br/pje2g/login.seam'},
                {c:'Sudeste',n:'TJMG — Minas Gerais — PJe 1º Grau',u:'https://pje.tjmg.jus.br/pje/login.seam'},
                {c:'Sudeste',n:'TJMG — Minas Gerais — PJe Recursal',u:'https://pjerecursal.tjmg.jus.br/pje/login.seam'},
                {c:'Sudeste',n:'TJMG — Minas Gerais — JPe-Themis (2ª Inst.)',u:'https://www.tjmg.jus.br/portal-tjmg/processos/jpe-themis-processo-eletronico-de-2-instancia/'},
                {c:'Sudeste',n:'TJMG — Minas Gerais — eProc (legado)',u:'https://eproc1g.tjmg.jus.br/eproc/'},
                {c:'Sudeste',n:'TJMG — Minas Gerais — Projudi Juizados BH',u:'https://www.tjmg.jus.br/portal-tjmg/processos/projudi-processo-eletronico-de-juizados-especiais/'},
                {c:'Sudeste',n:'TJSP — São Paulo — e-SAJ (1º/2º Grau)',u:'https://esaj.tjsp.jus.br/sajcas/login'},
                // ────── Sul (PR, RS, SC) ──────
                {c:'Sul',n:'TJPR — Paraná — Projudi 1º Grau',u:'https://projudi.tjpr.jus.br/projudi/'},
                {c:'Sul',n:'TJPR — Paraná — Projudi 2º Grau',u:'https://projudi2.tjpr.jus.br/projudi/'},
                {c:'Sul',n:'TJRS — Rio Grande do Sul — eproc 1º Grau',u:'https://eproc1g.tjrs.jus.br/eproc/externo_controlador.php?acao=principal'},
                {c:'Sul',n:'TJRS — Rio Grande do Sul — eproc 2º Grau',u:'https://eproc2g.tjrs.jus.br/eproc/externo_controlador.php?acao=principal'},
                {c:'Sul',n:'TJRS — Rio Grande do Sul — PPE Unificado',u:'https://ppe.tjrs.jus.br/ppe/signin'},
                {c:'Sul',n:'TJSC — Santa Catarina — eproc 1º Grau',u:'https://eproc1g.tjsc.jus.br/eproc/'},
                {c:'Sul',n:'TJSC — Santa Catarina — eproc 2º Grau',u:'https://eproc2g.tjsc.jus.br/eproc/externo_controlador.php?acao=principal'},
                // ────── Centro-Oeste (DF, GO, MT, MS) ──────
                {c:'Centro-Oeste',n:'TJDFT — Distrito Federal — PJe 1º Grau',u:'https://pje.tjdft.jus.br/pje/login.seam'},
                {c:'Centro-Oeste',n:'TJDFT — Distrito Federal — PJe 2º Grau',u:'https://pje2i.tjdft.jus.br/pje/login.seam'},
                {c:'Centro-Oeste',n:'TJGO — Goiás — Projudi (1º/2º Grau)',u:'https://projudi.tjgo.jus.br/LogOn'},
                {c:'Centro-Oeste',n:'TJGO — Goiás — PJD (1º/2º Grau)',u:'https://pjd.tjgo.jus.br/LogOn'},
                {c:'Centro-Oeste',n:'TJMT — Mato Grosso — PJe 1º Grau',u:'https://pje.tjmt.jus.br/pje/login.seam'},
                {c:'Centro-Oeste',n:'TJMT — Mato Grosso — PJe 2º Grau',u:'https://pje2.tjmt.jus.br/pje2/login.seam'},
                {c:'Centro-Oeste',n:'TJMT — Mato Grosso — PEA (Portal Advogado)',u:'https://pea.tjmt.jus.br/'},
                {c:'Centro-Oeste',n:'TJMS — Mato Grosso do Sul — e-SAJ (1º/2º Grau)',u:'https://esaj.tjms.jus.br/sajcas/login'},
                // ────── Nordeste (AL, BA, CE, MA, PB, PE, PI, RN, SE) ──────
                {c:'Nordeste',n:'TJAL — Alagoas — e-SAJ (1º/2º Grau)',u:'https://www2.tjal.jus.br/sajcas/login'},
                {c:'Nordeste',n:'TJBA — Bahia — PJe 1º Grau',u:'https://pje.tjba.jus.br/pje/login.seam'},
                {c:'Nordeste',n:'TJBA — Bahia — PJe 2º Grau',u:'https://pje2g.tjba.jus.br/pje/login.seam'},
                {c:'Nordeste',n:'TJCE — Ceará — PJe 1º Grau',u:'https://pje.tjce.jus.br/pje1grau/login.seam'},
                {c:'Nordeste',n:'TJCE — Ceará — PJe 2º Grau',u:'https://pje.tjce.jus.br/pje2grau/login.seam'},
                {c:'Nordeste',n:'TJMA — Maranhão — PJe 1º Grau',u:'https://pje.tjma.jus.br/pje/login.seam'},
                {c:'Nordeste',n:'TJMA — Maranhão — PJe 2º Grau',u:'https://pje2.tjma.jus.br/pje2g/login.seam'},
                {c:'Nordeste',n:'TJPB — Paraíba — PJe 1º Grau',u:'https://pje.tjpb.jus.br/pje/login.seam'},
                {c:'Nordeste',n:'TJPB — Paraíba — PJe 2º Grau',u:'https://pjesg.tjpb.jus.br/pje2g/login.seam'},
                {c:'Nordeste',n:'TJPE — Pernambuco — PJe 1º Grau',u:'https://pje.cloud.tjpe.jus.br/1g/login.seam'},
                {c:'Nordeste',n:'TJPE — Pernambuco — PJe 2º Grau',u:'https://pje.cloud.tjpe.jus.br/2g/login.seam'},
                {c:'Nordeste',n:'TJPI — Piauí — PJe 1º Grau',u:'https://tjpi.pje.jus.br/1g/login.seam'},
                {c:'Nordeste',n:'TJPI — Piauí — PJe 2º Grau',u:'https://tjpi.pje.jus.br/2g/login.seam'},
                {c:'Nordeste',n:'TJPI — Piauí — ThemisWeb',u:'https://www.tjpi.jus.br/themisweb/'},
                {c:'Nordeste',n:'TJRN — Rio Grande do Norte — PJe 1º Grau',u:'https://pje1g.tjrn.jus.br/pje/login.seam'},
                {c:'Nordeste',n:'TJRN — Rio Grande do Norte — PJe 2º Grau',u:'https://pje2g.tjrn.jus.br/pje/login.seam'},
                {c:'Nordeste',n:'TJSE — Sergipe — Portal do Advogado (tjnet)',u:'https://www.tjse.jus.br/tjnet/portaladv/login.wsp'},
                {c:'Nordeste',n:'TJSE — Sergipe — eproc (implantação)',u:'https://www.tjse.jus.br/portal/servicos/judiciais/eproc'},
                // ────── Norte (AC, AP, AM, PA, RR, RO, TO) ──────
                {c:'Norte',n:'TJAC — Acre — e-SAJ (1º/2º Grau)',u:'https://esaj.tjac.jus.br/sajcas/login'},
                {c:'Norte',n:'TJAP — Amapá — PJe 1º Grau',u:'https://pje.tjap.jus.br/1g/login.seam'},
                {c:'Norte',n:'TJAP — Amapá — PJe 2º Grau',u:'https://pje.tjap.jus.br/2g/login.seam'},
                {c:'Norte',n:'TJAP — Amapá — Tucujuris (1º Grau)',u:'https://tucujuris.tjap.jus.br/tucujuris/pages/login/login.html'},
                {c:'Norte',n:'TJAM — Amazonas — e-SAJ (1º/2º Grau)',u:'https://consultasaj.tjam.jus.br/sajcas/login'},
                {c:'Norte',n:'TJAM — Amazonas — Projudi (1º Grau)',u:'https://projudi.tjam.jus.br/projudi/'},
                {c:'Norte',n:'TJPA — Pará — PJe 1º Grau',u:'https://pje.tjpa.jus.br/pje/login.seam'},
                {c:'Norte',n:'TJPA — Pará — PJe 2º Grau',u:'https://pje.tjpa.jus.br/pje-2g/login.seam'},
                {c:'Norte',n:'TJRR — Roraima — PJe 1ª Instância',u:'http://pje.tjrr.jus.br/pje/login.seam'},
                {c:'Norte',n:'TJRR — Roraima — PJe 2ª Instância',u:'http://pje2.tjrr.jus.br/pje/login.seam'},
                {c:'Norte',n:'TJRR — Roraima — Projudi (1º/2º Grau)',u:'https://projudi.tjrr.jus.br/projudi/'},
                {c:'Norte',n:'TJRO — Rondônia — PJe 1º Grau',u:'https://pjepg.tjro.jus.br/pje/login.seam'},
                {c:'Norte',n:'TJRO — Rondônia — PJe 2º Grau',u:'https://pjesg.tjro.jus.br/pje/login.seam'},
                {c:'Norte',n:'TJTO — Tocantins — eproc 1º Grau',u:'https://eproc1.tjto.jus.br/eprocV2_prod_1grau/'},
                {c:'Norte',n:'TJTO — Tocantins — eproc 2º Grau',u:'https://eproc2.tjto.jus.br/eprocV2_prod_2grau/'},
                {c:'Norte',n:'TJTO — Tocantins — IDP (Gov.br)',u:'https://idp.tjto.jus.br/Account/Login'},
                // ────── Portais Gerais ──────
                {c:'Portais Gerais',n:'Comunica PJE (Prazos)',u:'https://comunica.pje.jus.br/'},
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

                                    // Detecta se o link é wa.me/... — nesse caso renderizamos com data
                                    // attributes pra abrir o WhatsApp do Hub (waSenderOpen) em vez
                                    // do WhatsApp externo. Parse na hora pra extrair phone/text.
                                    $isWaLink = !empty($n['link']) && stripos($n['link'], 'wa.me/') !== false;
                                    if ($isWaLink) {
                                        $waPhone = '';
                                        $waText  = '';
                                        $waName  = '';
                                        $u = parse_url($n['link']);
                                        if (isset($u['path'])) {
                                            $waPhone = preg_replace('/\D/', '', $u['path']);
                                        }
                                        if (isset($u['query'])) {
                                            parse_str($u['query'], $qs);
                                            if (isset($qs['text'])) $waText = (string)$qs['text'];
                                        }
                                        // Tenta pegar nome do título: "Documentos recebidos — NOME"
                                        if (strpos((string)$n['title'], '—') !== false) {
                                            $partsTitle = explode('—', $n['title']);
                                            $waName = trim((string)array_pop($partsTitle));
                                        }
                                    }
                                ?>
                                <?php if ($isWaLink): ?>
                                <a href="javascript:void(0)" class="<?= $nClass ?>"
                                   data-notif-id="<?= (int)$n['id'] ?>"
                                   data-wa-phone="<?= e($waPhone) ?>"
                                   data-wa-name="<?= e($waName) ?>"
                                   data-wa-text="<?= e($waText) ?>"
                                   onclick="fsaNotifClickWa(this); return false;">
                                    <span class="notif-icon"><?= $nIcon ?></span>
                                    <div class="notif-content">
                                        <div class="notif-title"><?= e($n['title']) ?></div>
                                        <?php if ($n['message']): ?>
                                            <div class="notif-msg"><?= e(mb_substr($n['message'], 0, 60, 'UTF-8')) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="notif-time"><?= $ago ?></span>
                                </a>
                                <?php else: ?>
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
                                <?php endif; ?>
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
<div class="no-print" style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:10px;padding:.6rem 1rem;margin-bottom:.75rem;font-size:.78rem;">
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
<div class="no-print" style="background:linear-gradient(135deg,<?= $__diasRest < 0 ? '#7f1d1d,#991b1b' : '#d97706,#b45309' ?>);color:#fff;border-radius:10px;padding:.7rem 1rem;margin-bottom:.75rem;font-size:.82rem;display:flex;align-items:center;gap:.7rem;">
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
