<?php
// Teste mínimo do drawer
$testUrl = url('modules/shared/card_api.php');
?>
<div id="testDrawerOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:998;" onclick="document.getElementById('testDrawerOverlay').style.display='none';document.getElementById('testDrawerPanel').style.right='-400px';"></div>
<div id="testDrawerPanel" style="position:fixed;top:0;right:-400px;width:400px;height:100vh;background:#fff;z-index:999;box-shadow:-5px 0 20px rgba(0,0,0,.1);transition:right .3s;padding:1.5rem;overflow-y:auto;">
    <button onclick="document.getElementById('testDrawerOverlay').style.display='none';document.getElementById('testDrawerPanel').style.right='-400px';" style="float:right;background:none;border:none;font-size:1.2rem;cursor:pointer;">✕</button>
    <h3 id="testDrawerTitle" style="font-size:1rem;color:#052228;">Carregando...</h3>
    <div id="testDrawerBody" style="margin-top:1rem;font-size:.85rem;"></div>
</div>
<script>
console.log('[TestDrawer] Script carregado OK');

function testAbrirDrawer(params) {
    console.log('[TestDrawer] Abrindo:', params);
    document.getElementById('testDrawerOverlay').style.display = 'block';
    document.getElementById('testDrawerPanel').style.right = '0';
    document.getElementById('testDrawerTitle').textContent = 'Carregando...';
    document.getElementById('testDrawerBody').innerHTML = '';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '<?= $testUrl ?>?' + params);
    xhr.onload = function() {
        console.log('[TestDrawer] Resposta:', xhr.status);
        try {
            var d = JSON.parse(xhr.responseText);
            var c = d.client || {};
            document.getElementById('testDrawerTitle').textContent = c.name || 'Sem nome';
            var h = '<p><strong>CPF:</strong> ' + (c.cpf || '—') + '</p>';
            h += '<p><strong>Tel:</strong> ' + (c.phone || '—') + '</p>';
            h += '<p><strong>Email:</strong> ' + (c.email || '—') + '</p>';
            if (d.lead) h += '<p><strong>Pipeline:</strong> ' + (d.lead.stage || '—') + '</p>';
            if (d.caso) h += '<p><strong>Operacional:</strong> ' + (d.caso.status || '—') + '</p>';
            h += '<p><strong>Comentários:</strong> ' + (d.comments ? d.comments.length : 0) + '</p>';
            document.getElementById('testDrawerBody').innerHTML = h;
        } catch(e) {
            document.getElementById('testDrawerBody').textContent = 'Erro: ' + e.message;
            console.error('[TestDrawer]', e);
        }
    };
    xhr.onerror = function() { document.getElementById('testDrawerBody').textContent = 'Erro de rede'; };
    xhr.send();
}

// Interceptar cliques
document.addEventListener('click', function(e) {
    var opCard = e.target.closest('.op-card[data-case-id]');
    if (opCard && !e.target.closest('select,form,.op-card-move,a')) {
        e.stopImmediatePropagation();
        e.preventDefault();
        testAbrirDrawer('case_id=' + opCard.getAttribute('data-case-id'));
        return;
    }
    var leadCard = e.target.closest('.lead-card[data-lead-id]');
    if (leadCard && !e.target.closest('.lead-actions,select,form,a')) {
        e.stopImmediatePropagation();
        e.preventDefault();
        testAbrirDrawer('lead_id=' + leadCard.getAttribute('data-lead-id'));
        return;
    }
}, true);

console.log('[TestDrawer] Event listener registrado');
</script>
