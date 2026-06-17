<?php
require_once __DIR__ . '/core/middleware.php';
require_login();
$pageTitle = 'Teste do banner';

// Limpa qualquer cache de sessão do banner
if (isset($_SESSION)) {
    foreach (array_keys($_SESSION) as $_k) {
        if (strpos($_k, '_prazoBanner_') === 0) unset($_SESSION[$_k]);
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="padding:2rem;background:#fef3c7;border:2px solid #f59e0b;border-radius:12px;margin:2rem;">
    <h2 style="color:#7c2d12;margin:0 0 1rem;">🔍 Teste do Banner de Prazos</h2>
    <p>Se você está vendo essa caixa amarela mas <strong>NÃO está vendo o banner vermelho acima</strong> dizendo "🚨 N prazos VENCIDOS + M pra HOJE", então:</p>
    <ul>
        <li>Olha no <strong>código-fonte</strong> da página (Ctrl+U): busca por <code>prazoBanner</code> e <code>venc=</code></li>
        <li>Verifica se em <strong>F12 → Console</strong> tem algum erro</li>
        <li>Verifica em <strong>F12 → Application → Local Storage</strong> se tem <code>prazoBannerDismissUntil</code> com timestamp futuro (e apaga)</li>
    </ul>
    <p><strong>O que aparece pra você AGORA:</strong></p>
    <div id="diag" style="background:#fff;padding:1rem;border-radius:8px;font-family:monospace;font-size:.85rem;"></div>
    <script>
    var diag = document.getElementById('diag');
    var banner = document.getElementById('prazoBanner');
    var dismiss = null;
    try { dismiss = parseInt(localStorage.getItem('prazoBannerDismissUntil') || '0', 10); } catch(e){}
    var msgs = [];
    msgs.push('• Banner no DOM: ' + (banner ? '✓ SIM' : '❌ NÃO'));
    if (banner) {
        var cs = window.getComputedStyle(banner);
        msgs.push('• display: ' + cs.display);
        msgs.push('• visibility: ' + cs.visibility);
        msgs.push('• opacity: ' + cs.opacity);
        msgs.push('• z-index: ' + cs.zIndex);
        msgs.push('• position: ' + cs.position);
        msgs.push('• altura computada: ' + banner.offsetHeight + 'px');
        msgs.push('• HTML inline style display: ' + (banner.style.display || '(vazio)'));
    }
    msgs.push('• localStorage prazoBannerDismissUntil: ' + (dismiss || '(não setado)'));
    if (dismiss && dismiss > Date.now()) {
        var minRest = Math.round((dismiss - Date.now()) / 60000);
        msgs.push('  ⚠️ DISMISSED por mais ' + minRest + ' min — clique no botão pra limpar:');
        msgs.push('<button onclick="localStorage.removeItem(\'prazoBannerDismissUntil\'); location.reload();" style="background:#dc2626;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-weight:700;margin-top:6px;">🗑️ Limpar dismiss e recarregar</button>');
    }
    diag.innerHTML = msgs.join('<br>');
    </script>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
