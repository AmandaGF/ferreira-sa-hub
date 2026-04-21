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
<!-- PWA: service worker + install prompt + update banner -->
<script>
(function() {
    if (!('serviceWorker' in navigator)) return;

    navigator.serviceWorker.register('<?= url('sw.js') ?>').then(function(reg) {
        // Detecta nova versão disponível
        reg.addEventListener('updatefound', function() {
            var nw = reg.installing;
            if (!nw) return;
            nw.addEventListener('statechange', function() {
                if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                    mostrarBannerUpdate(function() {
                        nw.postMessage({ type: 'SKIP_WAITING' });
                    });
                }
            });
        });
    }).catch(function(){});

    // Recarrega quando SW novo assume
    var reloaded = false;
    navigator.serviceWorker.addEventListener('controllerchange', function() {
        if (reloaded) return;
        reloaded = true;
        location.reload();
    });

    // ── Install prompt (Android/Chrome/Edge) ──
    var deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        // Só mostra se não foi dispensado antes (localStorage)
        if (localStorage.getItem('fsa_install_dispensado') === '1') return;
        mostrarBotaoInstalar();
    });

    window.addEventListener('appinstalled', function() {
        esconderBotaoInstalar();
        deferredPrompt = null;
    });

    function mostrarBotaoInstalar() {
        if (document.getElementById('fsaInstallBtn')) return;
        var btn = document.createElement('button');
        btn.id = 'fsaInstallBtn';
        btn.innerHTML = '📲 Instalar Hub';
        btn.style.cssText = 'position:fixed;bottom:16px;right:16px;background:#B87333;color:#fff;border:none;padding:.7rem 1.1rem;border-radius:999px;font-weight:700;font-size:.82rem;cursor:pointer;box-shadow:0 8px 24px rgba(184,115,51,.4);z-index:9998;display:flex;align-items:center;gap:.4rem;';
        btn.onclick = function() {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function() {
                deferredPrompt = null;
                esconderBotaoInstalar();
            });
        };
        // Botão de dispensar
        var close = document.createElement('span');
        close.textContent = '×';
        close.style.cssText = 'margin-left:4px;opacity:.7;font-size:1rem;line-height:1;padding:0 2px;';
        close.onclick = function(ev) {
            ev.stopPropagation();
            localStorage.setItem('fsa_install_dispensado', '1');
            esconderBotaoInstalar();
        };
        btn.appendChild(close);
        document.body.appendChild(btn);
    }

    function esconderBotaoInstalar() {
        var b = document.getElementById('fsaInstallBtn');
        if (b) b.remove();
    }

    function mostrarBannerUpdate(callback) {
        if (document.getElementById('fsaUpdateBanner')) return;
        var banner = document.createElement('div');
        banner.id = 'fsaUpdateBanner';
        banner.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#059669;color:#fff;padding:.55rem 1rem;text-align:center;font-size:.8rem;font-weight:600;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,.2);';
        banner.innerHTML = '✨ Nova versão do Hub disponível. <button id="fsaUpdateNow" style="background:#fff;color:#059669;border:none;padding:3px 10px;border-radius:6px;margin-left:8px;cursor:pointer;font-weight:700;font-size:.78rem;">Atualizar agora</button>';
        document.body.appendChild(banner);
        document.getElementById('fsaUpdateNow').onclick = callback;
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
</script>

<?php if (!empty($extraJs)): ?>
    <script><?= $extraJs ?></script>
<?php endif; ?>
</body>
</html>
