<?php
/**
 * Presença — Bandeja de Aprovação.
 * Amanda opera aqui: vê os sugeridos, aprova em lote/individual ou dispensa.
 * Também pode criar sugestão MANUAL (cliente + fase + data alvo).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');
require_once APP_ROOT . '/core/functions_presenca.php';

$pdo = db();
$pageTitle = 'Presença — Bandeja de Aprovação';

$sugeridos = $pdo->query("
    SELECT e.*, c.name AS cli_nome, c.phone AS cli_phone,
           p.nome AS perfil_nome, p.cor_hex AS perfil_cor,
           f.nome AS fase_nome,
           b.nome AS brinde_nome, b.eh_kit,
           fr.texto AS frase_texto,
           cs.title AS caso_titulo, cs.case_number
    FROM presenca_envio e
    LEFT JOIN clients c ON c.id = e.cliente_id
    LEFT JOIN presenca_perfil p ON p.id = e.perfil_id
    LEFT JOIN presenca_fase f ON f.id = e.fase_id
    LEFT JOIN presenca_brinde b ON b.id = e.brinde_id
    LEFT JOIN presenca_frase fr ON fr.id = e.frase_id
    LEFT JOIN cases cs ON cs.id = e.processo_id
    WHERE e.status = 'sugerido'
    ORDER BY e.bloqueado ASC, e.data_pedido_limite ASC, e.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$fases = $pdo->query("SELECT id, slug, nome FROM presenca_fase WHERE ativo=1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pa-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pa-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pa-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }

.pa-hint { background:#f5ede3; border-left:4px solid #B87333; padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:.85rem; color:#78350f; line-height:1.5; }

.pa-manual { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:16px 20px; margin-bottom:20px; }
.pa-manual summary { cursor:pointer; font-weight:800; color:#0E2E36; font-size:.9rem; }
.pa-manual .body { margin-top:12px; }
.pa-manual .row { display:grid; grid-template-columns:2fr 1fr 130px 130px; gap:10px; align-items:end; }
.pa-manual label { display:block; font-size:.7rem; color:#6b7280; font-weight:700; text-transform:uppercase; letter-spacing:.03em; margin-bottom:4px; }
.pa-manual input, .pa-manual select { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:.88rem; font-family:inherit; }
.pa-manual button { background:#0E2E36; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; }
.pa-manual .autocom-box { display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:0 0 8px 8px; max-height:200px; overflow-y:auto; z-index:20; box-shadow:0 4px 12px rgba(0,0,0,.1); }
.pa-manual .autocom-box div { padding:8px 12px; cursor:pointer; font-size:.85rem; border-bottom:1px solid #f3f4f6; }
.pa-manual .autocom-box div:hover { background:#f5ede3; }
@media (max-width:800px) { .pa-manual .row { grid-template-columns: 1fr; } }

.pa-barra { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px; }
.pa-barra .info { font-weight:700; color:#0E2E36; }
.pa-btn-lote { background:#15803d; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.pa-btn-lote:hover { background:#166534; }
.pa-btn-lote:disabled { background:#9ca3af; cursor:not-allowed; }

.pa-empty { background:#fff; border:1px dashed #d1d5db; border-radius:12px; padding:40px; text-align:center; color:#6b7280; }
.pa-empty .num { font-size:2rem; margin-bottom:8px; }

.pa-lista { display:flex; flex-direction:column; gap:10px; }
.pa-card { background:#fff; border:1px solid #e5e7eb; border-left:5px solid #ccc; border-radius:12px; padding:16px 20px; display:grid; grid-template-columns:auto 1fr auto; gap:16px; align-items:center; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.pa-card.bloqueado { border-left-color:#dc2626; background:linear-gradient(90deg,#fef2f2,#fff); }
.pa-card.urgente { border-left-color:#f59e0b; }
.pa-card .chk { transform:scale(1.4); }
.pa-card .head-linha1 { display:flex; align-items:center; gap:10px; margin-bottom:4px; flex-wrap:wrap; }
.pa-card .cli-nome { font-weight:800; color:#0E2E36; font-size:1rem; }
.pa-card .perfil-tag { color:#fff; padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.03em; }
.pa-card .fase-tag { background:#f5ede3; color:#78350f; padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:700; }
.pa-card .caso { font-size:.72rem; color:#6b7280; }
.pa-card .conteudo { display:flex; gap:14px; margin-top:8px; font-size:.82rem; color:#374151; flex-wrap:wrap; }
.pa-card .conteudo b { color:#0E2E36; }
.pa-card blockquote { margin:6px 0 0; font-family:'Cormorant Garamond',Georgia,serif; font-style:italic; font-size:1.05rem; color:#0E2E36; line-height:1.35; padding-left:12px; border-left:2px solid #d7ab90; }
.pa-card .prazos { display:flex; gap:14px; margin-top:6px; font-size:.72rem; color:#6b7280; }
.pa-card .prazos .urg { color:#dc2626; font-weight:700; }
.pa-card .bloq-msg { background:#fef2f2; border:1px solid #fecaca; padding:6px 10px; border-radius:6px; margin-top:6px; font-size:.78rem; color:#7f1d1d; }
.pa-card .acoes { display:flex; flex-direction:column; gap:6px; }
.pa-btn { background:#15803d; color:#fff; border:none; border-radius:8px; padding:8px 14px; font-size:.78rem; font-weight:700; cursor:pointer; text-align:center; text-decoration:none; }
.pa-btn:hover { background:#166534; }
.pa-btn.warn { background:#fff; color:#dc2626; border:1px solid #fecaca; }
.pa-btn.warn:hover { background:#fef2f2; }
.pa-btn.info { background:#0369a1; color:#fff; }
.pa-btn.info:hover { background:#075985; }
</style>

<div class="pa-hero">
    <div>
        <h1>✅ Bandeja de Aprovação</h1>
        <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">Você aprova, o sistema opera. Um toque por linha ou "Aprovar todos".</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pa-back">← Voltar</a>
</div>

<div class="pa-hint">
    💡 Cada linha é um cliente que chegou num marco. Os presentes já foram escolhidos pela matriz. Você só confirma o envio. Bloqueados (vermelho) precisam da confirmação de endereço antes.
</div>

<details class="pa-manual">
    <summary>➕ Criar sugestão manual (cliente + fase + data)</summary>
    <div class="body">
        <div class="row">
            <div style="position:relative;">
                <label>Cliente</label>
                <input type="hidden" id="cliId" value="">
                <input type="text" id="cliBusca" placeholder="Digite o nome..." autocomplete="off" oninput="paBuscarCli(this.value)">
                <div class="autocom-box" id="cliBox"></div>
            </div>
            <div>
                <label>Fase</label>
                <select id="faseSlug">
                    <?php foreach ($fases as $f): ?>
                    <option value="<?= e($f['slug']) ?>"><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Data alvo (chegada)</label>
                <input type="date" id="dataAlvo" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
            </div>
            <div>
                <button type="button" onclick="paSugerirManual()">💡 Sugerir</button>
            </div>
        </div>
        <div style="font-size:.72rem;color:#6b7280;margin-top:8px;">Perfil derivado automaticamente do cliente. Sugestão respeita restrições e regras da matriz.</div>
    </div>
</details>

<div class="pa-barra">
    <div class="info"><?= count($sugeridos) ?> sugestão(ões) aguardando</div>
    <?php if (!empty($sugeridos)): ?>
    <button class="pa-btn-lote" onclick="paAprovarTodos()">✅ Aprovar TODOS (que não estão bloqueados)</button>
    <?php endif; ?>
</div>

<?php if (empty($sugeridos)): ?>
<div class="pa-empty">
    <div class="num">✓</div>
    <div style="font-weight:700;color:#0E2E36;">Nada pra aprovar agora</div>
    <div style="margin-top:6px;font-size:.85rem;">Quando um cliente encostar num marco, aparece aqui. Ou você mesma pode criar uma sugestão manual acima.</div>
</div>
<?php else: ?>
<div class="pa-lista">
    <?php foreach ($sugeridos as $s):
        $dias = $s['data_pedido_limite'] ? (int)floor((strtotime($s['data_pedido_limite']) - time()) / 86400) : null;
        $urgente = ($dias !== null && $dias <= 3);
    ?>
    <div class="pa-card <?= !empty($s['bloqueado'])?'bloqueado':($urgente?'urgente':'') ?>" data-id="<?= (int)$s['id'] ?>">
        <input type="checkbox" class="chk pa-check" value="<?= (int)$s['id'] ?>" <?= !empty($s['bloqueado'])?'disabled':'' ?> title="<?= !empty($s['bloqueado'])?'Bloqueado':'Marcar pra aprovação em lote' ?>">
        <div>
            <div class="head-linha1">
                <span class="cli-nome"><?= e($s['cli_nome'] ?: '(cliente removido)') ?></span>
                <span class="perfil-tag" style="background: <?= e($s['perfil_cor']) ?>;"><?= e($s['perfil_nome']) ?></span>
                <span class="fase-tag"><?= e($s['fase_nome']) ?></span>
            </div>
            <?php if (!empty($s['caso_titulo'])): ?>
            <div class="caso">📁 <?= e(mb_substr($s['caso_titulo'], 0, 60)) ?><?php if (!empty($s['case_number'])): ?> · <?= e($s['case_number']) ?><?php endif; ?></div>
            <?php endif; ?>
            <div class="conteudo">
                <div><b>🎁</b> <?= e($s['brinde_nome'] ?: '(sem brinde definido)') ?><?php if (!empty($s['eh_kit'])): ?> <span style="background:#f3e8ff;color:#7c3aed;padding:0 6px;border-radius:4px;font-size:.65rem;font-weight:700;">KIT</span><?php endif; ?></div>
                <div><b>💰</b> R$ <?= number_format((float)$s['custo_previsto'], 2, ',', '.') ?></div>
            </div>
            <?php if (!empty($s['frase_texto'])): ?>
            <blockquote>“ <?= e($s['frase_texto']) ?> ”</blockquote>
            <?php endif; ?>
            <div class="prazos">
                <span>🎯 Data alvo: <b><?= $s['data_alvo'] ? date('d/m/Y', strtotime($s['data_alvo'])) : '—' ?></b></span>
                <?php if ($s['data_pedido_limite']): ?>
                <span class="<?= $urgente?'urg':'' ?>">📅 Pedir até: <b><?= date('d/m/Y', strtotime($s['data_pedido_limite'])) ?></b><?= $dias!==null?' ('.$dias.'d)':'' ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($s['bloqueado'])): ?>
            <div class="bloq-msg">🔒 <strong>Bloqueado por sensibilidade:</strong> <?= e($s['bloqueio_motivo'] ?: 'restrição ativa') ?></div>
            <?php endif; ?>
        </div>
        <div class="acoes">
            <?php if (!empty($s['bloqueado'])): ?>
            <button class="pa-btn info" onclick="paDesbloquear(<?= (int)$s['id'] ?>)">🔓 Endereço confirmado</button>
            <?php else: ?>
            <button class="pa-btn" onclick="paAprovar(<?= (int)$s['id'] ?>)">✅ Aprovar</button>
            <?php endif; ?>
            <button class="pa-btn warn" onclick="paCancelar(<?= (int)$s['id'] ?>)">✕ Dispensar</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
var paCsrf = '<?= $csrf ?>';
var paTimer = null;

function paBuscarCli(q) {
    clearTimeout(paTimer);
    var box = document.getElementById('cliBox');
    if (q.length < 2) { box.style.display = 'none'; return; }
    paTimer = setTimeout(function() {
        fetch('<?= module_url('presenca','api.php') ?>?acao=buscar_cliente&q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(arr) {
                var html = '';
                arr.forEach(function(c) {
                    html += '<div onclick="paSelCli(' + c.id + ',&quot;' + (c.name||'').replace(/"/g,'') + '&quot;)">' + (c.name||'') + '</div>';
                });
                box.innerHTML = html || '<div style="color:#999;cursor:default;">Nenhum</div>';
                box.style.display = 'block';
            });
    }, 250);
}
function paSelCli(id, nome) {
    document.getElementById('cliId').value = id;
    document.getElementById('cliBusca').value = nome;
    document.getElementById('cliBox').style.display = 'none';
}
function paPost(dados) {
    dados.csrf_token = paCsrf;
    var fd = new FormData();
    Object.keys(dados).forEach(function(k) { fd.append(k, dados[k]); });
    return fetch('<?= module_url('presenca','api.php') ?>', {
        method:'POST', body: fd, credentials:'same-origin',
        headers: { 'X-Requested-With':'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(j) {
        if (j.csrf) paCsrf = j.csrf;
        return j;
    });
}

function paSugerirManual() {
    var cli = document.getElementById('cliId').value;
    var slug = document.getElementById('faseSlug').value;
    var data = document.getElementById('dataAlvo').value;
    if (!cli) { alert('Selecione o cliente.'); return; }
    paPost({acao:'sugerir_manual', cliente_id:cli, fase_slug:slug, data_alvo:data})
        .then(function(j) {
            if (j.ok) location.reload();
            else alert('Não foi possível sugerir: ' + (j.erro || '?'));
        });
}

function paAprovar(id) {
    paPost({acao:'mudar_status', envio_id:id, novo_status:'aprovado'})
        .then(function(j) {
            if (j.ok) { var c = document.querySelector('[data-id="'+id+'"]'); if (c) c.style.opacity='.3'; setTimeout(function(){ location.reload(); }, 300); }
            else alert('Erro: ' + (j.erro || '?'));
        });
}
function paCancelar(id) {
    if (!confirm('Dispensar esta sugestão? Ela some da bandeja.')) return;
    paPost({acao:'cancelar', envio_id:id})
        .then(function(j) { if (j.ok) location.reload(); else alert(j.erro || 'Erro'); });
}
function paDesbloquear(id) {
    if (!confirm('Confirmar endereço da cliente? Depois disso, o envio pode seguir pro fluxo normal.')) return;
    paPost({acao:'desbloquear', envio_id:id})
        .then(function(j) { if (j.ok) location.reload(); });
}
function paAprovarTodos() {
    var ids = Array.from(document.querySelectorAll('.pa-check:checked')).map(function(c){ return c.value; });
    if (ids.length === 0) {
        // Se nenhum marcado, aprova TODOS não-bloqueados
        ids = Array.from(document.querySelectorAll('.pa-check:not(:disabled)')).map(function(c){ return c.value; });
    }
    if (ids.length === 0) { alert('Nenhuma sugestão pra aprovar.'); return; }
    if (!confirm('Aprovar ' + ids.length + ' sugestão(ões) de uma vez?')) return;
    var fd = new FormData();
    fd.append('acao','aprovar_lote'); fd.append('csrf_token', paCsrf);
    ids.forEach(function(id) { fd.append('ids[]', id); });
    fetch('<?= module_url('presenca','api.php') ?>', { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(function(r){ return r.json(); })
        .then(function(j) {
            if (j.ok) {
                if (j.erros && j.erros.length) alert('Aprovados: ' + j.aprovados + '. Erros:\n' + j.erros.join('\n'));
                location.reload();
            } else alert(j.erro || 'Erro');
        });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
