        </div><!-- /.page-content -->
    </main><!-- /.main-content -->
</div><!-- /.app-layout -->

<script>
    // Variáveis globais pro wa_sender (envio direto de WhatsApp pelo Hub)
    window.FSA_CSRF = '<?= generate_csrf_token() ?>';
    window.FSA_WHATSAPP_API_URL = '<?= module_url('whatsapp', 'api.php') ?>';
</script>
<script src="<?= url('assets/js/conecta.js') ?>"></script>
<script src="<?= url('assets/js/helpers.js') ?>"></script>
<script src="<?= url('assets/js/drawer.js') ?>?v=<?= date('YmdHi') ?>"></script>
<script src="<?= url('assets/js/busca_cpf.js') ?>"></script>
<script src="<?= url('assets/js/gamificacao-efeitos.js') ?>"></script>
<script src="<?= url('assets/js/wa_sender.js') ?>?v=<?= date('YmdHi') ?>"></script>
<script>
// Click handler pras notificações cujo link era wa.me/... — em vez de abrir
// o WhatsApp externo, dispara o waSenderOpen do Hub. layout_start.php
// renderiza essas notificações com data-wa-* attrs e onclick="fsaNotifClickWa(this); return false;"
window.fsaNotifClickWa = function(el) {
    var phone = el.getAttribute('data-wa-phone') || '';
    var name  = el.getAttribute('data-wa-name')  || '';
    var text  = el.getAttribute('data-wa-text')  || '';
    var notifId = el.getAttribute('data-notif-id') || '';

    // Marca a notificação como lida em background (fire-and-forget XHR)
    if (notifId) {
        try {
            var rxhr = new XMLHttpRequest();
            rxhr.open('GET', '<?= url('modules/notificacoes/api.php') ?>?action=read&id=' + encodeURIComponent(notifId), true);
            rxhr.send();
        } catch (e) {}
        // Atualiza visualmente
        el.classList.add('read');
        // Decrementa contador do badge (se houver)
        var badge = document.querySelector('.notif-badge, #notifBadge');
        if (badge) {
            var n = parseInt(badge.textContent, 10) || 0;
            if (n > 1) badge.textContent = (n - 1);
            else if (badge.style) badge.style.display = 'none';
        }
    }

    // Abre o WhatsApp do Hub
    if (typeof window.waSenderOpen === 'function') {
        window.waSenderOpen({
            telefone: phone,
            nome:     name || 'Cliente',
            mensagem: text,
            canal:    '24'
        });
    } else {
        alert('WhatsApp do Hub não carregado. Recarregue a página.');
    }
};
</script>
<script src="<?= url('assets/js/fix-webm-duration.js') ?>?v=<?= date('YmdHi') ?>"></script>
<script src="<?= url('assets/js/nvoip.js') ?>?v=<?= date('YmdHi') ?>"></script>
<!-- PWA: service worker + install prompt + update banner -->
<script>
(function() {
    if (!('serviceWorker' in navigator)) return;

    var _swReg = null;
    navigator.serviceWorker.register('<?= url('sw.js') ?>').then(function(reg) {
        _swReg = reg;
        // Se já tem um SW em waiting quando a página carregou, mostra banner
        if (reg.waiting && navigator.serviceWorker.controller) {
            mostrarBannerUpdate(acionarUpdate);
        }
        // Detecta nova versão disponível durante a sessão
        reg.addEventListener('updatefound', function() {
            var nw = reg.installing;
            if (!nw) return;
            nw.addEventListener('statechange', function() {
                if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                    mostrarBannerUpdate(acionarUpdate);
                }
            });
        });
    }).catch(function(){});

    function acionarUpdate() {
        if (!_swReg) { location.reload(); return; }
        var target = _swReg.waiting || _swReg.installing;
        if (target) {
            try { target.postMessage({ type: 'SKIP_WAITING' }); } catch (e) {}
        }
        // Fallback: se o controllerchange não disparar em 1.5s (comum em iOS/mobile),
        // força reload — garante que a versão nova seja carregada sem o usuário ficar travado
        setTimeout(function() { location.reload(); }, 1500);
    }

    // Recarrega quando SW novo assume (desktop geralmente)
    var reloaded = false;
    navigator.serviceWorker.addEventListener('controllerchange', function() {
        if (reloaded) return;
        reloaded = true;
        location.reload();
    });

    // ── Install prompt (Android/Chrome/Edge) ──
    // Expõe o prompt globalmente pra botão permanente da sidebar acessá-lo
    window._fsaDeferredPrompt = null;
    var deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        window._fsaDeferredPrompt = e;
    });

    window.addEventListener('appinstalled', function() {
        deferredPrompt = null;
        window._fsaDeferredPrompt = null;
    });

    function mostrarBannerUpdate(callback) {
        if (document.getElementById('fsaUpdateBanner')) return;
        var banner = document.createElement('div');
        banner.id = 'fsaUpdateBanner';
        banner.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#059669;color:#fff;padding:.55rem 1rem;text-align:center;font-size:.8rem;font-weight:600;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,.2);';
        banner.innerHTML = '✨ Nova versão do Hub disponível. <button id="fsaUpdateNow" style="background:#fff;color:#059669;border:none;padding:4px 12px;border-radius:6px;margin-left:8px;cursor:pointer;font-weight:700;font-size:.78rem;-webkit-tap-highlight-color:transparent;">Atualizar agora</button>';
        document.body.appendChild(banner);
        document.getElementById('fsaUpdateNow').onclick = function() {
            this.textContent = 'Atualizando...';
            this.disabled = true;
            this.style.opacity = '.7';
            try { callback(); } catch (e) { location.reload(); }
        };
    }

    // ── Web Push ──
    <?php
    try {
        $__vapidRow = db()->query("SELECT valor FROM configuracoes WHERE chave = 'vapid_public'")->fetchColumn();
    } catch (Exception $e) { $__vapidRow = ''; }
    ?>
    var VAPID_PUBLIC = '<?= e($__vapidRow ?: '') ?>';
    var PUSH_SUB_URL = '<?= url('api/push_subscribe.php') ?>';

    function urlBase64ToUint8Array(b64) {
        var pad = '='.repeat((4 - b64.length % 4) % 4);
        var b64std = (b64 + pad).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(b64std);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function arrayBufferToBase64url(buf) {
        var bytes = new Uint8Array(buf);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
    }

    function pushSubscribe(onStatus) {
        var say = onStatus || function(){};
        if (!VAPID_PUBLIC) { say('erro', 'Chave VAPID ausente — rode migrar_push_subs.php'); return; }
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) { say('erro', 'Browser não suporta Web Push'); return; }

        say('info', 'Aguardando service worker...');
        navigator.serviceWorker.ready.then(function(reg) {
            say('info', 'Registrando subscription no browser...');
            return reg.pushManager.getSubscription().then(function(existing) {
                if (existing) { say('info', 'Subscription existente encontrada'); return existing; }
                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC)
                });
            });
        }).then(function(sub) {
            if (!sub) { say('erro', 'pushManager.subscribe retornou vazio'); return; }
            say('info', 'Enviando pro servidor...');
            var body = {
                endpoint:   sub.endpoint,
                p256dh:     arrayBufferToBase64url(sub.getKey('p256dh')),
                auth:       arrayBufferToBase64url(sub.getKey('auth')),
                user_agent: navigator.userAgent
            };
            return fetch(PUSH_SUB_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify(body)
            }).then(function(r) { return r.json().then(function(j){ return {status: r.status, body: j}; }); });
        }).then(function(r) {
            if (!r) return;
            if (r.status >= 200 && r.status < 300 && r.body && r.body.ok) {
                say('ok', '✅ Notificações ativadas! (' + (r.body.status || 'registrado') + ')');
            } else {
                say('erro', 'Servidor rejeitou: ' + JSON.stringify(r.body));
            }
        }).catch(function(e) {
            say('erro', 'Falha: ' + (e && e.message ? e.message : e));
            console.warn('[Push] erro subscribe:', e);
        });
    }

    function perguntarPushDepois() {
        // Só pede se: VAPID configurado, não foi dispensado, permissão ainda é 'default'
        if (!VAPID_PUBLIC) return;
        if (localStorage.getItem('fsa_push_dispensado') === '1') return;
        if (typeof Notification === 'undefined' || Notification.permission !== 'default') {
            if (Notification && Notification.permission === 'granted') pushSubscribe();
            return;
        }
        // Banner discreto 30s após o load, não modal intrusiva
        setTimeout(function() {
            if (document.getElementById('fsaPushAsk')) return;
            var b = document.createElement('div');
            b.id = 'fsaPushAsk';
            b.style.cssText = 'position:fixed;bottom:16px;left:16px;background:#052228;color:#fff;padding:.8rem 1rem;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.3);z-index:9998;max-width:320px;font-size:.82rem;';
            b.innerHTML = '🔔 <strong>Receber notificações do Hub?</strong><br><span style="opacity:.8;font-size:.75rem;">Novo lead, WhatsApp, prazo urgente — direto no seu dispositivo.</span><div style="margin-top:.6rem;display:flex;gap:.4rem;">'
                + '<button id="fsaPushYes" style="background:#B87333;color:#fff;border:none;padding:5px 12px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.75rem;">Ativar</button>'
                + '<button id="fsaPushNo" style="background:transparent;color:#94a3b8;border:1px solid #334155;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:.75rem;">Agora não</button></div>';
            document.body.appendChild(b);
            document.getElementById('fsaPushYes').onclick = function() {
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Aguardando...';

                // Função pra mostrar status dentro do próprio banner (troca o conteúdo)
                var showStatus = function(tipo, msg) {
                    var cor = tipo === 'ok' ? '#22c55e' : (tipo === 'erro' ? '#ef4444' : '#cbd5e1');
                    b.innerHTML = '<div style="font-size:.82rem;color:' + cor + ';"><strong>' + (tipo === 'ok' ? '✅' : (tipo === 'erro' ? '❌' : '⏳')) + '</strong> ' + msg.replace(/</g,'&lt;') + '</div>'
                        + '<button onclick="document.getElementById(\'fsaPushAsk\').remove()" style="margin-top:.5rem;background:transparent;color:#94a3b8;border:1px solid #334155;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:.72rem;">Fechar</button>';
                };

                if (Notification.permission === 'denied') {
                    showStatus('erro', 'Notificações estão bloqueadas no browser. Clique no cadeado da URL → Permissões → Notificações → Permitir, e tente de novo.');
                    return;
                }

                Notification.requestPermission().then(function(p) {
                    if (p === 'denied') {
                        showStatus('erro', 'Permissão negada. Pra ativar depois: cadeado da URL → Permissões → Notificações → Permitir.');
                        return;
                    }
                    if (p === 'default') {
                        showStatus('erro', 'Permissão não concedida (fechou o prompt sem responder). Tente de novo.');
                        return;
                    }
                    // granted
                    pushSubscribe(showStatus);
                }).catch(function(e) {
                    showStatus('erro', 'Erro ao pedir permissão: ' + e.message);
                });
            };
            document.getElementById('fsaPushNo').onclick = function() {
                localStorage.setItem('fsa_push_dispensado', '1');
                b.remove();
            };
        }, 30000);
    }

    // Se permissão já concedida, garante re-subscribe (útil após troca de dispositivo)
    if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
        navigator.serviceWorker.ready.then(pushSubscribe).catch(function(){});
    } else {
        perguntarPushDepois();
    }
})();
</script>

<!-- Heartbeat: mantém sessão viva e CSRF sincronizado (pró-ativo) -->
<script>
(function(){
    var HEARTBEAT_MS = 4 * 60 * 1000; // 4 minutos
    var HEARTBEAT_URL = '<?= url('api/heartbeat.php') ?>';
    var LOGIN_URL     = '<?= url('auth/login.php') ?>';

    function heartbeat() {
        fetch(HEARTBEAT_URL, { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r){
                if (r.status === 401) { mostrarModalSessaoExpirada(); return null; }
                return r.json();
            })
            .then(function(d){
                if (!d || !d.ok) return;
                // Atualiza todos inputs hidden csrf_token com token fresco
                document.querySelectorAll('input[name="csrf_token"]').forEach(function(el){ el.value = d.csrf; });
                // Atualiza variável global se algum script a usar
                window._FSA_CSRF = d.csrf;
            })
            .catch(function(){ /* silencioso se for erro de rede passageiro */ });
    }

    function mostrarModalSessaoExpirada() {
        if (document.getElementById('fsaModalSessao')) return;
        var m = document.createElement('div');
        m.id = 'fsaModalSessao';
        m.innerHTML =
            '<div style="position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99999;display:flex;align-items:center;justify-content:center;padding:1rem;">' +
              '<div style="background:#fff;border-radius:14px;padding:2rem;max-width:440px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.3);">' +
                '<div style="font-size:3rem;margin-bottom:.5rem;">🔒</div>' +
                '<h2 style="margin:0 0 .5rem;color:#0f2140;">Sessão expirada</h2>' +
                '<p style="margin:0 0 1.2rem;color:#6b7280;font-size:.9rem;">Por segurança, sua sessão foi encerrada. Faça login novamente para continuar — qualquer alteração não salva pode ter sido perdida.</p>' +
                '<button onclick="window.location.href=\'' + LOGIN_URL + '?voltar=\'+encodeURIComponent(location.pathname+location.search)" style="background:#d7ab90;color:#fff;border:none;padding:12px 28px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.95rem;">Fazer login novamente</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(m);
    }

    // Expor pra interceptadores AJAX usarem (ex: saveCell do pipeline)
    window.fsaMostrarSessaoExpirada = mostrarModalSessaoExpirada;

    // Primeira batida em ~30s (não logo no load, pra evitar duplicar se houver redirect inicial)
    setTimeout(heartbeat, 30000);
    setInterval(heartbeat, HEARTBEAT_MS);
})();

/**
 * Intercept GLOBAL de window.fetch — protege saves AJAX silenciosos em TODAS as páginas.
 *
 * Antes disso, dezenas de saves AJAX (whatsapp, drawer, pipeline, wa_sender, etc.)
 * mostravam ✓ "salvo" quando a sessão expirava — server retornava redirect HTML pro login,
 * fetch tratava como sucesso, JSON.parse silenciava erro, código de "salvo" rodava
 * sobre dado vazio. Bug sistêmico documentado em CLAUDE.md (mar/abr 2026).
 *
 * O intercept só age em URLs same-origin (não polui requisições a CDN/APIs externas).
 *  - Adiciona header X-Requested-With: XMLHttpRequest → middleware retorna JSON 401/403
 *  - Em 401 → mostra modal de sessão expirada e devolve Response neutro (não crasha .json())
 */
(function(){
    if (window._fsaFetchPatched) return;
    window._fsaFetchPatched = true;
    if (typeof window.fetch !== 'function') return; // browser muito antigo, sem fetch
    var _origFetch = window.fetch.bind(window);
    function isSameOrigin(input) {
        try {
            var u = (typeof input === 'string') ? input : (input && input.url ? input.url : '');
            if (!u) return true;
            if (u.indexOf('//') === -1) return true;            // relative
            if (u.indexOf(location.origin) === 0) return true;  // mesmo origin
            return false;
        } catch(e) { return true; }
    }
    window.fetch = function(input, opts) {
        if (!isSameOrigin(input)) return _origFetch(input, opts);
        opts = opts || {};
        if (!(opts.headers instanceof Headers)) {
            opts.headers = opts.headers || {};
            if (!opts.headers['X-Requested-With']) opts.headers['X-Requested-With'] = 'XMLHttpRequest';
        }
        if (!opts.credentials) opts.credentials = 'same-origin';
        return _origFetch(input, opts).then(function(r) {
            if (r.status === 401) {
                if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada();
                return new Response(JSON.stringify({ ok: false, error: '__SESSAO_EXPIRADA__' }), { status: 401, headers: { 'Content-Type': 'application/json' } });
            }
            return r;
        });
    };
})();

/**
 * Intercept GLOBAL de XMLHttpRequest — protege XHRs (drawer.js, código legado, etc.)
 * com a mesma lógica do intercept de fetch.
 *
 * Estratégia: wrap o `send()`. Antes de chamar o original:
 *   - injeta header X-Requested-With (best-effort; setRequestHeader silencia se inválido)
 *   - wrap o `onload` que o usuário definiu, pra checar status 401 antes do callback original
 *
 * Limitações: se o código setar `onload` DEPOIS de chamar `send()` (anti-padrão), o
 * wrap não pega. Mas todo o código nosso segue open() → setHeaders → onload= → send().
 */
(function(){
    if (window._fsaXhrPatched) return;
    if (typeof XMLHttpRequest !== 'function') return;
    window._fsaXhrPatched = true;
    var XP = XMLHttpRequest.prototype;
    var origOpen = XP.open;
    var origSend = XP.send;
    XP.open = function(method, url) {
        try {
            this._fsaUrl = url || '';
            this._fsaSameOrigin = (typeof url !== 'string') || (url.indexOf('//') === -1) || (url.indexOf(location.origin) === 0);
        } catch(e) { this._fsaSameOrigin = true; }
        return origOpen.apply(this, arguments);
    };
    XP.send = function(body) {
        var xhr = this;
        if (xhr._fsaSameOrigin !== false) {
            try { xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); } catch(e) {}
            var origOnLoad = xhr.onload;
            xhr.onload = function() {
                if (xhr.status === 401) {
                    if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada();
                    return; // não roda o callback original — dado é inválido
                }
                if (origOnLoad) return origOnLoad.apply(this, arguments);
            };
        }
        return origSend.apply(this, arguments);
    };
})();

/**
 * Helper global pra AJAX seguros — evita "saves silenciosos" quando sessão expira.
 *
 * Padrão correto:
 *   var data = await fsaFetch(url, {method:'POST', body: fd});
 *   if (data === null) return; // 401: modal de sessão expirada já foi mostrado
 *
 * Comportamento:
 *  - injeta header X-Requested-With: XMLHttpRequest (faz middleware retornar JSON 401/403 em vez de HTML do login)
 *  - se response.status === 401 → mostra modal de sessão expirada e retorna null
 *  - se response.status === 403 → alerta permissão e retorna null
 *  - !response.ok → tenta parsear JSON e retorna {ok:false, erro:...} se for JSON, senão {ok:false, erro:'HTTP <status>'}
 *  - sucesso → retorna o JSON parsed (ou texto se response não-JSON)
 */
window.fsaFetch = function(url, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    headers['X-Requested-With'] = 'XMLHttpRequest';
    opts.headers = headers;
    if (!opts.credentials) opts.credentials = 'same-origin';

    return fetch(url, opts).then(function(r) {
        if (r.status === 401) {
            if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada();
            return null;
        }
        if (r.status === 403) {
            try { window.alert('Sem permissão para essa ação.'); } catch(e) {}
            return null;
        }
        var ct = (r.headers.get('Content-Type') || '').toLowerCase();
        if (ct.indexOf('application/json') !== -1) {
            return r.json().catch(function(){ return { ok: false, erro: 'JSON inválido' }; });
        }
        return r.text();
    }).catch(function(e) {
        return { ok: false, erro: 'Erro de rede: ' + (e && e.message ? e.message : 'desconhecido') };
    });
};
</script>

<?php if (!empty($extraJs)): ?>
    <script><?= $extraJs ?></script>
<?php endif; ?>
</body>
</html>
