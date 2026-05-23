<?php
/**
 * Ferreira & Sá Hub — WhatsApp Backup: Pendentes Manuais
 *
 * Lista todos os arquivos do WhatsApp que ficaram marcados como
 * `backup_status='pendente_manual'` (sem client_id ou sem case com pasta Drive)
 * agrupados por conversa, e permite à Amanda vincular cliente + re-disparar
 * backup pro Drive de forma controlada.
 *
 * Acesso: SOMENTE admin/gestao.
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_utils.php';

require_login();
if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url(''));
}

$pdo = db();
$pageTitle = '📞 WhatsApp — Backup Manual Pendente';

// Agrupa pendentes por conversa
$rows = $pdo->query(
    "SELECT co.id AS conv_id, co.canal, co.telefone, co.nome_contato, co.client_id,
            c.name AS client_name,
            COUNT(m.id) AS qtd,
            MIN(m.created_at) AS primeira,
            MAX(m.created_at) AS ultima,
            GROUP_CONCAT(DISTINCT m.tipo ORDER BY m.tipo SEPARATOR ',') AS tipos
     FROM zapi_mensagens m
     INNER JOIN zapi_conversas co ON co.id = m.conversa_id
     LEFT JOIN clients c ON c.id = co.client_id
     WHERE m.backup_status = 'pendente_manual'
     GROUP BY co.id, co.canal, co.telefone, co.nome_contato, co.client_id, c.name
     ORDER BY MAX(m.created_at) DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$totalArquivos = 0;
foreach ($rows as $r) $totalArquivos += (int)$r['qtd'];

require_once __DIR__ . '/../../templates/layout_start.php';
?>

<div style="max-width:1200px;">
<h1 style="margin-bottom:.3rem;">📞 WhatsApp — Backup Manual Pendente</h1>
<p style="color:#6b7280;margin-bottom:1.5rem;">Arquivos do WhatsApp (áudios, imagens, documentos) que <strong>não conseguiram ser salvos automaticamente no Drive</strong> porque a conversa não tinha cliente/case vinculado quando o cron rodou. Vincule o cliente abaixo e re-dispare o backup.</p>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:1.5rem;">
    <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid #dc2626;border-radius:8px;padding:.85rem 1rem;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;">Conversas órfãs</div>
        <div style="font-size:1.6rem;font-weight:700;color:#7f1d1d;"><?= count($rows) ?></div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid #f59e0b;border-radius:8px;padding:.85rem 1rem;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;">Arquivos pendentes</div>
        <div style="font-size:1.6rem;font-weight:700;color:#92400e;"><?= $totalArquivos ?></div>
    </div>
</div>

<?php if (empty($rows)): ?>
    <div style="background:#dcfce7;border:1px solid #86efac;color:#15803d;padding:1rem;border-radius:8px;text-align:center;">
        ✅ Nenhum arquivo pendente — todos os backups do WhatsApp estão em dia!
    </div>
<?php else: ?>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
<table style="width:100%;border-collapse:collapse;font-size:.85rem;">
    <thead style="background:#f9fafb;">
    <tr style="text-align:left;">
        <th style="padding:.6rem .8rem;">Conversa</th>
        <th style="padding:.6rem .8rem;">Cliente</th>
        <th style="padding:.6rem .8rem;text-align:center;">Arquivos</th>
        <th style="padding:.6rem .8rem;">Período</th>
        <th style="padding:.6rem .8rem;">Tipos</th>
        <th style="padding:.6rem .8rem;text-align:right;">Ações</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr style="border-top:1px solid #f3f4f6;" data-conv="<?= (int)$r['conv_id'] ?>">
        <td style="padding:.55rem .8rem;">
            <div style="font-weight:600;color:#052228;"><?= e($r['nome_contato'] ?: '(sem nome)') ?></div>
            <div style="font-size:.72rem;color:#6b7280;font-family:monospace;"><?= e($r['telefone']) ?> · canal <?= e($r['canal']) ?></div>
        </td>
        <td style="padding:.55rem .8rem;">
            <?php if (!empty($r['client_id'])): ?>
                <span style="background:#ecfdf5;color:#065f46;padding:.15rem .5rem;border-radius:4px;font-size:.75rem;font-weight:700;">✓ <?= e($r['client_name']) ?></span>
                <div style="font-size:.65rem;color:#9a3412;margin-top:.2rem;">⚠ Sem case com pasta Drive — crie um caso ou vincule a um existente abaixo.</div>
            <?php else: ?>
                <span style="background:#fee2e2;color:#991b1b;padding:.15rem .5rem;border-radius:4px;font-size:.72rem;font-weight:700;">não vinculado</span>
            <?php endif; ?>
        </td>
        <td style="padding:.55rem .8rem;text-align:center;font-weight:700;color:#92400e;"><?= (int)$r['qtd'] ?></td>
        <td style="padding:.55rem .8rem;font-size:.72rem;color:#6b7280;">
            <?= date('d/m/y', strtotime($r['primeira'])) ?> – <?= date('d/m/y', strtotime($r['ultima'])) ?>
        </td>
        <td style="padding:.55rem .8rem;font-size:.7rem;color:#6b7280;"><?= e($r['tipos']) ?></td>
        <td style="padding:.55rem .8rem;text-align:right;white-space:nowrap;">
            <button type="button" onclick="abrirVincular(<?= (int)$r['conv_id'] ?>, <?= e(json_encode($r['nome_contato'] ?: $r['telefone'])) ?>, <?= (int)($r['client_id'] ?? 0) ?>)" style="background:#6366f1;color:#fff;border:none;padding:.25rem .6rem;border-radius:5px;font-size:.7rem;cursor:pointer;font-weight:600;">🔗 Vincular</button>
            <button type="button" onclick="tentarBackup(<?= (int)$r['conv_id'] ?>, this)" title="Re-disparar backup pro Drive (cliente precisa estar vinculado e ter case com pasta Drive)" style="background:#0e7490;color:#fff;border:none;padding:.25rem .6rem;border-radius:5px;font-size:.7rem;cursor:pointer;font-weight:600;">🔄 Backup</button>
            <button type="button" onclick="descartarConv(<?= (int)$r['conv_id'] ?>, this)" title="Descartar — não tenta backup desses arquivos mais (lixo / spam / não-cliente)" style="background:#fff;border:1px solid #cbd5e1;color:#475569;padding:.25rem .6rem;border-radius:5px;font-size:.7rem;cursor:pointer;font-weight:600;">🗑 Descartar</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

</div>

<!-- Modal de vincular cliente -->
<div id="modalVincular" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;max-width:480px;width:100%;border-radius:12px;padding:1.4rem 1.6rem;box-shadow:0 10px 40px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;">
            <h3 style="margin:0;color:#1e1b4b;">🔗 Vincular cliente</h3>
            <button onclick="document.getElementById('modalVincular').style.display='none'" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;">×</button>
        </div>
        <p style="color:#6b7280;font-size:.85rem;">Vinculando: <strong id="vincNome"></strong></p>
        <input type="hidden" id="vincConvId">
        <div style="position:relative;">
            <input type="text" id="vincBuscaCli" placeholder="Buscar cliente por nome ou CPF..." autocomplete="off" style="width:100%;padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;">
            <div id="vincResults" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #d1d5db;border-radius:6px;max-height:240px;overflow-y:auto;display:none;z-index:10;margin-top:2px;"></div>
        </div>
        <div style="margin-top:.8rem;font-size:.75rem;color:#6b7280;">
            Após vincular, o sistema vai tentar fazer o backup automaticamente. <strong>O cliente precisa ter pelo menos 1 caso ativo com pasta Drive criada.</strong>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem;">
            <button onclick="document.getElementById('modalVincular').style.display='none'" style="background:#fff;border:1px solid #cbd5e1;color:#475569;padding:.4rem 1rem;border-radius:6px;cursor:pointer;">Cancelar</button>
        </div>
    </div>
</div>

<script>
function abrirVincular(convId, nome, currentClientId) {
    document.getElementById('vincConvId').value = convId;
    document.getElementById('vincNome').textContent = nome || '(sem nome)';
    document.getElementById('vincBuscaCli').value = '';
    document.getElementById('vincResults').style.display = 'none';
    document.getElementById('modalVincular').style.display = 'flex';
    setTimeout(function(){ document.getElementById('vincBuscaCli').focus(); }, 50);
}

(function(){
    var inp = document.getElementById('vincBuscaCli');
    var box = document.getElementById('vincResults');
    var t = null;
    inp.addEventListener('input', function(){
        var q = this.value.trim();
        if (t) clearTimeout(t);
        if (q.length < 2) { box.style.display = 'none'; return; }
        t = setTimeout(function(){
            fetch('<?= module_url('operacional', 'api.php') ?>?action=buscar_clients_nome&q=' + encodeURIComponent(q), {credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(arr){
                    if (!Array.isArray(arr) || arr.length === 0) { box.innerHTML = '<div style="padding:.5rem .8rem;color:#9ca3af;font-size:.8rem;">Nenhum cliente</div>'; box.style.display='block'; return; }
                    box.innerHTML = arr.map(function(c){
                        return '<div style="padding:.5rem .8rem;cursor:pointer;border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background=\'#f3f4f6\'" onmouseout="this.style.background=\'#fff\'" onclick="confirmarVincular(' + c.id + ',\'' + (c.name||'').replace(/'/g,"\\'") + '\')">'
                            + '<div style="font-weight:600;color:#052228;">' + (c.name||'') + '</div>'
                            + '<div style="font-size:.72rem;color:#6b7280;">' + (c.cpf||'') + '</div>'
                            + '</div>';
                    }).join('');
                    box.style.display = 'block';
                });
        }, 250);
    });
})();

function confirmarVincular(clientId, nome) {
    var convId = document.getElementById('vincConvId').value;
    if (!confirm('Vincular conversa a "' + nome + '"?\n\nApós vincular, o sistema vai tentar fazer o backup automático dos arquivos pendentes.')) return;
    var fd = new FormData();
    fd.append('action', 'vincular_cliente');
    fd.append('conv_id', convId);
    fd.append('client_id', String(clientId));
    fd.append('csrf_token', '<?= e(generate_csrf_token()) ?>');
    fetch('<?= module_url('admin', 'wa_pendentes_api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) { alert('Erro: ' + d.error); return; }
            var msg = '✓ Vinculado';
            if (d.backup_tentado) msg += '\n\nBackup tentado: ' + d.salvos + ' salvo(s), ' + d.falhas + ' falha(s).';
            else if (d.sem_pasta) msg += '\n\n⚠ Cliente vinculado mas não tem case com pasta Drive — crie a pasta e clique em "🔄 Backup".';
            alert(msg);
            location.reload();
        })
        .catch(function(e){ alert('Erro de rede: ' + e.message); });
}

function tentarBackup(convId, btn) {
    if (!confirm('Tentar fazer backup dos arquivos pendentes desta conversa?')) return;
    btn.disabled = true; btn.textContent = '⏳…';
    var fd = new FormData();
    fd.append('action', 'tentar_backup');
    fd.append('conv_id', convId);
    fd.append('csrf_token', '<?= e(generate_csrf_token()) ?>');
    fetch('<?= module_url('admin', 'wa_pendentes_api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false; btn.textContent = '🔄 Backup';
            if (d.error) { alert('Erro: ' + d.error); return; }
            alert('✓ ' + d.salvos + ' salvo(s), ' + d.falhas + ' falha(s).');
            if (d.salvos > 0) location.reload();
        });
}

function descartarConv(convId, btn) {
    if (!confirm('Descartar arquivos desta conversa?\n\nEles serão marcados como "descartado" e o sistema não vai tentar fazer backup deles. Use isso pra lixo / spam / não-cliente.')) return;
    btn.disabled = true; btn.textContent = '⏳…';
    var fd = new FormData();
    fd.append('action', 'descartar');
    fd.append('conv_id', convId);
    fd.append('csrf_token', '<?= e(generate_csrf_token()) ?>');
    fetch('<?= module_url('admin', 'wa_pendentes_api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) { alert('Erro: ' + d.error); btn.disabled = false; btn.textContent = '🗑 Descartar'; return; }
            location.reload();
        });
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
