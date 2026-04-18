<?php
/**
 * Ferreira & Sá Hub — WhatsApp CRM Inbox
 * Canal: ?canal=21 (Comercial) | ?canal=24 (CX/Operacional)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('whatsapp');
require_once APP_ROOT . '/core/functions_zapi.php';

$pdo = db();
$user = current_user();

$canal = $_GET['canal'] ?? '21';
if (!in_array($canal, array('21', '24'), true)) $canal = '21';

$isComercial = ($canal === '21');
$canalLabel  = $isComercial ? 'Comercial' : 'CX/Operacional';
$pageTitle   = 'WhatsApp ' . $canalLabel . ' (DDD ' . $canal . ')';

$inst = zapi_get_instancia($canal);
$cfg  = zapi_get_config();
$configurado = $inst && $inst['instancia_id'] !== '' && $inst['token'] !== '';

// Cores por canal
$accentColor = $isComercial ? '#b08d6e' : '#0f3460';        // dourado / azul petróleo
$accentLight = $isComercial ? '#fdf5ed' : '#eef2f8';

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.wa-wrap { display:grid;grid-template-columns:360px 1fr;gap:.5rem;height:calc(100vh - 140px);min-height:520px; }
.wa-pane { background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column; }
.wa-head { padding:.6rem .9rem;background:<?= $accentColor ?>;color:#fff;font-weight:600;font-size:.9rem;display:flex;align-items:center;gap:.5rem; }
.wa-head-sub { font-size:.7rem;opacity:.8;font-weight:400;margin-left:auto; }
.wa-badge-canal { display:inline-block;padding:1px 8px;border-radius:10px;font-size:.65rem;font-weight:700;background:#fff;color:<?= $accentColor ?>;letter-spacing:.3px; }
.wa-status-dot { display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:.2rem; }
.wa-status-dot.on  { background:#22c55e;box-shadow:0 0 4px #22c55e; }
.wa-status-dot.off { background:#ef4444; }
.wa-toolbar { padding:.5rem .6rem;border-bottom:1px solid var(--border);background:<?= $accentLight ?>;display:flex;flex-direction:column;gap:.4rem; }
.wa-filters { display:flex;gap:.3rem;flex-wrap:wrap; }
.wa-filter { padding:3px 8px;border-radius:10px;font-size:.68rem;background:#fff;border:1px solid var(--border);cursor:pointer;color:var(--text-muted); }
.wa-filter.active { background:<?= $accentColor ?>;color:#fff;border-color:<?= $accentColor ?>; }
.wa-search { width:100%;padding:5px 10px;border:1px solid var(--border);border-radius:8px;font-size:.78rem; }
.wa-list { flex:1;overflow-y:auto; }
.wa-conv { padding:.6rem .8rem;border-bottom:1px solid var(--border);cursor:pointer;display:flex;gap:.5rem;align-items:flex-start; }
.wa-conv:hover { background:<?= $accentLight ?>; }
.wa-conv.active { background:<?= $accentLight ?>;border-left:3px solid <?= $accentColor ?>; }
.wa-avatar { width:36px;height:36px;border-radius:50%;background:<?= $accentColor ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0; }
.wa-conv-info { flex:1;min-width:0; }
.wa-conv-name { font-weight:600;font-size:.82rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.wa-conv-preview { font-size:.72rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px; }
.wa-conv-meta { text-align:right;font-size:.65rem;color:var(--text-muted);flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:3px; }
.wa-unread { background:#ef4444;color:#fff;border-radius:10px;padding:1px 7px;font-weight:700;font-size:.65rem; }
.wa-bot-badge { background:#7c3aed;color:#fff;padding:1px 5px;border-radius:4px;font-size:.6rem;margin-left:4px; }
.wa-empty { padding:2rem 1rem;text-align:center;color:var(--text-muted);font-size:.85rem; }
.wa-chat-empty { display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--text-muted);text-align:center;padding:2rem; }
.wa-chat-empty-ico { font-size:3rem;margin-bottom:.5rem;opacity:.4; }
.wa-not-config { padding:1.5rem;background:#fffbeb;border:1px solid #f59e0b;border-radius:10px;color:#78350f; }
@media (max-width:900px){ .wa-wrap{grid-template-columns:1fr;height:auto;} }
</style>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;flex-wrap:wrap;">
    <h1 style="margin:0;">💬 WhatsApp <?= e($canalLabel) ?></h1>
    <span class="wa-badge-canal" style="background:<?= $accentColor ?>;color:#fff;">DDD <?= e($canal) ?></span>
    <?php if ($configurado): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;color:var(--text-muted);">
            <span class="wa-status-dot <?= !empty($inst['conectado']) ? 'on' : 'off' ?>"></span>
            <?= !empty($inst['conectado']) ? 'Conectado' : 'Desconectado' ?>
        </span>
    <?php endif; ?>
    <div style="margin-left:auto;display:flex;gap:.4rem;">
        <a href="<?= url('modules/whatsapp/?canal=' . ($isComercial ? '24' : '21')) ?>" class="btn btn-outline btn-sm">
            Ir para <?= $isComercial ? 'DDD 24 (CX)' : 'DDD 21 (Comercial)' ?> →
        </a>
        <?php if (has_min_role('gestao')): ?>
            <a href="<?= module_url('whatsapp', 'configurar.php') ?>" class="btn btn-outline btn-sm">⚙️ Configurar</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$configurado): ?>
<div class="wa-not-config">
    <strong>⚠️ Instância DDD <?= e($canal) ?> ainda não configurada.</strong>
    <p style="margin:.5rem 0 0;font-size:.85rem;">
        <?php if (has_min_role('gestao')): ?>
            Vá em <a href="<?= module_url('whatsapp', 'configurar.php') ?>" style="color:var(--rose);font-weight:600;">⚙️ Configurar Z-API</a> e cole as credenciais da instância.
        <?php else: ?>
            Peça à gestão para configurar em: <em>Sistemas → WhatsApp → Configurar Z-API</em>.
        <?php endif; ?>
    </p>
</div>
<?php else: ?>

<div class="wa-wrap" id="waWrap">
    <!-- ── Coluna esquerda: Inbox ────────────────────────── -->
    <div class="wa-pane">
        <div class="wa-head">
            Conversas <span class="wa-head-sub" id="waCount"></span>
        </div>
        <div class="wa-toolbar">
            <input type="text" class="wa-search" id="waSearch" placeholder="🔍 Buscar por nome ou telefone...">
            <div class="wa-filters">
                <button class="wa-filter active" data-filter="todos">Todos</button>
                <button class="wa-filter" data-filter="aguardando">Aguardando</button>
                <button class="wa-filter" data-filter="em_atendimento">Em atend.</button>
                <button class="wa-filter" data-filter="bot">🤖 Bot</button>
                <button class="wa-filter" data-filter="nao_lidas">🔴 Não lidas</button>
                <button class="wa-filter" data-filter="resolvido">✅ Resolv.</button>
            </div>
        </div>
        <div class="wa-list" id="waList">
            <div class="wa-empty">Carregando...</div>
        </div>
    </div>

    <!-- ── Coluna direita: Chat (placeholder 1.3) ────────── -->
    <div class="wa-pane">
        <div class="wa-head" style="background:#f9fafb;color:var(--text);border-bottom:1px solid var(--border);">
            <span id="waChatTitle">Selecione uma conversa</span>
            <span class="wa-head-sub" id="waChatSub"></span>
        </div>
        <div id="waChatBody" style="flex:1;overflow-y:auto;padding:1rem;background:#fafafa;">
            <div class="wa-chat-empty">
                <div class="wa-chat-empty-ico">💬</div>
                <div>Clique em uma conversa à esquerda para visualizar as mensagens.</div>
                <div style="font-size:.75rem;margin-top:.5rem;opacity:.7;">Envio de mensagens será liberado no próximo checkpoint.</div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var canal = '<?= e($canal) ?>';
    var apiUrl = '<?= module_url('whatsapp', 'api.php') ?>';
    var filtroAtual = 'todos';
    var buscaAtual = '';
    var convAtiva = null;
    var pollTimer = null;

    function escapeHtml(s) { return (s||'').replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function iniciais(n) { if(!n) return '?'; var p=n.trim().split(/\s+/); return (p[0][0]+(p[1]?p[1][0]:'')).toUpperCase(); }
    function formatTel(t) {
        if(!t) return '';
        var n = t.replace(/[^0-9]/g, '');
        if (n.length >= 12) return '+' + n.substr(0,2) + ' ' + n.substr(2,2) + ' ' + n.substr(4,5) + '-' + n.substr(9);
        return t;
    }
    function formatHora(iso) {
        if(!iso) return '';
        var d = new Date(iso.replace(' ', 'T'));
        var hoje = new Date();
        if (d.toDateString() === hoje.toDateString()) return d.toTimeString().substr(0,5);
        var ontem = new Date(); ontem.setDate(ontem.getDate() - 1);
        if (d.toDateString() === ontem.toDateString()) return 'ontem';
        return ('0'+d.getDate()).slice(-2) + '/' + ('0'+(d.getMonth()+1)).slice(-2);
    }

    function carregarLista() {
        var url = apiUrl + '?action=listar_conversas&canal=' + canal + '&status=' + filtroAtual + '&q=' + encodeURIComponent(buscaAtual);
        fetch(url).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok) return;
            var list = document.getElementById('waList');
            document.getElementById('waCount').textContent = '(' + d.conversas.length + ')';
            if (d.conversas.length === 0) {
                list.innerHTML = '<div class="wa-empty">Nenhuma conversa.</div>';
                return;
            }
            var html = '';
            d.conversas.forEach(function(c){
                var nome = c.nome_contato || c.client_name || c.lead_name || formatTel(c.telefone);
                var isActive = convAtiva === c.id ? 'active' : '';
                html += '<div class="wa-conv '+isActive+'" data-id="'+c.id+'" onclick="waAbrir('+c.id+')">';
                html += '  <div class="wa-avatar">' + iniciais(nome) + '</div>';
                html += '  <div class="wa-conv-info">';
                html += '    <div class="wa-conv-name">' + escapeHtml(nome);
                if (+c.bot_ativo) html += '<span class="wa-bot-badge">🤖 BOT</span>';
                html += '</div>';
                html += '    <div class="wa-conv-preview">' + escapeHtml((c.ultima_mensagem||'').substr(0,60)) + '</div>';
                html += '  </div>';
                html += '  <div class="wa-conv-meta">';
                html += '    <div>' + formatHora(c.ultima_msg_em) + '</div>';
                if (+c.nao_lidas > 0) html += '<div class="wa-unread">'+c.nao_lidas+'</div>';
                html += '  </div>';
                html += '</div>';
            });
            list.innerHTML = html;
        });
    }

    window.waAbrir = function(id) {
        convAtiva = id;
        document.querySelectorAll('.wa-conv').forEach(function(el){ el.classList.remove('active'); });
        var el = document.querySelector('.wa-conv[data-id="'+id+'"]');
        if (el) el.classList.add('active');

        fetch(apiUrl + '?action=abrir_conversa&id=' + id).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok) return;
            var c = d.conversa;
            var nome = c.nome_contato || c.client_name || c.lead_name || formatTel(c.telefone);
            document.getElementById('waChatTitle').textContent = nome;
            document.getElementById('waChatSub').textContent = formatTel(c.telefone) + (c.atendente_name ? ' · 👤 ' + c.atendente_name : '');
            var body = document.getElementById('waChatBody');
            if (!d.mensagens.length) {
                body.innerHTML = '<div class="wa-chat-empty"><div class="wa-chat-empty-ico">📭</div><div>Nenhuma mensagem nesta conversa ainda.</div></div>';
                return;
            }
            var html = '<div style="display:flex;flex-direction:column;gap:.5rem;">';
            d.mensagens.forEach(function(m){
                var dir = m.direcao === 'recebida' ? 'left' : 'right';
                var bg  = m.direcao === 'recebida' ? '#fff' : (+m.enviado_por_bot ? '#ede9fe' : '<?= $accentLight ?>');
                var border = m.direcao === 'recebida' ? '#e5e7eb' : '<?= $accentColor ?>';
                html += '<div style="display:flex;justify-content:'+(dir==='left'?'flex-start':'flex-end')+';">';
                html += '  <div style="max-width:70%;background:'+bg+';border:1px solid '+border+';border-radius:10px;padding:.5rem .75rem;font-size:.82rem;">';
                if (+m.enviado_por_bot) html += '<div style="font-size:.65rem;font-weight:700;color:#7c3aed;margin-bottom:2px;">🤖 BOT</div>';
                html += '<div style="white-space:pre-wrap;">' + escapeHtml(m.conteudo||'') + '</div>';
                html += '<div style="font-size:.62rem;color:#9ca3af;text-align:right;margin-top:3px;">' + formatHora(m.created_at) + '</div>';
                html += '  </div>';
                html += '</div>';
            });
            html += '</div>';
            body.innerHTML = html;
            body.scrollTop = body.scrollHeight;
        });
    };

    // Filtros
    document.querySelectorAll('.wa-filter').forEach(function(b){
        b.addEventListener('click', function(){
            document.querySelectorAll('.wa-filter').forEach(function(x){ x.classList.remove('active'); });
            b.classList.add('active');
            filtroAtual = b.dataset.filter;
            carregarLista();
        });
    });
    // Busca
    var stBusca;
    document.getElementById('waSearch').addEventListener('input', function(e){
        clearTimeout(stBusca);
        buscaAtual = e.target.value;
        stBusca = setTimeout(carregarLista, 300);
    });

    // Polling a cada 5s
    carregarLista();
    pollTimer = setInterval(carregarLista, 5000);
})();
</script>

<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
