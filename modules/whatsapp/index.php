<?php
/**
 * Ferreira & Sá Hub — WhatsApp CRM Inbox + Chat
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

$accentColor = $isComercial ? '#b08d6e' : '#0f3460';
$accentLight = $isComercial ? '#fdf5ed' : '#eef2f8';

$csrfToken = generate_csrf_token();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.wa-wrap { display:grid;grid-template-columns:360px 1fr;gap:.5rem;height:calc(100vh - 140px);min-height:560px; }
.wa-pane { background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column; }
.wa-head { padding:.6rem .9rem;background:<?= $accentColor ?>;color:#fff;font-weight:600;font-size:.9rem;display:flex;align-items:center;gap:.5rem; }
.wa-head-sub { font-size:.7rem;opacity:.8;font-weight:400;margin-left:auto; }
.wa-badge-canal { display:inline-block;padding:1px 8px;border-radius:10px;font-size:.65rem;font-weight:700;background:<?= $accentColor ?>;color:#fff;letter-spacing:.3px; }
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
.wa-chat-head { padding:.6rem .9rem;background:#f9fafb;color:var(--text);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap; }
.wa-chat-head strong { font-size:.9rem; }
.wa-chat-actions { margin-left:auto;display:flex;gap:.3rem;flex-wrap:wrap; }
.wa-chat-actions button { padding:4px 8px;font-size:.72rem;border:1px solid var(--border);background:#fff;border-radius:6px;cursor:pointer;color:var(--text); }
.wa-chat-actions button:hover { background:<?= $accentLight ?>;border-color:<?= $accentColor ?>; }
.wa-chat-actions .btn-primary-sm { background:<?= $accentColor ?>;color:#fff;border-color:<?= $accentColor ?>; }
.wa-chat-body { flex:1;overflow-y:auto;padding:1rem;background:#faf8f5; }
.wa-msg-row { display:flex;margin-bottom:.4rem; }
.wa-msg-row.left { justify-content:flex-start; }
.wa-msg-row.right { justify-content:flex-end; }
.wa-msg { max-width:70%;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:.5rem .75rem;font-size:.83rem;box-shadow:0 1px 2px rgba(0,0,0,.04); }
.wa-msg.sent { background:<?= $accentLight ?>;border-color:<?= $accentColor ?>; }
.wa-msg.bot  { background:#ede9fe;border-color:#c4b5fd; }
.wa-msg-tag { font-size:.62rem;font-weight:700;margin-bottom:2px; }
.wa-msg-time { font-size:.62rem;color:#9ca3af;text-align:right;margin-top:3px; }
.wa-chat-input { padding:.5rem .6rem;border-top:1px solid var(--border);background:#fff;display:flex;gap:.4rem;align-items:flex-end; }
.wa-chat-input textarea { flex:1;min-height:38px;max-height:120px;resize:none;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:.85rem;font-family:inherit; }
.wa-btn-send { padding:8px 16px;background:<?= $accentColor ?>;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:.85rem; }
.wa-btn-send:disabled { opacity:.5;cursor:not-allowed; }
.wa-btn-tpl { padding:8px 10px;background:#fff;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:1rem; }
.wa-templates-menu { position:absolute;bottom:56px;left:10px;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.12);padding:.5rem;max-width:340px;z-index:100;display:none; }
.wa-templates-menu.open { display:block; }
.wa-tpl-item { padding:.4rem .6rem;border-radius:6px;cursor:pointer;font-size:.78rem;border-bottom:1px solid #f3f4f6; }
.wa-tpl-item:hover { background:<?= $accentLight ?>; }
.wa-tpl-item strong { display:block;color:var(--text);margin-bottom:2px; }
.wa-tpl-item span { color:var(--text-muted);font-size:.72rem; }
.wa-not-config { padding:1.5rem;background:#fffbeb;border:1px solid #f59e0b;border-radius:10px;color:#78350f; }
@media (max-width:900px){ .wa-wrap{grid-template-columns:1fr;height:auto;} }
</style>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;flex-wrap:wrap;">
    <h1 style="margin:0;">💬 WhatsApp <?= e($canalLabel) ?></h1>
    <span class="wa-badge-canal">DDD <?= e($canal) ?></span>
    <?php if ($configurado): ?>
        <span id="waStatusIndicator" style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;color:var(--text-muted);">
            <span class="wa-status-dot <?= !empty($inst['conectado']) ? 'on' : 'off' ?>" id="waStatusDot"></span>
            <span id="waStatusText"><?= !empty($inst['conectado']) ? 'Conectado' : 'Desconectado' ?></span>
        </span>
        <button onclick="waVerificarStatus()" class="btn btn-outline btn-sm" style="font-size:.68rem;padding:2px 8px;" title="Consultar status na Z-API">🔄</button>
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
                <button class="wa-filter" data-filter="nao_lidas">🔴 Não lidas</button>
                <button class="wa-filter" data-filter="resolvido">✅ Resolv.</button>
            </div>
        </div>
        <div class="wa-list" id="waList">
            <div class="wa-empty">Carregando...</div>
        </div>
    </div>

    <!-- ── Coluna direita: Chat ────────────────────────── -->
    <div class="wa-pane" style="position:relative;">
        <div class="wa-chat-head" id="waChatHeadContainer">
            <strong id="waChatTitle">Selecione uma conversa</strong>
            <span class="wa-head-sub" id="waChatSub"></span>
        </div>
        <div id="waChatBody" class="wa-chat-body">
            <div class="wa-chat-empty">
                <div class="wa-chat-empty-ico">💬</div>
                <div>Clique em uma conversa à esquerda para ver e responder.</div>
            </div>
        </div>

        <!-- Menu de templates (popover) -->
        <div class="wa-templates-menu" id="waTemplatesMenu"></div>

        <!-- Input de mensagem (escondido até abrir uma conversa) -->
        <div class="wa-chat-input" id="waChatInput" style="display:none;">
            <button class="wa-btn-tpl" onclick="waToggleTemplates()" title="Respostas rápidas">📋</button>
            <button class="wa-btn-tpl" onclick="document.getElementById('waFile').click()" title="Anexar imagem ou documento">📎</button>
            <input type="file" id="waFile" style="display:none;" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar">
            <textarea id="waInput" placeholder="Digite uma mensagem (Shift+Enter = nova linha)..." rows="1"></textarea>
            <button class="wa-btn-send" id="waBtnSend" onclick="waEnviar()">➤ Enviar</button>
        </div>
    </div>
</div>

<script>
(function(){
    var canal  = '<?= e($canal) ?>';
    var apiUrl = '<?= module_url('whatsapp', 'api.php') ?>';
    var csrf   = '<?= e($csrfToken) ?>';
    var filtroAtual = 'todos';
    var buscaAtual  = '';
    var convAtiva   = null;
    var pollTimer   = null;
    var templatesCache = null;

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

    // ── LISTA DE CONVERSAS ──────────────────────────────
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

    // ── ABRIR CONVERSA ──────────────────────────────────
    window.waAbrir = function(id) {
        convAtiva = id;
        document.querySelectorAll('.wa-conv').forEach(function(el){ el.classList.remove('active'); });
        var el = document.querySelector('.wa-conv[data-id="'+id+'"]');
        if (el) el.classList.add('active');

        fetch(apiUrl + '?action=abrir_conversa&id=' + id).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok) return;
            renderConversa(d);
        });
    };

    function renderConversa(d) {
        var c = d.conversa;
        var nome = c.nome_contato || c.client_name || c.lead_name || formatTel(c.telefone);

        // Header com ações
        var head = document.getElementById('waChatHeadContainer');
        var actions = '<div class="wa-chat-actions">';
        if (!c.atendente_id || +c.atendente_id !== <?= (int)$user['id'] ?>) {
            actions += '<button class="btn-primary-sm" onclick="waAssumir()">👤 Assumir</button>';
        }
        if (c.status !== 'resolvido') {
            actions += '<button onclick="waResolver()">✅ Resolver</button>';
        }
        actions += '<button onclick="waArquivar()" title="Arquivar">🗄</button>';
        actions += '</div>';

        var subTxt = formatTel(c.telefone);
        if (c.atendente_name) subTxt += ' · 👤 ' + c.atendente_name;
        if (c.client_name) subTxt += ' · 🎯 Cliente';
        else if (c.lead_name) subTxt += ' · 📈 Lead';

        head.innerHTML =
            '<strong>' + escapeHtml(nome) + '</strong>' +
            '<span class="wa-head-sub">' + escapeHtml(subTxt) + '</span>' +
            actions;

        // Body com mensagens
        var body = document.getElementById('waChatBody');
        if (!d.mensagens.length) {
            body.innerHTML = '<div class="wa-chat-empty"><div class="wa-chat-empty-ico">📭</div><div>Nenhuma mensagem ainda.</div></div>';
        } else {
            var html = '';
            d.mensagens.forEach(function(m){
                var dir = m.direcao === 'recebida' ? 'left' : 'right';
                var cls = 'wa-msg';
                if (m.direcao === 'enviada') cls += ' sent';
                if (+m.enviado_por_bot) cls += ' bot';
                html += '<div class="wa-msg-row '+dir+'">';
                html += '<div class="'+cls+'">';
                if (+m.enviado_por_bot) html += '<div class="wa-msg-tag" style="color:#7c3aed;">🤖 BOT</div>';
                else if (m.direcao === 'enviada' && m.enviado_por_name) html += '<div class="wa-msg-tag" style="color:#6b7280;">' + escapeHtml(m.enviado_por_name) + '</div>';
                // Mídia (imagem/vídeo/áudio/documento)
                if (m.arquivo_url) {
                    if (m.tipo === 'imagem') {
                        html += '<a href="'+escapeHtml(m.arquivo_url)+'" target="_blank"><img src="'+escapeHtml(m.arquivo_url)+'" style="max-width:260px;max-height:260px;border-radius:8px;margin-bottom:4px;display:block;" onerror="this.style.display=\'none\';this.nextSibling&&(this.nextSibling.style.display=\'inline\');"></a>';
                        html += '<span style="display:none;color:#ef4444;font-size:.7rem;">🖼️ Imagem (URL expirada)</span>';
                    } else if (m.tipo === 'video') {
                        html += '<video src="'+escapeHtml(m.arquivo_url)+'" controls style="max-width:260px;border-radius:8px;margin-bottom:4px;"></video>';
                    } else if (m.tipo === 'audio') {
                        html += '<audio src="'+escapeHtml(m.arquivo_url)+'" controls style="width:220px;margin-bottom:4px;"></audio>';
                    } else if (m.tipo === 'documento') {
                        var nm = m.arquivo_nome || 'documento';
                        html += '<a href="'+escapeHtml(m.arquivo_url)+'" target="_blank" style="display:inline-flex;gap:6px;align-items:center;padding:6px 10px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:var(--text);margin-bottom:4px;"><span>📄</span><span>'+escapeHtml(nm)+'</span></a>';
                    } else if (m.tipo === 'sticker') {
                        html += '<img src="'+escapeHtml(m.arquivo_url)+'" style="max-width:120px;margin-bottom:4px;">';
                    }
                }
                // Texto / caption
                if (m.conteudo && m.conteudo !== ('[' + m.tipo + ']')) {
                    html += '<div style="white-space:pre-wrap;word-break:break-word;">' + escapeHtml(m.conteudo) + '</div>';
                } else if (!m.arquivo_url) {
                    // Fallback: se não tem arquivo nem texto útil, mostra o tipo
                    html += '<div style="color:#9ca3af;font-style:italic;">' + escapeHtml(m.conteudo||'[' + m.tipo + ']') + '</div>';
                }
                var statusIcon = '';
                if (m.direcao === 'enviada') {
                    if (+m.lida) statusIcon = ' <span style="color:#3b82f6;">✓✓</span>';
                    else if (+m.entregue) statusIcon = ' <span style="color:#9ca3af;">✓✓</span>';
                    else statusIcon = ' <span style="color:#9ca3af;">✓</span>';
                }
                html += '<div class="wa-msg-time">' + formatHora(m.created_at) + statusIcon + '</div>';
                html += '</div>';
                html += '</div>';
            });
            body.innerHTML = html;
            body.scrollTop = body.scrollHeight;
        }

        // Mostrar input de mensagem
        document.getElementById('waChatInput').style.display = 'flex';
        document.getElementById('waInput').focus();
    }

    // ── ENVIAR MENSAGEM ─────────────────────────────────
    window.waEnviar = function() {
        if (!convAtiva) return;
        var txt = document.getElementById('waInput').value.trim();
        if (!txt) return;
        var btn = document.getElementById('waBtnSend');
        btn.disabled = true; btn.textContent = 'Enviando...';

        var fd = new FormData();
        fd.append('action', 'enviar_mensagem');
        fd.append('conversa_id', convAtiva);
        fd.append('mensagem', txt);
        fd.append('csrf_token', csrf);

        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            if (d.error) { alert('Erro: ' + d.error); return; }
            document.getElementById('waInput').value = '';
            window.waAbrir(convAtiva);
            carregarLista();
        }).catch(function(e){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            alert('Falha: ' + e);
        });
    };

    // Enter = enviar, Shift+Enter = nova linha
    document.addEventListener('keydown', function(e){
        if (e.target.id === 'waInput' && e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            waEnviar();
        }
    });

    // ── ENVIO DE ARQUIVO (imagem ou documento) ──────────
    document.getElementById('waFile').addEventListener('change', function(e){
        if (!convAtiva) { alert('Selecione uma conversa primeiro.'); return; }
        var file = e.target.files[0];
        if (!file) return;
        if (file.size > 16 * 1024 * 1024) { alert('Arquivo maior que 16 MB.'); return; }

        var caption = document.getElementById('waInput').value.trim();
        var fd = new FormData();
        fd.append('action', 'enviar_arquivo');
        fd.append('conversa_id', convAtiva);
        fd.append('caption', caption);
        fd.append('arquivo', file);
        fd.append('csrf_token', csrf);

        var btn = document.getElementById('waBtnSend');
        btn.disabled = true; btn.textContent = 'Enviando...';

        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            e.target.value = ''; // reset file input
            if (d.error) { alert('Erro: ' + d.error); return; }
            document.getElementById('waInput').value = '';
            window.waAbrir(convAtiva);
            carregarLista();
        }).catch(function(err){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            e.target.value = '';
            alert('Falha: ' + err);
        });
    });

    // ── AÇÕES NA CONVERSA ───────────────────────────────
    function acaoConversa(action, extra) {
        if (!convAtiva) return;
        var fd = new FormData();
        fd.append('action', action);
        fd.append('conversa_id', convAtiva);
        fd.append('csrf_token', csrf);
        if (extra) for (var k in extra) fd.append(k, extra[k]);
        return fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); });
    }
    window.waAssumir   = function() { acaoConversa('assumir_atendimento').then(function(){ window.waAbrir(convAtiva); carregarLista(); }); };
    window.waResolver  = function() { if(confirm('Marcar como resolvida?')) acaoConversa('resolver').then(function(){ window.waAbrir(convAtiva); carregarLista(); }); };
    window.waArquivar  = function() { if(confirm('Arquivar conversa?')) acaoConversa('arquivar').then(function(){ convAtiva=null; location.reload(); }); };

    // ── TEMPLATES (respostas rápidas) ───────────────────
    window.waToggleTemplates = function() {
        var menu = document.getElementById('waTemplatesMenu');
        if (menu.classList.contains('open')) { menu.classList.remove('open'); return; }
        if (templatesCache === null) {
            fetch(apiUrl + '?action=listar_templates&canal=' + canal).then(function(r){ return r.json(); }).then(function(d){
                if (!d.ok) return;
                templatesCache = d.templates;
                renderTemplates();
                menu.classList.add('open');
            });
        } else {
            renderTemplates();
            menu.classList.add('open');
        }
    };
    function renderTemplates() {
        var menu = document.getElementById('waTemplatesMenu');
        if (!templatesCache || !templatesCache.length) {
            menu.innerHTML = '<div class="wa-empty" style="padding:1rem;">Nenhum template.</div>';
            return;
        }
        var html = '';
        templatesCache.forEach(function(t){
            html += '<div class="wa-tpl-item" onclick=\'waUsarTemplate('+JSON.stringify(t.conteudo).replace(/\'/g,"&#39;")+')\'>';
            html += '<strong>' + escapeHtml(t.nome) + '</strong>';
            html += '<span>' + escapeHtml((t.conteudo||'').substr(0,80)) + '</span>';
            html += '</div>';
        });
        menu.innerHTML = html;
    }
    window.waUsarTemplate = function(txt) {
        document.getElementById('waInput').value = txt;
        document.getElementById('waTemplatesMenu').classList.remove('open');
        document.getElementById('waInput').focus();
    };
    // Fechar templates ao clicar fora
    document.addEventListener('click', function(e){
        var menu = document.getElementById('waTemplatesMenu');
        var btn  = e.target.closest('.wa-btn-tpl');
        if (!btn && !e.target.closest('#waTemplatesMenu')) menu.classList.remove('open');
    });

    // ── VERIFICAR STATUS DA INSTÂNCIA ───────────────────
    window.waVerificarStatus = function() {
        fetch(apiUrl + '?action=verificar_status&ddd=' + canal).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok) return;
            var dot = document.getElementById('waStatusDot');
            var txt = document.getElementById('waStatusText');
            if (d.conectado === true) { dot.className = 'wa-status-dot on'; txt.textContent = 'Conectado'; }
            else if (d.conectado === false) { dot.className = 'wa-status-dot off'; txt.textContent = 'Desconectado'; }
            else { txt.textContent = 'Erro ao verificar'; }
        });
    };

    // ── FILTROS / BUSCA / POLLING ───────────────────────
    document.querySelectorAll('.wa-filter').forEach(function(b){
        b.addEventListener('click', function(){
            document.querySelectorAll('.wa-filter').forEach(function(x){ x.classList.remove('active'); });
            b.classList.add('active');
            filtroAtual = b.dataset.filter;
            carregarLista();
        });
    });
    var stBusca;
    document.getElementById('waSearch').addEventListener('input', function(e){
        clearTimeout(stBusca);
        buscaAtual = e.target.value;
        stBusca = setTimeout(carregarLista, 300);
    });

    // Verificar status automaticamente ao carregar
    waVerificarStatus();

    // Polling a cada 5s: atualiza lista + conversa aberta
    carregarLista();
    pollTimer = setInterval(function(){
        carregarLista();
        if (convAtiva) {
            // Atualiza mensagens da conversa aberta
            fetch(apiUrl + '?action=abrir_conversa&id=' + convAtiva).then(function(r){ return r.json(); }).then(function(d){
                if (d.ok && d.mensagens) {
                    var body = document.getElementById('waChatBody');
                    var scrollAtBottom = (body.scrollHeight - body.scrollTop - body.clientHeight) < 50;
                    renderConversa(d);
                    if (!scrollAtBottom) body.scrollTop = body.scrollTop; // preserva scroll se user subiu
                }
            });
        }
    }, 5000);
})();
</script>

<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
