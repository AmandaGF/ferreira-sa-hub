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

// Config: mostrar nome do atendente no chat interno (default: sim)
$mostrarNomeAtendente = '1';
try {
    $r = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'zapi_mostrar_nome_interno'")->fetchColumn();
    if ($r !== false && $r !== null) $mostrarNomeAtendente = $r;
} catch (Exception $e) {}

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
.wa-msg.deleted { opacity:.5;font-style:italic; }
.wa-msg:hover .wa-msg-actions { display:flex !important; }
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
.wa-etiqueta { display:inline-block;padding:1px 7px;border-radius:10px;font-size:.62rem;font-weight:700;color:#fff;margin-right:3px;margin-top:2px;letter-spacing:.2px; }
.wa-etq-bar { display:flex;gap:3px;flex-wrap:wrap;margin-top:2px; }
.wa-etq-remove { cursor:pointer;margin-left:3px;opacity:.8; }
.wa-etq-remove:hover { opacity:1; }
.wa-etq-popover { position:absolute;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:.5rem;z-index:200;display:none;min-width:220px;max-height:300px;overflow-y:auto; }
.wa-etq-popover.open { display:block; }
.wa-etq-opt { display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:6px;cursor:pointer;font-size:.78rem; }
.wa-etq-opt:hover { background:#f3f4f6; }
.wa-etq-opt .wa-etiqueta { font-size:.7rem; }
.wa-name-edit { background:transparent;border:1px dashed var(--border);border-radius:4px;padding:2px 6px;font-size:.9rem;font-weight:700;min-width:150px; }
.wa-name-display { cursor:pointer;border-bottom:1px dashed transparent;padding:2px 0;font-size:.9rem;font-weight:700; }
.wa-name-display:hover { border-bottom-color:var(--text-muted); }
.wa-etq-filter { padding:3px 8px;border-radius:10px;font-size:.68rem;background:#fff;border:1px solid var(--border);cursor:pointer;color:var(--text-muted);white-space:nowrap; }
.wa-etq-filter.active { color:#fff !important;border-color:transparent !important; }
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
            <button onclick="waImportarTodas()" class="btn btn-outline btn-sm" title="Importar lista de contatos (Multi Device não permite baixar mensagens antigas)">👥 Importar contatos</button>
            <a href="<?= module_url('whatsapp', 'central.php') ?>" class="btn btn-outline btn-sm" title="Templates, Etiquetas, Automações, Z-API">⚙️ Configurações</a>
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
            <div class="wa-filters" id="waEtqFilters" style="max-height:60px;overflow-y:auto;"></div>
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

        <!-- Preview de arquivo pendente (aparece só quando colou/anexou) -->
        <div id="waPendingArquivo" style="display:none;padding:.5rem .7rem;background:<?= $accentLight ?>;border-top:1px solid var(--border);border-bottom:1px solid var(--border);align-items:center;gap:.6rem;">
            <img id="waPendingPreview" src="" style="max-width:80px;max-height:80px;border-radius:6px;display:none;">
            <span id="waPendingIcon" style="font-size:1.8rem;display:none;">📄</span>
            <div style="flex:1;min-width:0;">
                <div id="waPendingNome" style="font-size:.82rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                <div id="waPendingTipo" style="font-size:.7rem;color:var(--text-muted);"></div>
                <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px;">💡 Adicione uma legenda no campo abaixo (opcional) e clique em <strong>Enviar</strong>.</div>
            </div>
            <button onclick="waCancelarPendente()" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 8px;cursor:pointer;" title="Cancelar">✕</button>
        </div>

        <!-- Input de mensagem (escondido até abrir uma conversa) -->
        <div class="wa-chat-input" id="waChatInput" style="display:none;">
            <button class="wa-btn-tpl" onclick="waToggleTemplates()" title="Respostas rápidas">📋</button>
            <button class="wa-btn-tpl" onclick="document.getElementById('waFile').click()" title="Anexar imagem ou documento">📎</button>
            <input type="file" id="waFile" style="display:none;" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar">
            <textarea id="waInput" placeholder="Digite uma mensagem ou cole uma imagem (Ctrl+V)..." rows="1"></textarea>
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
    var etiquetaFiltro = 0;
    var convAtiva   = null;
    var pollTimer   = null;
    var templatesCache = null;
    var etiquetasCache = null;
    var arquivoPendente = null; // {file, previewUrl}
    var convNomeAtual = ''; // nome do contato da conversa aberta (pra {{nome}})
    var mostrarNomeAtendente = <?= $mostrarNomeAtendente === '1' ? 'true' : 'false' ?>; // config

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
        if (etiquetaFiltro) url += '&etiqueta=' + etiquetaFiltro;
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
                if (c.etiquetas && c.etiquetas.length) {
                    html += '    <div class="wa-etq-bar">';
                    c.etiquetas.forEach(function(et){
                        html += '<span class="wa-etiqueta" style="background:'+escapeHtml(et.cor)+';">'+escapeHtml(et.nome)+'</span>';
                    });
                    html += '    </div>';
                }
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
        convNomeAtual = nome; // guarda pra substituição de {{nome}}

        // Header com ações
        var head = document.getElementById('waChatHeadContainer');
        var actions = '<div class="wa-chat-actions">';
        actions += '<button onclick="waToggleEtiquetas(event)" title="Etiquetas">🏷 Etiqueta</button>';
        if (c.canal === '21') {
            if (+c.bot_ativo) actions += '<button onclick="waToggleBot(0)" style="background:#ede9fe;border-color:#a78bfa;color:#6d28d9;" title="Bot ativo — clique para desativar">🤖 Bot ON</button>';
            else               actions += '<button onclick="waToggleBot(1)" title="Ativar bot IA para responder sozinho">🤖 Bot OFF</button>';
        }
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

        // Etiquetas aplicadas
        var etqHtml = '';
        if (c.etiquetas && c.etiquetas.length) {
            etqHtml = '<div style="display:flex;gap:3px;flex-wrap:wrap;margin-top:4px;">';
            c.etiquetas.forEach(function(et){
                etqHtml += '<span class="wa-etiqueta" style="background:'+escapeHtml(et.cor)+';">' + escapeHtml(et.nome) +
                    ' <span class="wa-etq-remove" onclick="waRemoverEtiqueta('+et.id+')" title="Remover">×</span></span>';
            });
            etqHtml += '</div>';
        }

        head.innerHTML =
            '<div style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;">' +
                '<div style="display:flex;align-items:center;gap:6px;">' +
                    '<span class="wa-name-display" onclick="waEditarNome()" id="waNomeDisplay" title="Clique para editar">' + escapeHtml(nome) + '</span>' +
                '</div>' +
                '<span class="wa-head-sub" style="margin-left:0;">' + escapeHtml(subTxt) + '</span>' +
                etqHtml +
            '</div>' +
            actions +
            '<div class="wa-etq-popover" id="waEtqPopover"></div>';

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
                if (m.status === 'deletada') cls += ' deleted';
                html += '<div class="wa-msg-row '+dir+'" data-msg-id="'+m.id+'">';
                html += '<div class="'+cls+'" style="position:relative;">';
                // Botões hover (apagar/editar) só pra mensagens enviadas pelo Hub e não deletadas
                if (m.direcao === 'enviada' && m.status !== 'deletada' && m.zapi_message_id) {
                    html += '<div class="wa-msg-actions" style="position:absolute;top:2px;right:2px;display:none;gap:3px;">';
                    if (m.tipo === 'texto') html += '<button onclick="waEditarMsg('+m.id+')" title="Editar (até 15 min)" style="background:rgba(255,255,255,.9);border:1px solid #e5e7eb;border-radius:4px;width:22px;height:22px;font-size:.7rem;cursor:pointer;padding:0;">✏️</button>';
                    html += '<button onclick="waDeletarMsg('+m.id+')" title="Apagar" style="background:rgba(255,255,255,.9);border:1px solid #e5e7eb;border-radius:4px;width:22px;height:22px;font-size:.7rem;cursor:pointer;padding:0;">🗑</button>';
                    html += '</div>';
                }
                if (+m.enviado_por_bot) html += '<div class="wa-msg-tag" style="color:#7c3aed;">🤖 BOT</div>';
                else if (m.direcao === 'enviada' && m.enviado_por_name && mostrarNomeAtendente) html += '<div class="wa-msg-tag" style="color:#6b7280;">' + escapeHtml(m.enviado_por_name) + '</div>';
                // Botão "Salvar no Drive" pra arquivos RECEBIDOS (tem arquivo_url e não foi salvo ainda)
                if (m.direcao === 'recebida' && m.arquivo_url && m.tipo !== 'texto') {
                    if (+m.arquivo_salvo_drive) {
                        html += '<div style="font-size:.68rem;color:#22c55e;font-weight:700;margin-bottom:3px;">✅ Salvo no Drive</div>';
                    } else {
                        html += '<button onclick="waSalvarDrive('+m.id+')" style="background:#4285f4;color:#fff;border:none;padding:3px 8px;border-radius:5px;font-size:.7rem;cursor:pointer;margin-bottom:5px;display:block;">📁 Salvar no Drive</button>';
                    }
                }
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

    // ── ENVIAR MENSAGEM ou ARQUIVO PENDENTE ─────────────
    window.waEnviar = function() {
        if (!convAtiva) return;
        var txt = document.getElementById('waInput').value.trim();
        // Safety net: se ainda tiver {{nome}} no texto, substitui antes de enviar
        if (txt && /\{\{[a-z_]+\}\}/i.test(txt)) {
            var primeiroNome = (convNomeAtual || '').split(/\s+/)[0] || '';
            var agora = new Date();
            txt = txt.replace(/\{\{nome\}\}/gi, primeiroNome)
                     .replace(/\{\{data\}\}/gi, agora.toLocaleDateString('pt-BR'))
                     .replace(/\{\{hora\}\}/gi, agora.toTimeString().substr(0,5));
            document.getElementById('waInput').value = txt;
        }

        // Se há arquivo pendente, enviar ele com o texto como caption
        if (arquivoPendente) {
            var f = arquivoPendente.file;
            if (arquivoPendente.previewUrl) URL.revokeObjectURL(arquivoPendente.previewUrl);
            arquivoPendente = null;
            esconderPendente();
            enviarArquivoBlob(f, txt);
            return;
        }

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

    // ── PENDENTE: preview de arquivo antes de enviar ────
    function mostrarPendente(file) {
        var isImg = file.type && file.type.indexOf('image/') === 0;
        var box = document.getElementById('waPendingArquivo');
        var preview = document.getElementById('waPendingPreview');
        var icon = document.getElementById('waPendingIcon');
        var nome = document.getElementById('waPendingNome');
        var tipo = document.getElementById('waPendingTipo');

        if (arquivoPendente && arquivoPendente.previewUrl) URL.revokeObjectURL(arquivoPendente.previewUrl);

        arquivoPendente = { file: file, previewUrl: null };

        if (isImg) {
            arquivoPendente.previewUrl = URL.createObjectURL(file);
            preview.src = arquivoPendente.previewUrl;
            preview.style.display = 'block';
            icon.style.display = 'none';
        } else {
            preview.style.display = 'none';
            icon.style.display = 'inline';
        }
        nome.textContent = file.name || 'arquivo';
        tipo.textContent = (file.type || '') + ' · ' + Math.round(file.size/1024) + ' KB';
        box.style.display = 'flex';
        document.getElementById('waInput').focus();
    }
    function esconderPendente() {
        document.getElementById('waPendingArquivo').style.display = 'none';
        document.getElementById('waPendingPreview').src = '';
    }
    window.waCancelarPendente = function() {
        if (arquivoPendente && arquivoPendente.previewUrl) URL.revokeObjectURL(arquivoPendente.previewUrl);
        arquivoPendente = null;
        esconderPendente();
    };

    // Enter = enviar, Shift+Enter = nova linha
    document.addEventListener('keydown', function(e){
        if (e.target.id === 'waInput' && e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            waEnviar();
        }
    });

    // ── ENVIO DE ARQUIVO (imagem ou documento) ──────────
    function enviarArquivoBlob(file, caption) {
        if (!convAtiva) { alert('Selecione uma conversa primeiro.'); return; }
        if (!file) return;
        if (file.size > 16 * 1024 * 1024) { alert('Arquivo maior que 16 MB.'); return; }

        var fd = new FormData();
        fd.append('action', 'enviar_arquivo');
        fd.append('conversa_id', convAtiva);
        fd.append('caption', caption || '');
        fd.append('arquivo', file, file.name || 'imagem.png');
        fd.append('csrf_token', csrf);

        var btn = document.getElementById('waBtnSend');
        btn.disabled = true; btn.textContent = 'Enviando...';

        return fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            if (d.error) { alert('Erro: ' + d.error); return; }
            document.getElementById('waInput').value = '';
            window.waAbrir(convAtiva);
            carregarLista();
        }).catch(function(err){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            alert('Falha: ' + err);
        });
    }

    document.getElementById('waFile').addEventListener('change', function(e){
        var file = e.target.files[0];
        if (!file) return;
        if (!convAtiva) { alert('Selecione uma conversa primeiro.'); e.target.value=''; return; }
        if (file.size > 16 * 1024 * 1024) { alert('Arquivo maior que 16 MB.'); e.target.value=''; return; }
        mostrarPendente(file);
        e.target.value = ''; // reset input pra poder escolher o mesmo arquivo novamente se precisar
    });

    // ── COLAR IMAGEM DIRETO (Ctrl+V em qualquer lugar do chat) ──
    function handlePaste(e) {
        if (!convAtiva) return;
        var cd = e.clipboardData || window.clipboardData;
        if (!cd) return;

        // Tentar via items primeiro (moderno)
        var items = cd.items;
        if (items && items.length) {
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                if (it.kind === 'file' && it.type && it.type.indexOf('image/') === 0) {
                    e.preventDefault();
                    var blob = it.getAsFile();
                    if (!blob) continue;
                    processarImagemColada(blob, it.type);
                    return;
                }
            }
        }
        // Fallback: cd.files (alguns navegadores antigos)
        if (cd.files && cd.files.length) {
            for (var j = 0; j < cd.files.length; j++) {
                var f = cd.files[j];
                if (f.type && f.type.indexOf('image/') === 0) {
                    e.preventDefault();
                    processarImagemColada(f, f.type);
                    return;
                }
            }
        }
        // Se não era imagem, deixa o paste padrão (texto normal)
    }

    function processarImagemColada(blob, mime) {
        var ext = (mime.split('/')[1] || 'png').split('+')[0];
        var nome = 'colado_' + Date.now() + '.' + ext;
        var fileComNome;
        try {
            fileComNome = new File([blob], nome, { type: mime });
        } catch (err) {
            fileComNome = blob;
            fileComNome.name = nome;
        }
        mostrarPendente(fileComNome);
    }

    // Anexa o paste no textarea E no container do chat inteiro (captura tudo)
    ['waInput', 'waChatBody', 'waWrap'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.addEventListener('paste', handlePaste);
    });
    // E também no documento inteiro como fallback, quando conversa está aberta
    document.addEventListener('paste', function(e){
        if (!convAtiva) return;
        // Se já foi tratado por outro listener mais específico, defaultPrevented estará true
        if (e.defaultPrevented) return;
        handlePaste(e);
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
    window.waToggleBot = function(ativar) {
        var action = ativar ? 'ativar_bot' : 'desativar_bot';
        acaoConversa(action).then(function(){ window.waAbrir(convAtiva); carregarLista(); });
    };

    // ── SALVAR ARQUIVO NO DRIVE ──────────────────────────
    window.waSalvarDrive = function(msgId) {
        if (!convAtiva) return;
        // Buscar casos do cliente desta conversa
        fetch(apiUrl + '?action=casos_do_cliente&conversa_id=' + convAtiva).then(function(r){ return r.json(); }).then(function(d){
            if (d.erro) { alert('⚠️ ' + d.erro); return; }
            if (!d.casos || d.casos.length === 0) { alert('Nenhum caso encontrado pra esse cliente. Crie o caso no Kanban Operacional primeiro.'); return; }
            // Montar modal
            var modal = document.getElementById('waDriveModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'waDriveModal';
                modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';
                document.body.appendChild(modal);
            }
            var html = '<div style="background:#fff;border-radius:14px;padding:1.5rem;max-width:520px;width:100%;box-shadow:0 10px 40px rgba(0,0,0,.3);">';
            html += '<h3 style="margin:0 0 .4rem;color:#0f2140;">📁 Salvar no Google Drive</h3>';
            html += '<p style="font-size:.85rem;color:#6b7280;margin:0 0 1rem;">Escolha em qual caso do cliente esse arquivo deve ser salvo (vai na pasta do Drive do caso):</p>';
            html += '<div style="max-height:300px;overflow-y:auto;display:flex;flex-direction:column;gap:.4rem;">';
            d.casos.forEach(function(c){
                var hasFolder = !!c.drive_folder_url;
                var estilo = hasFolder ? 'cursor:pointer;background:#f9fafb;' : 'background:#fee2e2;cursor:not-allowed;';
                html += '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:.6rem .8rem;'+estilo+'" ';
                if (hasFolder) html += 'onclick="waConfirmarDrive('+msgId+', '+c.id+')"';
                html += '>';
                html += '<div style="font-weight:600;color:#0f2140;">'+escapeHtml(c.client_title||'(sem título)')+'</div>';
                html += '<div style="font-size:.75rem;color:#6b7280;">'+escapeHtml(c.case_type||'')+' · status='+escapeHtml(c.status||'')+'</div>';
                if (!hasFolder) html += '<div style="font-size:.72rem;color:#dc2626;margin-top:2px;">⚠️ Caso sem pasta no Drive — crie no Kanban Operacional primeiro</div>';
                html += '</div>';
            });
            html += '</div>';
            html += '<div style="text-align:right;margin-top:1rem;"><button onclick="document.getElementById(\'waDriveModal\').remove()" style="background:#f3f4f6;border:1px solid #d1d5db;padding:8px 16px;border-radius:8px;cursor:pointer;">Cancelar</button></div>';
            html += '</div>';
            modal.innerHTML = html;
        });
    };
    window.waConfirmarDrive = function(msgId, caseId) {
        var modal = document.getElementById('waDriveModal');
        if (modal) modal.innerHTML = '<div style="background:#fff;padding:2rem;border-radius:12px;"><strong>Salvando no Drive...</strong></div>';
        var fd = new FormData();
        fd.append('action', 'salvar_drive');
        fd.append('mensagem_id', msgId);
        fd.append('case_id', caseId);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            if (modal) modal.remove();
            if (d.error) { alert('⚠️ Falha: ' + d.error); return; }
            alert('✅ Arquivo salvo no Drive!' + (d.fileUrl ? '\n\nURL: ' + d.fileUrl : ''));
            window.waAbrir(convAtiva);
        });
    };

    // ── APAGAR / EDITAR MENSAGEM ─────────────────────────
    window.waDeletarMsg = function(msgId) {
        if (!confirm('Apagar esta mensagem no WhatsApp do cliente também? Esta ação é irreversível.')) return;
        var fd = new FormData();
        fd.append('action', 'deletar_mensagem');
        fd.append('mensagem_id', msgId);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            if (d.error) { alert('Erro: ' + d.error); return; }
            window.waAbrir(convAtiva);
            carregarLista();
        });
    };

    // ⚠️ Z-API não suporta editar de verdade — fazemos "apagar + reenviar com texto pré-preenchido"
    window.waEditarMsg = function(msgId) {
        var row = document.querySelector('.wa-msg-row[data-msg-id="'+msgId+'"] .wa-msg');
        if (!row) return;
        var atual = '';
        row.childNodes.forEach(function(n){
            if (n.nodeType === 1 && (n.className === 'wa-msg-actions' || n.className === 'wa-msg-tag' || n.className === 'wa-msg-time')) return;
            atual += (n.textContent || '');
        });
        atual = atual.trim();
        if (!confirm('Reenvio com edição:\n\n1. A mensagem original será APAGADA no WhatsApp\n2. O texto vai pro campo de envio pra você editar\n3. Você corrige e clica Enviar\n\nContinuar?')) return;
        // 1. Deleta a original
        var fd = new FormData();
        fd.append('action', 'deletar_mensagem');
        fd.append('mensagem_id', msgId);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            if (d.error) { alert('Erro ao apagar original: ' + d.error); return; }
            // 2. Coloca o texto no input pra editar
            document.getElementById('waInput').value = atual;
            document.getElementById('waInput').focus();
            document.getElementById('waInput').setSelectionRange(atual.length, atual.length);
            window.waAbrir(convAtiva);
            carregarLista();
        });
    };
    window.waResolver  = function() { if(confirm('Marcar como resolvida?')) acaoConversa('resolver').then(function(){ window.waAbrir(convAtiva); carregarLista(); }); };
    window.waArquivar  = function() { if(confirm('Arquivar conversa?')) acaoConversa('arquivar').then(function(){ convAtiva=null; location.reload(); }); };
    // ── EDITAR NOME DA CONVERSA ─────────────────────────
    window.waEditarNome = function() {
        var disp = document.getElementById('waNomeDisplay');
        if (!disp) return;
        var atual = disp.textContent;
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'wa-name-edit';
        input.value = atual;
        input.maxLength = 150;
        disp.replaceWith(input);
        input.focus();
        input.select();
        var finalizar = function(salvar){
            if (salvar && input.value.trim() !== atual) {
                var fd = new FormData();
                fd.append('action', 'editar_conversa');
                fd.append('conversa_id', convAtiva);
                fd.append('nome_contato', input.value.trim());
                fd.append('csrf_token', csrf);
                fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(){
                    window.waAbrir(convAtiva);
                    carregarLista();
                });
            } else {
                window.waAbrir(convAtiva);
            }
        };
        input.addEventListener('keydown', function(e){
            if (e.key === 'Enter') { e.preventDefault(); finalizar(true); }
            if (e.key === 'Escape') { e.preventDefault(); finalizar(false); }
        });
        input.addEventListener('blur', function(){ finalizar(true); });
    };

    // ── ETIQUETAS ───────────────────────────────────────
    function carregarEtiquetasCache() {
        return fetch(apiUrl + '?action=listar_etiquetas').then(function(r){ return r.json(); }).then(function(d){
            if (d.ok) etiquetasCache = d.etiquetas;
            return etiquetasCache;
        });
    }
    function renderEtqFilters() {
        if (!etiquetasCache) return;
        var bar = document.getElementById('waEtqFilters');
        var html = '';
        etiquetasCache.forEach(function(et){
            var active = (+etiquetaFiltro === +et.id);
            var style = active ? 'background:'+et.cor+';color:#fff;border-color:'+et.cor+';' : '';
            html += '<button class="wa-etq-filter '+(active?'active':'')+'" style="'+style+'" onclick="waFiltrarPorEtiqueta('+et.id+')">' + escapeHtml(et.nome) + '</button>';
        });
        if (etiquetaFiltro) html += '<button class="wa-etq-filter" onclick="waFiltrarPorEtiqueta(0)" style="color:#ef4444;">✕ Limpar</button>';
        bar.innerHTML = html;
    }
    window.waFiltrarPorEtiqueta = function(id) {
        etiquetaFiltro = +id;
        renderEtqFilters();
        carregarLista();
    };
    window.waToggleEtiquetas = function(ev) {
        ev.stopPropagation();
        var pop = document.getElementById('waEtqPopover');
        if (!pop) return;
        if (pop.classList.contains('open')) { pop.classList.remove('open'); return; }
        // Popover abaixo do botão
        var rect = ev.target.getBoundingClientRect();
        var parentRect = pop.parentElement.getBoundingClientRect();
        pop.style.top  = (rect.bottom - parentRect.top + 4) + 'px';
        pop.style.right = '8px';
        fetch(apiUrl + '?action=listar_etiquetas&conversa_id=' + convAtiva).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok) return;
            var html = '<div style="font-size:.7rem;color:#6b7280;font-weight:700;margin-bottom:4px;padding:0 4px;">CLIQUE PARA APLICAR/REMOVER</div>';
            d.etiquetas.forEach(function(et){
                var check = +et.aplicada ? '✅' : '';
                html += '<div class="wa-etq-opt" onclick="waToggleEtq('+et.id+', '+(+et.aplicada?1:0)+')">';
                html += '<span class="wa-etiqueta" style="background:'+escapeHtml(et.cor)+';">'+escapeHtml(et.nome)+'</span>';
                html += '<span style="margin-left:auto;">'+check+'</span>';
                html += '</div>';
            });
            html += '<div style="border-top:1px solid #eee;margin-top:4px;padding-top:4px;"><a href="<?= module_url('whatsapp', 'etiquetas.php') ?>" target="_blank" style="font-size:.72rem;color:#6b7280;">+ Gerenciar etiquetas</a></div>';
            pop.innerHTML = html;
            pop.classList.add('open');
        });
    };
    window.waToggleEtq = function(etqId, aplicada) {
        var action = aplicada ? 'remover_etiqueta' : 'adicionar_etiqueta';
        var fd = new FormData();
        fd.append('action', action);
        fd.append('conversa_id', convAtiva);
        fd.append('etiqueta_id', etqId);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(){
            window.waAbrir(convAtiva);
            carregarLista();
            document.getElementById('waEtqPopover').classList.remove('open');
        });
    };
    window.waRemoverEtiqueta = function(etqId) {
        var fd = new FormData();
        fd.append('action', 'remover_etiqueta');
        fd.append('conversa_id', convAtiva);
        fd.append('etiqueta_id', etqId);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(){
            window.waAbrir(convAtiva);
            carregarLista();
        });
    };
    // Fecha popover ao clicar fora
    document.addEventListener('click', function(e){
        var pop = document.getElementById('waEtqPopover');
        if (pop && !e.target.closest('#waEtqPopover') && !e.target.closest('.wa-chat-actions')) {
            pop.classList.remove('open');
        }
    });

    window.waSincronizar = function() {
        alert('⚠️ Limitação da Z-API\n\nA Z-API não permite baixar o histórico do WhatsApp na versão Multi Device (que é a única disponível hoje).\n\nTodas as mensagens NOVAS (após a configuração do webhook) são capturadas em tempo real — essas ficam salvas aqui para sempre.\n\nMensagens anteriores só ficam no WhatsApp Web ou no celular.');
    };

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
        // Substituir variáveis antes de colar no input
        var primeiroNome = (convNomeAtual || '').split(/\s+/)[0] || '';
        var agora = new Date();
        var dataStr = agora.toLocaleDateString('pt-BR');
        var horaStr = agora.toTimeString().substr(0,5);
        txt = txt.replace(/\{\{nome\}\}/gi, primeiroNome);
        txt = txt.replace(/\{\{data\}\}/gi, dataStr);
        txt = txt.replace(/\{\{hora\}\}/gi, horaStr);
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

    // ── IMPORTAR TODAS AS CONVERSAS ANTIGAS ─────────────
    window.waImportarTodas = function() {
        var q = prompt('Quantos contatos importar do WhatsApp?\n(padrão 200, máximo 500)\n\nObs: por limitação da Z-API Multi Device, só vêm os contatos/telefones — as mensagens antigas não. Mensagens futuras são capturadas em tempo real.', '200');
        if (q === null) return;
        var max = Math.min(Math.max(parseInt(q, 10) || 200, 1), 500);
        var btn = event.target;
        btn.disabled = true; btn.textContent = 'Importando...';
        var fd = new FormData();
        fd.append('action', 'importar_todos');
        fd.append('ddd', canal);
        fd.append('max_chats', max);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            btn.disabled = false; btn.textContent = '👥 Importar contatos';
            if (d.error) { alert('Erro: ' + d.error); return; }
            alert('Importado!\n\nContatos novos: ' + d.conversas + '\nGrupos/inválidos pulados: ' + (d.pulados || 0));
            carregarLista();
        }).catch(function(e){
            btn.disabled = false; btn.textContent = '👥 Importar contatos';
            alert('Falha: ' + e);
        });
    };

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

    // Carrega etiquetas ativas para a barra de filtros
    carregarEtiquetasCache().then(renderEtqFilters);

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
