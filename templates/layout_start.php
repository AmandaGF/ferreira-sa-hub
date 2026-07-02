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

        <?php
        // ═══════════════════════════════════════════════════════
        // 🇧🇷 TEMA COPA — Amanda 29/06/2026: Brasil x Japão (vitória!)
        // Bandeirinha fixa no topo + pop-up de celebração 1x por sessão.
        // Pra desligar quando o Brasil for eliminado/campeão: trocar TEMA_COPA = false abaixo.
        // ═══════════════════════════════════════════════════════
        $TEMA_COPA = true;
        if ($TEMA_COPA):
        ?>
        <style>
        .br-flag-fixa { position:fixed !important; top:60px; right:18px; z-index:99999 !important; font-size:1.8rem; cursor:pointer; user-select:none;
            background:linear-gradient(180deg,#009c3b 50%,#ffdf00 50%); border-radius:50%; width:38px; height:38px; display:flex; align-items:center; justify-content:center;
            box-shadow:0 3px 10px rgba(0,0,0,.25), 0 0 0 2px #fff; transition:transform .2s; line-height:1; pointer-events:auto;
            border:none; padding:0; font-family:inherit; animation:brFlagPulse 2.4s ease-in-out infinite; }
        .br-flag-fixa:hover { transform:scale(1.18) rotate(-5deg); animation:none; }
        @keyframes brFlagPulse { 0%,100% { box-shadow:0 3px 10px rgba(0,0,0,.25), 0 0 0 2px #fff; } 50% { box-shadow:0 3px 14px rgba(0,156,59,.6), 0 0 0 2px #fff, 0 0 0 8px rgba(255,223,0,.35); } }
        @media (max-width: 768px) { .br-flag-fixa { top:54px; right:10px; font-size:1.5rem; width:32px; height:32px; } }
        /* Pop-up celebração */
        .br-celebra-bg { position:fixed; inset:0; z-index:9999; background:radial-gradient(circle at 50% 40%, rgba(0,156,59,.95), rgba(0,39,118,.97));
            display:flex; align-items:center; justify-content:center; flex-direction:column; cursor:pointer; animation:brFadeIn .4s ease-out; }
        @keyframes brFadeIn { from { opacity:0; } to { opacity:1; } }
        .br-celebra-titulo { color:#ffdf00; font-size:3.2rem; font-weight:900; text-shadow:0 4px 12px rgba(0,0,0,.4); text-align:center;
            font-family:'Playfair Display',Georgia,serif; letter-spacing:2px; padding:0 20px; line-height:1.1; animation:brPulse 1s ease-in-out infinite alternate; }
        @keyframes brPulse { from { transform:scale(1); } to { transform:scale(1.06); } }
        .br-celebra-sub { color:#fff; font-size:1.8rem; font-weight:800; text-shadow:0 2px 8px rgba(0,0,0,.5); margin-top:18px; animation:brBounce 1.2s ease-in-out infinite; }
        @keyframes brBounce { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-12px); } }
        .br-celebra-bandeira { font-size:5rem; animation:brSpin 2s linear infinite; margin-bottom:20px; filter:drop-shadow(0 6px 18px rgba(0,0,0,.4)); }
        @keyframes brSpin { 0%,80%,100% { transform:rotate(0deg); } 85%,95% { transform:rotate(-10deg); } 90% { transform:rotate(10deg); } }
        .br-celebra-dica { color:rgba(255,255,255,.85); font-size:.9rem; margin-top:30px; font-weight:600; }
        /* Fogos / confetti */
        .br-firework { position:absolute; width:6px; height:6px; border-radius:50%; animation:brFirework 1.5s ease-out forwards; pointer-events:none; }
        @keyframes brFirework {
            0%   { transform:translateY(0) scale(.4); opacity:1; }
            70%  { transform:translateY(var(--ty,-300px)) translateX(var(--tx,0)) scale(1); opacity:1; }
            100% { transform:translateY(var(--ty,-300px)) translateX(var(--tx,0)) scale(0); opacity:0; }
        }
        .br-confetti { position:absolute; width:12px; height:18px; top:-30px; animation:brConfetti linear infinite; pointer-events:none; }
        @keyframes brConfetti {
            from { transform:translateY(-30px) rotate(0deg); }
            to   { transform:translateY(110vh) rotate(720deg); }
        }
        </style>

        <!-- Bandeirinha fixa: aparece em todas as páginas até o Brasil ser eliminado/campeão.
             Clique reabre a animação de celebração (pra Amanda tirar foto/curtir de novo). -->
        <button type="button" class="br-flag-fixa" title="Clique pra celebrar de novo! 🇧🇷" id="brFlagFixa" onclick="brCelebraAbrir()">🇧🇷</button>

        <!-- Pop-up de celebração (mostra 1× por sessão) -->
        <div id="brCelebra" class="br-celebra-bg" style="display:none;" onclick="brCelebraFechar()">
            <div class="br-celebra-bandeira">🇧🇷</div>
            <div class="br-celebra-titulo">MOSTRA SUA RAÇA<br>BRASIL!!!</div>
            <div class="br-celebra-sub">Boraaaa timeeee! 🚀⚽</div>
            <div class="br-celebra-dica">(clique pra continuar)</div>
        </div>

        <script>
        (function() {
            // 1× por sessão NA PRIMEIRA ABERTURA (não enche o saco).
            // Pra reabrir manualmente, basta clicar na bandeirinha.
            var KEY = 'fsa_copa_celebra_v1_<?= date('Y-m-d') ?>'; // muda por dia
            window.brCelebraAbrir = function() {
                var pop = document.getElementById('brCelebra');
                if (!pop) return;
                // Limpa fogos/confetti antigos (pra reabrir limpinho)
                pop.querySelectorAll('.br-confetti, .br-firework').forEach(function(el){ el.remove(); });
                pop.style.opacity = '1';
                pop.style.display = 'flex';
                // Confetti
                var cores = ['#009c3b','#ffdf00','#002776','#ffffff'];
                for (var i = 0; i < 60; i++) {
                    var c = document.createElement('div');
                    c.className = 'br-confetti';
                    c.style.left = Math.random() * 100 + 'vw';
                    c.style.background = cores[Math.floor(Math.random() * cores.length)];
                    c.style.animationDuration = (2 + Math.random() * 2.5) + 's';
                    c.style.animationDelay = (Math.random() * 1.2) + 's';
                    pop.appendChild(c);
                }
                // Fogos espalhados
                for (var j = 0; j < 24; j++) {
                    var f = document.createElement('div');
                    f.className = 'br-firework';
                    f.style.left = (20 + Math.random() * 60) + 'vw';
                    f.style.top  = (40 + Math.random() * 30) + 'vh';
                    f.style.background = cores[Math.floor(Math.random() * cores.length)];
                    f.style.setProperty('--tx', ((Math.random() - .5) * 400) + 'px');
                    f.style.setProperty('--ty', (-200 - Math.random() * 200) + 'px');
                    f.style.boxShadow = '0 0 12px ' + cores[Math.floor(Math.random() * cores.length)];
                    f.style.animationDelay = (Math.random() * 1.5) + 's';
                    pop.appendChild(f);
                }
                // Som de foguete (Web Audio — sem precisar hostar mp3)
                try {
                    var Ctx = window.AudioContext || window.webkitAudioContext;
                    if (Ctx) {
                        var ctx = new Ctx();
                        // Whoosh: oscilador descendo de 800Hz a 100Hz em 1.2s
                        var osc = ctx.createOscillator();
                        var gain = ctx.createGain();
                        osc.type = 'sawtooth';
                        osc.frequency.setValueAtTime(800, ctx.currentTime);
                        osc.frequency.exponentialRampToValueAtTime(80, ctx.currentTime + 1.2);
                        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.4, ctx.currentTime + 0.05);
                        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 1.2);
                        osc.connect(gain); gain.connect(ctx.destination);
                        osc.start();
                        osc.stop(ctx.currentTime + 1.3);
                        // Explosão final (ruído branco curto)
                        setTimeout(function() {
                            var bufSize = ctx.sampleRate * 0.4;
                            var buf = ctx.createBuffer(1, bufSize, ctx.sampleRate);
                            var data = buf.getChannelData(0);
                            for (var k = 0; k < bufSize; k++) data[k] = (Math.random() * 2 - 1) * Math.pow(1 - k/bufSize, 2);
                            var src = ctx.createBufferSource();
                            src.buffer = buf;
                            var g2 = ctx.createGain();
                            g2.gain.value = 0.5;
                            src.connect(g2); g2.connect(ctx.destination);
                            src.start();
                        }, 1200);
                    }
                } catch (e) {}
                // Marca como visto
                try { sessionStorage.setItem(KEY, '1'); } catch (e) {}
                // Auto-fecha em 7 segundos
                setTimeout(brCelebraFechar, 7000);
            }
            window.brCelebraFechar = function() {
                var pop = document.getElementById('brCelebra');
                if (pop) { pop.style.opacity = '0'; setTimeout(function() { pop.style.display = 'none'; }, 400); }
            };
            // 29/06/2026 Amanda: removido auto-show. Agora só abre quando clica
            // no botão da bandeirinha 🇧🇷 (brFlagFixa) no topo direito.
        })();
        </script>
        <?php endif; // TEMA_COPA ?>

        <?php
        // ── Banner de prazos críticos (sticky topo, todas as páginas) ──
        if ($_prazoBannerData && (($_prazoBannerData['vencidos'] ?? 0) > 0 || ($_prazoBannerData['hoje'] ?? 0) > 0)):
            $_v = (int)$_prazoBannerData['vencidos'];
            $_h = (int)$_prazoBannerData['hoje'];
            $_temVencido = $_v > 0;
            $_corBg = $_temVencido ? '#7f1d1d' : '#dc2626';
            $_corBgClaro = $_temVencido ? '#b91c1c' : '#ef4444';
        ?>
        <div id="prazoBanner" style="background:linear-gradient(135deg, <?= $_corBg ?>, <?= $_corBgClaro ?>);color:#fff;padding:.6rem 1rem;border-bottom:3px solid rgba(255,255,255,.25);position:sticky;top:0;z-index:200;box-shadow:0 4px 12px rgba(127,29,29,.3);">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;font-size:.88rem;font-weight:700;">
                    <span style="font-size:1.1rem;">🚨</span>
                    <?php if ($_temVencido && $_h > 0): ?>
                        <span><?= $_v ?> prazo<?= $_v>1?'s':'' ?> <u>VENCIDO<?= $_v>1?'S':'' ?></u> + <?= $_h ?> pra <u>HOJE</u></span>
                    <?php elseif ($_temVencido): ?>
                        <span><?= $_v ?> prazo<?= $_v>1?'s':'' ?> <u>VENCIDO<?= $_v>1?'S':'' ?></u></span>
                    <?php else: ?>
                        <span><?= $_h ?> prazo<?= $_h>1?'s':'' ?> vencendo <u>HOJE</u></span>
                    <?php endif; ?>
                    <button type="button" onclick="prazoBannerToggle()" id="prazoBannerToggleBtn" style="background:rgba(255,255,255,.18);border:none;color:#fff;padding:.2rem .65rem;border-radius:14px;cursor:pointer;font-size:.7rem;font-weight:600;">▼ Ver lista</button>
                </div>
                <div style="display:flex;gap:.4rem;align-items:center;">
                    <a href="<?= url('modules/dashboard/index.php') ?>#prazos" style="background:rgba(255,255,255,.95);color:<?= $_corBg ?>;padding:.3rem .8rem;border-radius:6px;text-decoration:none;font-size:.76rem;font-weight:700;">📋 Abrir todos</a>
                    <button type="button" onclick="prazoBannerDismiss()" title="Esconder por 30 minutos" style="background:transparent;border:1px solid rgba(255,255,255,.5);color:#fff;padding:.25rem .55rem;border-radius:6px;cursor:pointer;font-size:.72rem;">✕ 30min</button>
                </div>
            </div>

            <div id="prazoBannerLista" style="display:none;margin-top:.6rem;padding-top:.6rem;border-top:1px solid rgba(255,255,255,.2);">
                <?php if (!empty($_prazoBannerData['lista'])): ?>
                    <div style="display:flex;flex-direction:column;gap:.3rem;">
                        <?php foreach ($_prazoBannerData['lista'] as $_p):
                            $_dias = (int)$_p['dias'];
                            $_lbl = $_dias < 0 ? '🚨 VENCIDO há ' . abs($_dias) . 'd' : ($_dias === 0 ? '⚠️ HOJE' : '✓ em ' . $_dias . 'd');
                            $_href = !empty($_p['case_id']) ? url('modules/operacional/caso_ver.php?id=' . (int)$_p['case_id']) : url('modules/agenda/');
                        ?>
                        <a href="<?= e($_href) ?>" style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;background:rgba(255,255,255,.12);padding:.4rem .7rem;border-radius:6px;text-decoration:none;color:#fff;font-size:.78rem;">
                            <div style="flex:1;min-width:0;overflow:hidden;">
                                <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?= e(mb_substr($_p['descricao_acao'] ?: '(sem descrição)', 0, 90, 'UTF-8')) ?>
                                </div>
                                <?php if (!empty($_p['client_name']) || !empty($_p['case_title'])): ?>
                                    <div style="font-size:.66rem;opacity:.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= e(($_p['client_name'] ?: '—') . ($_p['case_title'] ? ' · ' . $_p['case_title'] : '')) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:.7rem;font-weight:700;white-space:nowrap;background:rgba(0,0,0,.25);padding:.18rem .55rem;border-radius:10px;"><?= e($_lbl) ?></div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
            // Esconde por 30 min via localStorage
            try {
                var stored = parseInt(localStorage.getItem('prazoBannerDismissUntil') || '0', 10);
                if (stored > Date.now()) {
                    var bn = document.getElementById('prazoBanner');
                    if (bn) bn.style.display = 'none';
                }
            } catch(e){}
            window.prazoBannerDismiss = function() {
                try { localStorage.setItem('prazoBannerDismissUntil', String(Date.now() + 30 * 60 * 1000)); } catch(e){}
                var bn = document.getElementById('prazoBanner');
                if (bn) bn.style.display = 'none';
            };
            window.prazoBannerToggle = function() {
                var lst = document.getElementById('prazoBannerLista');
                var btn = document.getElementById('prazoBannerToggleBtn');
                if (!lst) return;
                if (lst.style.display === 'none' || !lst.style.display) {
                    lst.style.display = 'block';
                    if (btn) btn.innerHTML = '▲ Ocultar';
                } else {
                    lst.style.display = 'none';
                    if (btn) btn.innerHTML = '▼ Ver lista';
                }
            };
        })();
        </script>
        <?php endif; ?>

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
                        <!-- 02/07/2026 Amanda: Chrome/Edge injetavam email no bgInput apos
                             copiar senha do 2FA. autocomplete='off' nao basta — precisa
                             de type='search' + name random + data-lpignore + data-1p-ignore
                             + readonly (removido on focus, truque anti-autofill). -->
                        <input type="search" id="bgInput" name="hubsearch_<?= substr(md5((string)time()), 0, 6) ?>"
                               placeholder="Buscar no Hub... (Ctrl+K ou /)"
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                               data-lpignore="true" data-1p-ignore data-form-type="other"
                               readonly onfocus="this.removeAttribute('readonly');this.style.width='280px';this.style.background='#fff';"
                               style="padding:.4rem .6rem .4rem 30px;background:rgba(5,34,40,.05);border:1px solid rgba(5,34,40,.15);border-radius:8px;color:var(--petrol-900);font-size:.8rem;outline:none;width:180px;transition:width .15s;"
                               onblur="setTimeout(function(){var i=document.getElementById('bgInput');if(i){i.style.width='180px';i.style.background='rgba(5,34,40,.05)';i.setAttribute('readonly','readonly');}document.getElementById('bgDrop').style.display='none';},200);">
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

                    // Atalho "/" global + Ctrl+K (ou Cmd+K no Mac) — Ctrl+K dispara em qualquer contexto, '/' só fora de input
                    document.addEventListener('keydown', function(ev) {
                        if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'k' || ev.key === 'K')) {
                            ev.preventDefault();
                            bgInput.focus();
                            bgInput.select();
                            return;
                        }
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
                            clientes:     'Clientes',
                            processos:    'Processos',
                            leads:        'Leads',
                            tarefas:      'Tarefas',
                            chamados:     'Chamados',
                            andamentos:   'Andamentos',
                            intimacoes:   'Intimações / Publicações',
                            agenda:       'Agenda',
                            audiencistas: 'Audiencistas',
                            wiki:         'Wiki'
                        };
                        var ordem = ['clientes','processos','leads','intimacoes','agenda','audiencistas','tarefas','chamados','andamentos','wiki'];
                        var html = '';
                        var total = 0;
                        ordem.forEach(function(k){
                            var list = grupos[k];
                            if (!list || !list.length) return;
                            total += list.length;
                            html += '<div class="bg-grupo">' + labels[k] + '</div>';
                            list.forEach(function(it){
                                // Item 'truncado' (aviso de + resultados) tem visual diferente
                                if (it.tipo === 'truncado') {
                                    html += '<a class="bg-item" href="' + base + '/' + it.url + '" style="background:#fef3c7;border-top:1px dashed #f59e0b;">'
                                         +  '<span class="bg-item-ico" style="opacity:.7;">' + it.icon + '</span>'
                                         +  '<div style="min-width:0;flex:1;">'
                                         +  '<div class="bg-item-tit" style="color:#92400e;font-weight:700;">' + bgEsc(it.titulo) + '</div>'
                                         +  (it.subtitulo ? '<div class="bg-item-sub" style="color:#a16207;">' + bgEsc(it.subtitulo) + '</div>' : '')
                                         +  '</div></a>';
                                } else {
                                    // Amanda 11/06/2026: processos arquivados/cancelados/concluidos ficam cinza-claro
                                    var _arqStyle = it.arquivado ? 'opacity:.5;filter:grayscale(.65);background:#f8fafc;' : '';
                                    var _arqTit   = it.arquivado ? 'color:#64748b;font-weight:600;' : '';
                                    var _arqSub   = it.arquivado ? 'color:#94a3b8;' : '';
                                    html += '<a class="bg-item" href="' + base + '/' + it.url + '" style="' + _arqStyle + '">'
                                         +  '<span class="bg-item-ico"' + (it.arquivado ? ' style="opacity:.6;"' : '') + '>' + it.icon + '</span>'
                                         +  '<div style="min-width:0;flex:1;">'
                                         +  '<div class="bg-item-tit" style="' + _arqTit + '">' + bgEsc(it.titulo) + '</div>'
                                         +  (it.subtitulo ? '<div class="bg-item-sub" style="' + _arqSub + '">' + bgEsc(it.subtitulo) + '</div>' : '')
                                         +  '</div></a>';
                                }
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
                {c:'Rio de Janeiro',n:'TJRJ — eproc 1º Grau',u:'https://eproc1g.tjrj.jus.br/eproc/'},
                {c:'Rio de Janeiro',n:'TJRJ — Portal TJ',u:'https://portaltj.tjrj.jus.br/login'},
                {c:'Rio de Janeiro',n:'TJRJ — Portal de Serviços (DCP/legado)',u:'https://www3.tjrj.jus.br/portalservicos/'},
                {c:'Rio de Janeiro',n:'TJRJ — IdServerJus (autenticação)',u:'https://www3.tjrj.jus.br/idserverjus-front/'},
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
                                <a href="<?= $n['link'] ? e($n['link']) . (strpos($n['link'],'?') !== false ? '&' : '?') . 'notif_id=' . $n['id'] : url('modules/notificacoes/?read=' . $n['id']) ?>" class="<?= $nClass ?>" data-notif-id="<?= (int)$n['id'] ?>">
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
// ── Banner GLOBAL de aviso (instabilidade, manutencao etc) ──
// Setado via configuracoes.aviso_global_msg. Visivel em todas as paginas
// pra todos os usuarios. Vazio = sem banner. Pra desativar: setar string
// vazia. Cada user pode fechar (localStorage) com 'hash' invalidando ao
// trocar a msg.
$__avisoMsg = '';
try {
    $__stA = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'aviso_global_msg'");
    $__stA->execute();
    $__avisoMsg = trim((string)$__stA->fetchColumn());
} catch (Throwable $e) {}
if ($__avisoMsg !== '') {
    $__avisoHash = substr(md5($__avisoMsg), 0, 12);
?>
<style>
.aviso-global { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; border-radius:10px; padding:.65rem 1rem; margin-bottom:.75rem; font-size:.82rem; display:flex; align-items:center; gap:.6rem; box-shadow:0 2px 8px rgba(245,158,11,.25); }
.aviso-global .aviso-icon { font-size:1.15rem; flex-shrink:0; }
.aviso-global .aviso-msg { flex:1; line-height:1.4; }
.aviso-global button.aviso-x { background:rgba(255,255,255,.18); border:none; color:#fff; padding:.18rem .55rem; border-radius:6px; cursor:pointer; font-weight:700; font-size:.78rem; flex-shrink:0; }
.aviso-global button.aviso-x:hover { background:rgba(255,255,255,.32); }
</style>
<div class="no-print aviso-global" id="avisoGlobalEl" data-hash="<?= e($__avisoHash) ?>">
    <span class="aviso-icon">⚠️</span>
    <div class="aviso-msg"><?= e($__avisoMsg) ?></div>
    <button type="button" class="aviso-x" onclick="(function(b){try{localStorage.setItem('fsa_aviso_global_dismiss','<?= e($__avisoHash) ?>');}catch(e){} b.closest('#avisoGlobalEl').style.display='none';})(this);" title="Fechar (volta a aparecer se a mensagem mudar)">✕</button>
</div>
<script>
(function(){
    try {
        var dismissed = localStorage.getItem('fsa_aviso_global_dismiss');
        if (dismissed === '<?= e($__avisoHash) ?>') {
            var el = document.getElementById('avisoGlobalEl');
            if (el) el.style.display = 'none';
        }
    } catch (e) {}
})();
</script>
<?php
}
?>

<?php
// Banner de prazos urgentes (próximos 3 dias) — visível em todas as páginas.
// 11/05/2026 Amanda pediu: linha inteira clicavel + mais informacoes
// (CNJ formatado, comarca/vara, responsavel).
try {
    $__userId = current_user_id();
    $__role = current_user_role();
    $__prazosUrgentes = array();
    if (in_array($__role, array('admin','gestao','operacional'))) {
        // Inclui VENCIDOS nao concluidos (prazo_fatal <= +3d, sem limite inferior).
        // Antes: BETWEEN CURDATE() AND +3d -- vencido sumia do banner. (28/05/2026)
        // 17/06/2026: UNION com agenda_eventos tipo='prazo' — Amanda usa Agenda
        // pra criar prazos hoje em dia, banner antigo so lia prazos_processuais
        // e por isso parou de aparecer.
        // COLLATE utf8mb4_unicode_ci em TODAS colunas string: agenda_eventos e
        // prazos_processuais foram criadas com collations diferentes e a UNION
        // levantava "Illegal mix of collations" — o catch engolia em silencio.
        $__stmtPz = db()->prepare(
            "SELECT * FROM (
                SELECT p.id,
                       p.descricao_acao COLLATE utf8mb4_unicode_ci AS descricao_acao,
                       p.prazo_fatal,
                       p.numero_processo COLLATE utf8mb4_unicode_ci AS numero_processo,
                       p.case_id,
                       cs.title COLLATE utf8mb4_unicode_ci AS case_title,
                       cs.case_number COLLATE utf8mb4_unicode_ci AS case_cnj,
                       cs.comarca COLLATE utf8mb4_unicode_ci AS comarca,
                       cs.comarca_uf COLLATE utf8mb4_unicode_ci AS comarca_uf,
                       cs.court COLLATE utf8mb4_unicode_ci AS vara,
                       cl.name COLLATE utf8mb4_unicode_ci AS client_name,
                       u.name COLLATE utf8mb4_unicode_ci AS responsavel_name,
                       CAST('prazo' AS CHAR) COLLATE utf8mb4_unicode_ci AS __origem
                FROM prazos_processuais p
                LEFT JOIN cases cs ON cs.id = p.case_id
                LEFT JOIN clients cl ON cl.id = p.client_id
                LEFT JOIN users u ON u.id = cs.responsible_user_id
                WHERE p.concluido = 0 AND p.prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                UNION ALL
                SELECT ae.id,
                       ae.titulo COLLATE utf8mb4_unicode_ci AS descricao_acao,
                       DATE(ae.data_inicio) AS prazo_fatal,
                       cs.case_number COLLATE utf8mb4_unicode_ci AS numero_processo,
                       ae.case_id,
                       cs.title COLLATE utf8mb4_unicode_ci AS case_title,
                       cs.case_number COLLATE utf8mb4_unicode_ci AS case_cnj,
                       cs.comarca COLLATE utf8mb4_unicode_ci AS comarca,
                       cs.comarca_uf COLLATE utf8mb4_unicode_ci AS comarca_uf,
                       cs.court COLLATE utf8mb4_unicode_ci AS vara,
                       cl.name COLLATE utf8mb4_unicode_ci AS client_name,
                       u.name COLLATE utf8mb4_unicode_ci AS responsavel_name,
                       CAST('agenda' AS CHAR) COLLATE utf8mb4_unicode_ci AS __origem
                FROM agenda_eventos ae
                LEFT JOIN cases cs ON cs.id = ae.case_id
                LEFT JOIN clients cl ON cl.id = ae.client_id
                LEFT JOIN users u ON u.id = cs.responsible_user_id
                WHERE ae.tipo = 'prazo'
                  AND ae.status NOT IN ('cancelado','realizado','concluido')
                  AND DATE(ae.data_inicio) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            ) un
            ORDER BY
                CASE WHEN prazo_fatal >= CURDATE() THEN 0 ELSE 1 END ASC,
                CASE WHEN prazo_fatal >= CURDATE() THEN prazo_fatal END ASC,
                prazo_fatal DESC
            LIMIT 15"
        );
        $__stmtPz->execute();
        $__prazosUrgentes = $__stmtPz->fetchAll();
    }
} catch (Exception $e) { $__prazosUrgentes = array(); }

// Post-process: pra prazos sem case_id mas com numero_processo textual, casa
// pelo CNJ desformatado. Sem isso, click no card vai pra /prazos (e nao pra
// pasta do processo). Amanda 14/05/2026.
$__prazoSemCase = array();
foreach ($__prazosUrgentes as $__i => $__pz) {
    if (empty($__pz['case_id']) && !empty($__pz['numero_processo'])) {
        $__dg = preg_replace('/\D/', '', $__pz['numero_processo']);
        if (strlen($__dg) === 20) $__prazoSemCase[$__i] = $__dg;
    }
}
if (!empty($__prazoSemCase)) {
    try {
        $__unic = array_values(array_unique($__prazoSemCase));
        $__phPz = implode(',', array_fill(0, count($__unic), '?'));
        $__stPz = db()->prepare(
            "SELECT id, title, case_number, comarca, comarca_uf, court, responsible_user_id,
                    REPLACE(REPLACE(REPLACE(case_number,'-',''),'.',''),'/','') AS cnj_dg
             FROM cases
             WHERE REPLACE(REPLACE(REPLACE(case_number,'-',''),'.',''),'/','') IN ($__phPz)"
        );
        $__stPz->execute($__unic);
        $__mapPz = array();
        foreach ($__stPz->fetchAll() as $__row) {
            if (!isset($__mapPz[$__row['cnj_dg']])) $__mapPz[$__row['cnj_dg']] = $__row;
        }
        // Busca nomes dos responsaveis num batch
        $__respIds = array_filter(array_map(function($r){ return (int)$r['responsible_user_id']; }, $__mapPz));
        $__respNomes = array();
        if (!empty($__respIds)) {
            $__phR = implode(',', array_fill(0, count($__respIds), '?'));
            $__stR = db()->prepare("SELECT id, name FROM users WHERE id IN ($__phR)");
            $__stR->execute(array_values($__respIds));
            foreach ($__stR->fetchAll() as $__r) $__respNomes[(int)$__r['id']] = $__r['name'];
        }
        foreach ($__prazoSemCase as $__i => $__dg) {
            if (isset($__mapPz[$__dg])) {
                $__c = $__mapPz[$__dg];
                $__prazosUrgentes[$__i]['case_id']    = $__c['id'];
                $__prazosUrgentes[$__i]['case_title'] = $__c['title'];
                $__prazosUrgentes[$__i]['case_cnj']   = $__c['case_number'];
                $__prazosUrgentes[$__i]['comarca']    = $__c['comarca'];
                $__prazosUrgentes[$__i]['comarca_uf'] = $__c['comarca_uf'];
                $__prazosUrgentes[$__i]['vara']       = $__c['court'];
                if (!empty($__c['responsible_user_id']) && isset($__respNomes[(int)$__c['responsible_user_id']])) {
                    $__prazosUrgentes[$__i]['responsavel_name'] = $__respNomes[(int)$__c['responsible_user_id']];
                }
            }
        }
    } catch (Exception $__e) { /* falha silenciosa — card vai pra /prazos como antes */ }
}

// Helper local: formata CNJ se vier desformatado (20 digitos)
if (!function_exists('_layoutFormatCnj')) {
    function _layoutFormatCnj($num) {
        $d = preg_replace('/\D/', '', (string)$num);
        if (strlen($d) !== 20) return $num;
        return substr($d,0,7).'-'.substr($d,7,2).'.'.substr($d,9,4).'.'.substr($d,13,1).'.'.substr($d,14,2).'.'.substr($d,16,4);
    }
}
if (!empty($__prazosUrgentes)):
?>
<style>
.urg-banner { background:linear-gradient(135deg,#dc2626,#b91c1c); color:#fff; border-radius:10px; padding:.6rem 1rem; margin-bottom:.75rem; font-size:.78rem; }
.urg-banner.urg-tem-vencido { background:linear-gradient(135deg,#991b1b,#7f1d1d); box-shadow:0 0 0 2px rgba(220,38,38,.4), 0 4px 14px rgba(127,29,29,.4); animation:urgPulse 2.5s infinite; }
@keyframes urgPulse { 0%,100%{box-shadow:0 0 0 2px rgba(220,38,38,.4), 0 4px 14px rgba(127,29,29,.4);} 50%{box-shadow:0 0 0 6px rgba(220,38,38,.2), 0 6px 18px rgba(127,29,29,.55);} }
/* Recolhido: para de pulsar e fica discreto (so o resumo) */
.urg-banner.urg-recolhido { animation:none; box-shadow:0 2px 8px rgba(127,29,29,.3); margin-bottom:.5rem; }
.urg-banner.urg-recolhido .urg-hdr { margin-bottom:0; }
.urg-toggle-btn { background:rgba(255,255,255,.18); border:none; color:#fff; padding:.2rem .65rem; border-radius:14px; cursor:pointer; font-size:.7rem; font-weight:600; }
.urg-toggle-btn:hover { background:rgba(255,255,255,.3); }
.urg-row.urg-row-vencido { background:rgba(0,0,0,.18); }
.urg-row.urg-row-vencido .urg-label { background:#fff; color:#7f1d1d; padding:1px 8px; border-radius:6px; font-weight:800; }
.urg-banner .urg-hdr { display:flex; align-items:center; gap:.5rem; margin-bottom:.45rem; }
.urg-row { display:flex; align-items:center; gap:.55rem; padding:.45rem .55rem; border-top:1px solid rgba(255,255,255,.15); text-decoration:none; color:#fff; border-radius:6px; transition:background .15s; }
.urg-row:hover { background:rgba(255,255,255,.1); color:#fff; }
.urg-row .urg-label { font-weight:700; min-width:80px; flex-shrink:0; }
.urg-row .urg-meio { flex:1; min-width:0; }
.urg-row .urg-titulo { font-weight:600; line-height:1.35; }
.urg-row .urg-meta { font-size:.7rem; opacity:.85; display:flex; gap:.6rem; flex-wrap:wrap; margin-top:.15rem; }
.urg-row .urg-meta .urg-cnj { font-family:monospace; }
.urg-row .urg-data { margin-left:auto; flex-shrink:0; text-align:right; font-family:monospace; font-size:.78rem; font-weight:600; }
.urg-row .urg-data small { display:block; font-size:.65rem; opacity:.75; font-weight:400; font-family:inherit; }
</style>
<?php
// Conta vencidos vs proximos pra ajustar o titulo e a cor do banner
$__qtdVencidos = 0;
foreach ($__prazosUrgentes as $__pz) {
    if ((strtotime($__pz['prazo_fatal']) - strtotime(date('Y-m-d'))) < 0) $__qtdVencidos++;
}
$__qtdProximos = count($__prazosUrgentes) - $__qtdVencidos;
?>
<div class="no-print urg-banner<?= $__qtdVencidos > 0 ? ' urg-tem-vencido' : '' ?>">
    <div class="urg-hdr">
        <span style="font-size:1rem;"><?= $__qtdVencidos > 0 ? '🚨' : '⏰' ?></span>
        <strong>
            <?php if ($__qtdVencidos > 0): ?>
                <?= $__qtdVencidos ?> prazo(s) <span style="background:#fff;color:#7f1d1d;padding:1px 8px;border-radius:6px;font-size:.78rem;margin:0 .25rem;">VENCIDO(S)</span>
                <?php if ($__qtdProximos > 0): ?>+ <?= $__qtdProximos ?> nos próximos 3 dias<?php endif; ?>
            <?php else: ?>
                <?= count($__prazosUrgentes) ?> prazo(s) nos próximos 3 dias!
            <?php endif; ?>
        </strong>
        <button type="button" id="urgBannerToggle" onclick="urgBannerToggle()" class="urg-toggle-btn" style="margin-left:auto;">▲ recolher</button>
        <a href="<?= url('modules/prazos/') ?>" style="color:#fecaca;margin-left:.6rem;font-size:.7rem;text-decoration:underline;">Ver todos →</a>
    </div>
    <div id="urgBannerLista">
    <?php foreach ($__prazosUrgentes as $__pz):
        $__diasPz = (int)((strtotime($__pz['prazo_fatal']) - strtotime(date('Y-m-d'))) / 86400);
        if ($__diasPz < 0) {
            $__urgLabel = '🚨 VENCIDO há ' . abs($__diasPz) . 'd';
        } elseif ($__diasPz === 0) {
            $__urgLabel = '🔴 HOJE';
        } elseif ($__diasPz === 1) {
            $__urgLabel = '🟡 AMANHÃ';
        } else {
            $__urgLabel = '⚠️ ' . $__diasPz . 'd';
        }
        $__rowExtra = $__diasPz < 0 ? ' urg-row-vencido' : '';
        $__caseHref = $__pz['case_id'] ? url('modules/operacional/caso_ver.php?id=' . $__pz['case_id']) : url('modules/prazos/');
        $__cnjFmt = $__pz['case_cnj'] ? _layoutFormatCnj($__pz['case_cnj']) : ($__pz['numero_processo'] ? _layoutFormatCnj($__pz['numero_processo']) : '');
        $__localTxt = '';
        if (!empty($__pz['vara']))    $__localTxt .= $__pz['vara'];
        if (!empty($__pz['comarca'])) $__localTxt .= ($__localTxt ? ' — ' : '') . $__pz['comarca'] . (!empty($__pz['comarca_uf']) ? '/' . $__pz['comarca_uf'] : '');
    ?>
    <div class="urg-row<?= $__rowExtra ?>" data-prazo-id="<?= (int)$__pz['id'] ?>" style="display:flex;align-items:center;gap:.55rem;padding:.45rem .55rem;border-top:1px solid rgba(255,255,255,.15);">
        <button type="button" class="urg-fechar-btn" data-prazo-id="<?= (int)$__pz['id'] ?>" data-prazo-nome="<?= e($__pz['descricao_acao'] ?: ($__pz['descricao'] ?? 'Prazo')) ?>" data-csrf="<?= e(generate_csrf_token()) ?>" title="Marcar este prazo como cumprido / não tem o que fazer" style="background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.4);color:#fff;padding:2px 8px;border-radius:50%;cursor:pointer;font-weight:700;font-size:.9rem;line-height:1;flex-shrink:0;">✓</button>
        <a href="<?= e($__caseHref) ?>" title="Abrir pasta do processo" style="display:flex;flex:1;align-items:center;gap:.55rem;text-decoration:none;color:#fff;">
        <span class="urg-label"><?= $__urgLabel ?></span>
        <div class="urg-meio">
            <div class="urg-titulo">
                <?= e($__pz['descricao_acao']) ?>
                <?php if (!empty($__pz['case_title'])): ?>
                    <span style="opacity:.85;font-weight:500;"> — <?= e($__pz['case_title']) ?></span>
                <?php endif; ?>
            </div>
            <div class="urg-meta">
                <?php if ($__pz['client_name']): ?><span>👤 <?= e($__pz['client_name']) ?></span><?php endif; ?>
                <?php if ($__cnjFmt): ?><span class="urg-cnj">📋 <?= e($__cnjFmt) ?></span><?php endif; ?>
                <?php if ($__localTxt): ?><span>📍 <?= e($__localTxt) ?></span><?php endif; ?>
                <?php if (!empty($__pz['responsavel_name'])): ?><span>⚖ <?= e(explode(' ', $__pz['responsavel_name'])[0]) ?></span><?php endif; ?>
            </div>
        </div>
        <span class="urg-data">
            <?= date('d/m', strtotime($__pz['prazo_fatal'])) ?>
            <small><?= date('D', strtotime($__pz['prazo_fatal'])) ?></small>
        </span>
        </a>
    </div>
    <?php endforeach; ?>
    </div><!-- /#urgBannerLista -->
</div>
<script>
// Recolher/expandir o banner de prazos urgentes (pedido Amanda 19/06/2026 —
// "nao ficar tao gritante"). Guarda a preferencia no localStorage e para a
// animacao pulsante quando recolhido. Resumo (cabecalho) fica sempre visivel.
window.urgBannerToggle = function() {
    var lst = document.getElementById('urgBannerLista');
    var btn = document.getElementById('urgBannerToggle');
    var banner = document.querySelector('.urg-banner');
    if (!lst) return;
    var vaiRecolher = (lst.style.display !== 'none');
    if (vaiRecolher) {
        lst.style.display = 'none';
        if (btn) btn.textContent = '▼ ver lista';
        if (banner) banner.classList.add('urg-recolhido');
        try { localStorage.setItem('urgBannerRecolhido', '1'); } catch(e){}
    } else {
        lst.style.display = '';
        if (btn) btn.textContent = '▲ recolher';
        if (banner) banner.classList.remove('urg-recolhido');
        try { localStorage.setItem('urgBannerRecolhido', '0'); } catch(e){}
    }
};
(function(){
    // Aplica preferencia salva ao carregar
    try {
        if (localStorage.getItem('urgBannerRecolhido') === '1') {
            var lst = document.getElementById('urgBannerLista');
            var btn = document.getElementById('urgBannerToggle');
            var banner = document.querySelector('.urg-banner');
            if (lst) lst.style.display = 'none';
            if (btn) btn.textContent = '▼ ver lista';
            if (banner) banner.classList.add('urg-recolhido');
        }
    } catch(e){}

    document.querySelectorAll('.urg-fechar-btn').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault(); e.stopPropagation();
            // Pedido Amanda 31/05/2026: pergunta se cumpriu + o que fez (vira andamento)
            var nomePrazo = btn.getAttribute('data-prazo-nome') || 'esse prazo';
            if (!confirm('✅ Já cumpriu esse prazo?\n\n"' + nomePrazo + '"')) return;
            var oque = prompt(
                '🟢 Ótimo! Em poucas palavras, o que foi feito?\n\n' +
                'Esse texto vai ser registrado como ANDAMENTO INTERNO do processo ' +
                '(invisível ao cliente). Pode deixar em branco se quiser só dar baixa sem registrar.',
                ''
            );
            if (oque === null) return; // clicou Cancelar no prompt
            var pid = btn.getAttribute('data-prazo-id');
            var csrf = btn.getAttribute('data-csrf');
            btn.disabled = true; btn.style.opacity = '.4';
            var fd = new FormData();
            fd.append('action', 'concluir_prazo');
            fd.append('prazo_id', pid);
            fd.append('case_id', '0');
            fd.append('descricao_cumprimento', (oque || '').trim());
            fd.append('csrf_token', csrf);
            fetch('<?= url("modules/operacional/api.php") ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(){
                    var linha = btn.closest('.urg-row');
                    if (linha) linha.style.display = 'none';
                    // Se nao tem mais linhas, esconde banner inteiro
                    var visiveis = document.querySelectorAll('.urg-banner .urg-row:not([style*="display: none"])');
                    if (!visiveis.length) {
                        var banner = document.querySelector('.urg-banner');
                        if (banner) banner.style.display = 'none';
                    }
                })
                .catch(function(){
                    alert('Falha ao marcar. Tente recarregar a página.');
                    btn.disabled = false; btn.style.opacity = '';
                });
        });
    });
})();
</script>
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
