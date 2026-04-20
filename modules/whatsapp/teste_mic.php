<?php
/**
 * Diagnóstico de acesso ao microfone.
 * Checa contexto seguro, suporte da API, estado da permissão e tenta
 * chamar getUserMedia com detalhamento completo do erro.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
$pageTitle = 'Diagnóstico do microfone';
require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.diag { max-width:800px;margin:1rem auto; }
.diag .box { background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1rem 1.25rem;margin-bottom:.75rem; }
.diag .row { display:grid;grid-template-columns:240px 1fr;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #f3f4f6;font-size:.85rem; }
.diag .row:last-child { border:none; }
.diag .row strong { color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.3px; }
.diag .ok { color:#059669;font-weight:700; }
.diag .warn { color:#d97706;font-weight:700; }
.diag .err { color:#dc2626;font-weight:700; }
.diag pre { background:#1f2937;color:#f9fafb;padding:.75rem;border-radius:8px;font-size:.75rem;overflow:auto;max-height:200px; }
.diag .btn-big { background:#7c3aed;color:#fff;padding:.7rem 1.5rem;border:none;border-radius:8px;font-size:.95rem;font-weight:700;cursor:pointer; }
.diag h2 { margin:.5rem 0;color:#052228;font-size:1rem; }
</style>

<div class="diag">
    <h1 style="margin:0 0 1rem;">🎤 Diagnóstico do Microfone</h1>
    <p style="color:#6b7280;">Esta tela mostra exatamente o que o navegador responde quando o Hub tenta acessar o microfone. Útil quando aparece erro sem motivo claro.</p>

    <div class="box">
        <h2>1. Ambiente</h2>
        <div class="row"><strong>Protocolo (precisa ser HTTPS)</strong><span id="diagProto">—</span></div>
        <div class="row"><strong>Contexto seguro</strong><span id="diagSecure">—</span></div>
        <div class="row"><strong>Dentro de iframe?</strong><span id="diagIframe">—</span></div>
        <div class="row"><strong>User Agent</strong><span id="diagUA" style="font-size:.75rem;">—</span></div>
    </div>

    <div class="box">
        <h2>2. APIs disponíveis</h2>
        <div class="row"><strong>navigator.mediaDevices</strong><span id="diagMD">—</span></div>
        <div class="row"><strong>getUserMedia</strong><span id="diagGUM">—</span></div>
        <div class="row"><strong>MediaRecorder</strong><span id="diagMR">—</span></div>
        <div class="row"><strong>API Permissions</strong><span id="diagPerms">—</span></div>
        <div class="row"><strong>Estado permissão microfone</strong><span id="diagPermState">—</span></div>
    </div>

    <div class="box">
        <h2>3. Dispositivos detectados</h2>
        <div id="diagDevices" style="font-size:.82rem;color:#6b7280;">Aguardando permissão pra listar...</div>
    </div>

    <div class="box">
        <h2>4. Teste real</h2>
        <button id="btnTestar" class="btn-big">🎤 Testar acesso ao microfone</button>
        <div id="diagTeste" style="margin-top:.75rem;"></div>
    </div>

    <p style="text-align:center;margin-top:1rem;"><a href="<?= module_url('whatsapp') ?>">← Voltar pro WhatsApp</a></p>
</div>

<script>
(function(){
    // 1. Ambiente
    var proto = location.protocol;
    var isHttps = (proto === 'https:');
    document.getElementById('diagProto').innerHTML = proto + (isHttps ? ' <span class="ok">✓</span>' : ' <span class="err">✗ Precisa ser HTTPS</span>');
    document.getElementById('diagSecure').innerHTML = window.isSecureContext ? '<span class="ok">✓ sim</span>' : '<span class="err">✗ não (bloqueia microfone)</span>';
    var inIframe = (window.self !== window.top);
    document.getElementById('diagIframe').innerHTML = inIframe ? '<span class="warn">⚠ SIM — iframes precisam de allow="microphone"</span>' : '<span class="ok">✓ não</span>';
    document.getElementById('diagUA').textContent = navigator.userAgent;

    // 2. APIs
    var mdOk = !!navigator.mediaDevices;
    document.getElementById('diagMD').innerHTML = mdOk ? '<span class="ok">✓ disponível</span>' : '<span class="err">✗ ausente</span>';
    var gumOk = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    document.getElementById('diagGUM').innerHTML = gumOk ? '<span class="ok">✓ disponível</span>' : '<span class="err">✗ ausente</span>';
    var mrOk = !!window.MediaRecorder;
    document.getElementById('diagMR').innerHTML = mrOk ? '<span class="ok">✓ disponível</span>' : '<span class="err">✗ ausente</span>';
    var permOk = !!(navigator.permissions && navigator.permissions.query);
    document.getElementById('diagPerms').innerHTML = permOk ? '<span class="ok">✓ disponível</span>' : '<span class="warn">⚠ não suportada (ok em muitos browsers)</span>';

    if (permOk) {
        navigator.permissions.query({ name: 'microphone' }).then(function(st){
            var cls = st.state === 'granted' ? 'ok' : (st.state === 'denied' ? 'err' : 'warn');
            document.getElementById('diagPermState').innerHTML = '<span class="' + cls + '">' + st.state + '</span>';
        }).catch(function(err){
            document.getElementById('diagPermState').innerHTML = '<span class="warn">Erro: ' + err.message + '</span>';
        });
    } else {
        document.getElementById('diagPermState').textContent = 'n/d (API não suportada)';
    }

    // 3. Dispositivos (só funciona se já tiver permissão OU após tentar)
    function atualizarDispositivos() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
            document.getElementById('diagDevices').innerHTML = '<span class="err">enumerateDevices não suportado</span>';
            return;
        }
        navigator.mediaDevices.enumerateDevices().then(function(devices){
            var mics = devices.filter(function(d){ return d.kind === 'audioinput'; });
            if (mics.length === 0) {
                document.getElementById('diagDevices').innerHTML = '<span class="err">❌ Nenhum microfone detectado pelo sistema.</span>';
                return;
            }
            var html = '<ul style="margin:0;padding-left:20px;">';
            mics.forEach(function(d, i){
                var label = d.label || '(label oculto — precisa dar permissão primeiro)';
                html += '<li style="margin-bottom:4px;">' + label + (d.deviceId === 'default' ? ' <strong>(padrão)</strong>' : '') + '</li>';
            });
            html += '</ul>';
            document.getElementById('diagDevices').innerHTML = html;
        });
    }
    atualizarDispositivos();

    // 4. Teste
    document.getElementById('btnTestar').onclick = function(){
        var out = document.getElementById('diagTeste');
        out.innerHTML = '<p style="color:#6b7280;">Solicitando acesso... (se aparecer popup, clique Permitir)</p>';
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream){
            out.innerHTML = '<p class="ok" style="font-size:1.1rem;">✅ Acesso concedido! O microfone está funcionando normalmente.</p>'
                          + '<p style="font-size:.82rem;color:#6b7280;">Tracks ativas: ' + stream.getAudioTracks().length + '</p>';
            var t = stream.getAudioTracks()[0];
            if (t) {
                out.innerHTML += '<pre>' + JSON.stringify({
                    label: t.label,
                    kind: t.kind,
                    enabled: t.enabled,
                    muted: t.muted,
                    readyState: t.readyState,
                    settings: t.getSettings ? t.getSettings() : null
                }, null, 2) + '</pre>';
            }
            // Para o stream pra não ficar gravando
            stream.getTracks().forEach(function(tr){ tr.stop(); });
            atualizarDispositivos();
        }).catch(function(err){
            out.innerHTML = '<p class="err" style="font-size:1.05rem;">❌ Falha: ' + err.name + '</p>'
                          + '<pre>' + err.name + '\n' + err.message + '\n\n' + (err.stack || '') + '</pre>'
                          + '<h3 style="margin-top:1rem;">Interpretação</h3>';

            var interp = '';
            if (err.name === 'NotAllowedError') {
                interp = '<p>🚫 <strong>Permissão foi negada</strong> — ou você clicou "Bloquear" agora, ou o site está na lista de bloqueados (nas configurações do browser), ou há uma policy do SO bloqueando o microfone pro Chrome.</p>'
                       + '<p><strong>Passos pra resolver:</strong></p>'
                       + '<ol><li>Clique no cadeado 🔒 ao lado da URL → Microfone → "Permitir"</li>'
                       + '<li>Se não resolveu: abra em nova aba <code>chrome://settings/content/microphone</code> e remova <code>ferreiraesa.com.br</code> da lista "Não autorizados"</li>'
                       + '<li>Se AINDA não resolveu: Windows → Configurações → Privacidade → Microfone → "Permitir que apps acessem seu microfone" (precisa estar LIGADO, e Chrome/Edge precisa estar na lista de apps permitidos)</li>'
                       + '<li>Tente em <strong>aba anônima</strong> (Ctrl+Shift+N) — se funcionar lá, o problema é uma extensão ou config salva no seu perfil</li></ol>';
            } else if (err.name === 'NotFoundError') {
                interp = '<p>🎤 <strong>Nenhum microfone encontrado</strong> pelo Windows. Conecte um microfone (headset, notebook, USB) e teste em outro app primeiro (ex: Windows → Gravador de Voz).</p>';
            } else if (err.name === 'NotReadableError') {
                interp = '<p>🎤 <strong>Microfone está em uso por outro programa</strong>. Feche: Zoom, Teams, Meet, WhatsApp Desktop, Discord, OBS. Depois tente de novo.</p>';
            } else if (err.name === 'SecurityError') {
                interp = '<p>🔒 Erro de segurança — pode ser Permissions-Policy do servidor ou iframe sem allow. Reporte o texto exato do erro.</p>';
            } else {
                interp = '<p>Erro inesperado. Copie a mensagem acima e mande pro suporte.</p>';
            }
            out.innerHTML += interp;
        });
    };
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
