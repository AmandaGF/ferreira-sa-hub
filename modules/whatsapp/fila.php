<?php
/**
 * Caixa de Envios WhatsApp — revisão de mensagens sugeridas pelo sistema.
 * Origens: andamento_visivel, processo_distribuido, cobranca_* (triggers automáticos).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$pageTitle = 'Caixa de Envios WhatsApp';

$statusSel = $_GET['status'] ?? 'pendente';
if (!in_array($statusSel, array('pendente','enviada','descartada','todas'), true)) $statusSel = 'pendente';
$clientFilter = (int)($_GET['client_id'] ?? 0);

$conds = array(); $params = array();
if ($statusSel !== 'todas') { $conds[] = 'f.status = ?'; $params[] = $statusSel; }
if ($clientFilter) { $conds[] = 'f.client_id = ?'; $params[] = $clientFilter; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$stmt = $pdo->prepare("
    SELECT f.*, cl.name AS client_name_real
    FROM zapi_fila_envio f
    LEFT JOIN clients cl ON cl.id = f.client_id
    {$where}
    ORDER BY f.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Nome do cliente filtrado (pra exibir)
$nomeClienteFiltro = '';
if ($clientFilter) {
    $stmtN = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
    $stmtN->execute(array($clientFilter));
    $nomeClienteFiltro = $stmtN->fetchColumn() ?: '';
}

$contagem = $pdo->query("SELECT status, COUNT(*) AS n FROM zapi_fila_envio GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$origemLabels = array(
    'andamento_visivel'    => array('📋 Andamento', '#0ea5e9'),
    'processo_distribuido' => array('⚖️ Distribuição', '#059669'),
    'cobranca_vencendo'    => array('💰 Cobrança', '#b45309'),
    'cobranca_vencida'     => array('🚨 Vencida', '#dc2626'),
    'outro'                => array('📨 Outro', '#6b7280'),
);

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.fila-head { background:linear-gradient(135deg,#052228,#0d3640); color:#fff; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem; }
.fila-head h2 { font-size:1.1rem; margin:0 0 .25rem; }
.fila-head .sub { font-size:.8rem; color:rgba(255,255,255,.7); }
.fila-tabs { display:flex; gap:.3rem; margin-bottom:1rem; flex-wrap:wrap; }
.fila-tab { padding:6px 14px; background:#fff; border:1.5px solid var(--border); border-radius:20px; font-size:.8rem; font-weight:600; cursor:pointer; text-decoration:none; color:var(--text); }
.fila-tab.active { background:var(--petrol-900); color:#fff; border-color:var(--petrol-900); }
.fila-item { background:#fff; border:1px solid var(--border); border-left:4px solid #ccc; border-radius:10px; padding:1rem 1.15rem; margin-bottom:.75rem; }
.fila-item.pendente { border-left-color:#f59e0b; }
.fila-item.enviada { border-left-color:#059669; opacity:.75; }
.fila-item.descartada { border-left-color:#6b7280; opacity:.5; }
.fila-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:.68rem; font-weight:700; color:#fff; }
.fila-msg-preview { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:.6rem .8rem; margin-top:.5rem; font-size:.82rem; white-space:pre-wrap; max-height:120px; overflow-y:auto; }
.fila-acoes { display:flex; gap:.4rem; margin-top:.6rem; flex-wrap:wrap; }
.fila-acoes button { padding:6px 14px; border:none; border-radius:6px; cursor:pointer; font-size:.78rem; font-weight:600; }
</style>

<div class="fila-head">
    <h2>📬 Caixa de Envios WhatsApp</h2>
    <div class="sub">Mensagens sugeridas pelo sistema aguardando sua revisão e aprovação</div>
</div>

<?php if ($clientFilter && $nomeClienteFiltro): ?>
<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:.6rem .9rem;margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
    <span style="font-size:.85rem;color:#1e3a8a;">🔎 Filtrando por cliente: <strong><?= e($nomeClienteFiltro) ?></strong></span>
    <a href="?status=<?= e($statusSel) ?>" style="margin-left:auto;background:#fff;border:1px solid #93c5fd;color:#1e3a8a;padding:3px 10px;border-radius:6px;font-size:.72rem;text-decoration:none;font-weight:600;">✕ Remover filtro</a>
</div>
<?php endif; ?>

<?php
// Helper pra preservar query params nos links
function _fila_link($status, $clientFilter) {
    $q = array('status' => $status);
    if ($clientFilter) $q['client_id'] = $clientFilter;
    return '?' . http_build_query($q);
}
?>
<div class="fila-tabs">
    <a href="<?= _fila_link('pendente', $clientFilter) ?>" class="fila-tab <?= $statusSel === 'pendente' ? 'active' : '' ?>">⏳ Pendentes (<?= (int)($contagem['pendente'] ?? 0) ?>)</a>
    <a href="<?= _fila_link('enviada', $clientFilter) ?>" class="fila-tab <?= $statusSel === 'enviada' ? 'active' : '' ?>">✅ Enviadas (<?= (int)($contagem['enviada'] ?? 0) ?>)</a>
    <a href="<?= _fila_link('descartada', $clientFilter) ?>" class="fila-tab <?= $statusSel === 'descartada' ? 'active' : '' ?>">🗑 Descartadas (<?= (int)($contagem['descartada'] ?? 0) ?>)</a>
    <a href="<?= _fila_link('todas', $clientFilter) ?>" class="fila-tab <?= $statusSel === 'todas' ? 'active' : '' ?>">Todas</a>
</div>

<?php if (empty($rows)): ?>
    <div style="text-align:center;padding:3rem;color:var(--text-muted);background:#fff;border-radius:10px;">
        Nenhuma mensagem com status "<?= e($statusSel) ?>" 🎉
    </div>
<?php else: ?>
    <?php foreach ($rows as $r):
        $origem = $origemLabels[$r['origem']] ?? $origemLabels['outro'];
        $nome = $r['client_name_real'] ?: $r['nome_contato'] ?: '(sem nome)';
    ?>
    <div class="fila-item <?= e($r['status']) ?>" id="fila_<?= (int)$r['id'] ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;flex-wrap:wrap;">
            <div>
                <span class="fila-badge" style="background:<?= $origem[1] ?>;"><?= $origem[0] ?></span>
                <strong style="margin-left:.5rem;color:var(--petrol-900);"><?= e($nome) ?></strong>
                <span style="font-size:.75rem;color:var(--text-muted);margin-left:.3rem;"><?= e($r['telefone']) ?></span>
            </div>
            <div style="font-size:.72rem;color:var(--text-muted);">
                Sugerido: <?= date('d/m H:i', strtotime($r['created_at'])) ?>
                <?php if ($r['status'] === 'enviada'): ?> · ✅ enviada <?= date('d/m H:i', strtotime($r['enviada_em'])) ?><?php endif; ?>
                <?php if ($r['status'] === 'descartada'): ?> · 🗑 descartada <?= date('d/m H:i', strtotime($r['descartada_em'])) ?><?php endif; ?>
            </div>
        </div>
        <div class="fila-msg-preview" id="fila_<?= (int)$r['id'] ?>_preview"><?= e($r['mensagem']) ?></div>
        <textarea id="fila_<?= (int)$r['id'] ?>_editor" style="display:none;width:100%;min-height:120px;padding:.6rem .8rem;border:1.5px solid #B87333;border-radius:8px;font-size:.82rem;font-family:inherit;resize:vertical;margin-top:.3rem;"><?= e($r['mensagem']) ?></textarea>
        <?php if ($r['status'] === 'pendente'): ?>
        <div class="fila-acoes" id="fila_<?= (int)$r['id'] ?>_acoes">
            <button type="button" style="background:#25d366;color:#fff;" onclick="filaEnviar(<?= (int)$r['id'] ?>, '<?= preg_replace('/\D/', '', $r['telefone']) ?>', <?= e(json_encode($nome, JSON_UNESCAPED_UNICODE)) ?>, '<?= e($r['canal_sugerido']) ?>', <?= (int)$r['client_id'] ?>)">✓ Revisar e enviar</button>
            <button style="background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;" onclick="filaEditarInline(<?= (int)$r['id'] ?>)">✏ Editar texto</button>
            <button style="background:#f3f4f6;border:1px solid #d1d5db;color:#374151;" onclick="filaDescartar(<?= (int)$r['id'] ?>)">🗑 Descartar</button>
        </div>
        <div id="fila_<?= (int)$r['id'] ?>_edicao" style="display:none;margin-top:.5rem;gap:.4rem;">
            <button style="background:#B87333;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:.78rem;font-weight:600;" onclick="filaSalvarEdicao(<?= (int)$r['id'] ?>)">💾 Salvar alterações</button>
            <button style="background:#f3f4f6;border:1px solid #d1d5db;color:#374151;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:.78rem;font-weight:600;margin-left:.3rem;" onclick="filaCancelarEdicao(<?= (int)$r['id'] ?>)">Cancelar</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
// Helpers de UI: ajusta contador na tab + remove card da lista atual
function _filaAjustarContador(statusKey, delta) {
    document.querySelectorAll('.fila-tab').forEach(function(a){
        var href = a.getAttribute('href') || '';
        var m = href.match(/status=([a-z]+)/);
        if (!m || m[1] !== statusKey) return;
        a.innerHTML = a.innerHTML.replace(/\((\d+)\)/, function(_, n){
            return '(' + Math.max(0, parseInt(n, 10) + delta) + ')';
        });
    });
}

function _filaRemoverCard(filaId, statusOrigem, statusDestino) {
    var el = document.getElementById('fila_' + filaId);
    if (!el) return;
    el.style.transition = 'opacity .25s ease, max-height .35s ease, margin .35s ease, padding .35s ease';
    el.style.maxHeight = el.offsetHeight + 'px';
    requestAnimationFrame(function(){
        el.style.opacity = '0';
        el.style.maxHeight = '0';
        el.style.marginBottom = '0';
        el.style.paddingTop = '0';
        el.style.paddingBottom = '0';
        el.style.overflow = 'hidden';
    });
    setTimeout(function(){
        el.remove();
        if (statusOrigem) _filaAjustarContador(statusOrigem, -1);
        if (statusDestino) _filaAjustarContador(statusDestino, +1);
        // Empty state se a lista ficou vazia
        if (!document.querySelector('.fila-item')) {
            var container = document.querySelector('.fila-tabs') ? document.querySelector('.fila-tabs').parentNode : document.body;
            var empty = document.createElement('div');
            empty.style.cssText = 'text-align:center;padding:3rem;color:var(--text-muted);background:#fff;border-radius:10px;';
            empty.textContent = 'Nenhuma mensagem com este status 🎉';
            container.appendChild(empty);
        }
    }, 380);
}

function filaEnviar(filaId, telefone, nome, canal, clientId) {
    // Pega a mensagem ATUAL (possivelmente editada) do textarea ou preview
    var editor = document.getElementById('fila_' + filaId + '_editor');
    var preview = document.getElementById('fila_' + filaId + '_preview');
    var mensagem = (editor && editor.value) ? editor.value : (preview ? preview.textContent : '');

    waSenderOpen({
        telefone: telefone,
        nome: nome,
        mensagem: mensagem,
        canal: canal,
        clientId: clientId,
        onSuccess: function(d) {
            // Marca a fila como enviada
            var fd = new FormData();
            fd.append('action', 'fila_marcar_enviada');
            fd.append('fila_id', filaId);
            fd.append('csrf_token', window.FSA_CSRF);
            fetch(window.FSA_WHATSAPP_API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(){
                    _filaRemoverCard(filaId, 'pendente', 'enviada');
                });
        }
    });
}

function filaEditarInline(filaId) {
    document.getElementById('fila_' + filaId + '_preview').style.display = 'none';
    document.getElementById('fila_' + filaId + '_editor').style.display = 'block';
    document.getElementById('fila_' + filaId + '_acoes').style.display = 'none';
    document.getElementById('fila_' + filaId + '_edicao').style.display = 'block';
    document.getElementById('fila_' + filaId + '_editor').focus();
}
function filaCancelarEdicao(filaId) {
    var preview = document.getElementById('fila_' + filaId + '_preview');
    var editor = document.getElementById('fila_' + filaId + '_editor');
    // Restaura o texto original do preview
    editor.value = preview.textContent;
    preview.style.display = 'block';
    editor.style.display = 'none';
    document.getElementById('fila_' + filaId + '_acoes').style.display = 'flex';
    document.getElementById('fila_' + filaId + '_edicao').style.display = 'none';
}
function filaSalvarEdicao(filaId) {
    var editor = document.getElementById('fila_' + filaId + '_editor');
    var novoTexto = (editor.value || '').trim();
    if (!novoTexto) { alert('Mensagem vazia.'); return; }
    var fd = new FormData();
    fd.append('action', 'fila_editar');
    fd.append('fila_id', filaId);
    fd.append('mensagem', novoTexto);
    fd.append('csrf_token', window.FSA_CSRF);
    fetch(window.FSA_WHATSAPP_API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                document.getElementById('fila_' + filaId + '_preview').textContent = novoTexto;
                filaCancelarEdicao(filaId);
            } else alert('Falha ao salvar: ' + (d.error || '?'));
        });
}

function filaDescartar(filaId) {
    if (!confirm('Descartar esta sugestão?')) return;
    var fd = new FormData();
    fd.append('action', 'fila_descartar');
    fd.append('fila_id', filaId);
    fd.append('csrf_token', window.FSA_CSRF);
    fetch(window.FSA_WHATSAPP_API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                _filaRemoverCard(filaId, 'pendente', 'descartada');
            } else alert('Falha: ' + (d.error || '?'));
        });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
