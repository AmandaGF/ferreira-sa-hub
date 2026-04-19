        </div><!-- /.page-content -->
    </main><!-- /.main-content -->
</div><!-- /.app-layout -->

<script src="<?= url('assets/js/conecta.js') ?>"></script>
<script src="<?= url('assets/js/helpers.js') ?>"></script>
<script src="<?= url('assets/js/drawer.js') ?>?v=<?= date('YmdHi') ?>"></script>
<script src="<?= url('assets/js/busca_cpf.js') ?>"></script>
<script src="<?= url('assets/js/gamificacao-efeitos.js') ?>"></script>
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('<?= url('sw.js') ?>').catch(function(){});}</script>

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
