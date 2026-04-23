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

// Etiqueta "🔓 AT DESBLOQUEADO" — atalho de filtro (só faz sentido no canal 21)
$etqAtDesbloqueadoId = $isComercial ? (int)_zapi_etiqueta_at_desbloqueado_id() : 0;

$csrfToken = generate_csrf_token();
$podeDelegar = can_delegar_whatsapp(); // só Amanda (1) e Luiz Eduardo (6)

// Nome de atendimento do usuário logado (custom ou "primeiro + último")
$meuDisplayName = user_display_name();
$meuDisplayCustom = '';
try {
    // Auto-heal + pega o custom atual
    try { db()->exec("ALTER TABLE users ADD COLUMN wa_display_name VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    $st = db()->prepare("SELECT wa_display_name FROM users WHERE id = ?");
    $st->execute(array($user['id']));
    $meuDisplayCustom = (string)$st->fetchColumn();
} catch (Exception $e) {}

// Self-heal: coluna wa_color pra cor configurada por atendente (borda esquerda das conversas)
try { $pdo->exec("ALTER TABLE users ADD COLUMN wa_color VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}

// Lista de usuários ativos (pra filtro de atendente e dropdown de delegação)
$usuariosAtivos = array();
try {
    $usuariosAtivos = $pdo->query("SELECT id, name, wa_color FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
} catch (Exception $e) {}

// Mapa de cores { user_id: '#rrggbb' } — só entra se wa_color setado
$atendentesCoresMap = array();
foreach ($usuariosAtivos as $_u) {
    if (!empty($_u['wa_color'])) $atendentesCoresMap[(int)$_u['id']] = $_u['wa_color'];
}

// Converte cor hex → emoji de bullet colorido (pra usar como prefix em <option>)
// Matching por proximidade aproximada do hue
function wa_cor_para_emoji($hex) {
    if (!$hex) return '⚪';
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '⚪';
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    if ($max - $min < 25 && $max > 200) return '⚪';
    if ($max < 80) return '⚫';
    if ($r > 200 && $g < 100 && $b < 100) return '🔴';
    if ($r < 150 && $g > 150 && $b < 150) return '🟢';
    if ($r < 150 && $g < 150 && $b > 180) return '🔵';
    if ($r > 200 && $g > 200 && $b < 100) return '🟡';
    if ($r > 200 && $g > 100 && $b < 100) return '🟠';
    if ($r > 150 && $b > 150 && $g < 150) return '🟣';
    if ($r > 200 && $g > 100 && $b > 150) return '🩷';
    if ($r > 100 && $g > 50 && $b < 50) return '🟤';
    if ($r < 150 && $g > 150 && $b > 150) return '🩵';
    return '⚫';
}

// Config: mostrar nome do atendente no chat interno (default: sim)
$mostrarNomeAtendente = '1';
try {
    $r = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'zapi_mostrar_nome_interno'")->fetchColumn();
    if ($r !== false && $r !== null) $mostrarNomeAtendente = $r;
} catch (Exception $e) {}

// Config: assinatura automática (nome no WhatsApp do cliente — externo)
$assinaturaLigada = '0';
try {
    $r2 = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'zapi_signature_on'")->fetchColumn();
    if ($r2 !== false && $r2 !== null) $assinaturaLigada = $r2;
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
.wa-conv { padding:.6rem .8rem;border-bottom:1px solid var(--border);cursor:pointer;display:flex;gap:.5rem;align-items:flex-start;transition:background .15s; }
.wa-conv:hover { background:<?= $accentLight ?>; }
.wa-conv.active { background:<?= $accentLight ?>;border-left:3px solid <?= $accentColor ?>; }
/* Estado visual por status */
.wa-conv[data-status="resolvido"] { background:#f1f5f9;opacity:.72; }
.wa-conv[data-status="resolvido"] .wa-conv-name { color:#64748b;text-decoration:line-through;text-decoration-color:rgba(100,116,139,.5); }
.wa-conv[data-status="resolvido"] .wa-conv-preview { font-style:italic; }
.wa-conv[data-status="aguardando"] { background:rgba(251,191,36,.08); }
.wa-conv[data-status="aguardando"] .wa-conv-name { color:#b45309; }
.wa-conv[data-status="em_atendimento"] { background:rgba(5,150,105,.04); }
.wa-conv[data-status="bot_ativo"] { background:rgba(139,92,246,.06); }
.wa-conv.mine { border-right:3px solid #059669; }
.wa-conv-status-pill { display:inline-block;padding:1px 6px;border-radius:8px;font-size:.58rem;font-weight:700;margin-left:4px;vertical-align:middle;letter-spacing:.3px;text-transform:uppercase; }
.wa-conv-status-pill.resolvido { background:#64748b;color:#fff; }
.wa-conv-status-pill.aguardando { background:#f59e0b;color:#fff; }
.wa-conv-status-pill.em_atendimento { background:#059669;color:#fff; }
.wa-conv-status-pill.bot { background:#7c3aed;color:#fff; }
/* Dropdown slash-command (/) */
.wa-slash-item:last-child { border-bottom:none !important; }
.wa-slash-item.selected { background:<?= $accentLight ?>;border-left:3px solid <?= $accentColor ?>; }
.wa-slash-item:hover { background:<?= $accentLight ?>; }
.wa-avatar { width:36px;height:36px;border-radius:50%;background:<?= $accentColor ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0;overflow:hidden;position:relative; }
.wa-avatar img { width:100%;height:100%;object-fit:cover;border-radius:50%;display:block; }
.wa-conv-info { flex:1;min-width:0; }
.wa-conv-name { font-weight:600;font-size:.82rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.wa-conv-preview { font-size:.72rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px; }
.wa-conv-meta { text-align:right;font-size:.65rem;color:var(--text-muted);flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:3px; }
.wa-unread { background:#ef4444;color:#fff;border-radius:10px;padding:1px 7px;font-weight:700;font-size:.65rem; }
.wa-bot-badge { background:#7c3aed;color:#fff;padding:1px 5px;border-radius:4px;font-size:.6rem;margin-left:4px; }
.wa-empty { padding:2rem 1rem;text-align:center;color:var(--text-muted);font-size:.85rem; }
.wa-chat-empty { display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--text-muted);text-align:center;padding:2rem; }
.wa-chat-empty-ico { font-size:3rem;margin-bottom:.5rem;opacity:.4; }
.wa-chat-head { padding:.6rem .9rem;background:#f9fafb;color:var(--text);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem; }
.wa-chat-head strong { font-size:.9rem; }
.wa-chat-head .wa-name-display { font-weight:700;font-size:1rem;color:var(--petrol-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;cursor:pointer; }
.wa-chat-head .wa-head-sub { font-size:.75rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block; }
/* Container dos botões: scroll horizontal quando não couber, sem wrap (mantém header compacto) */
.wa-chat-actions { margin-left:auto;display:flex;gap:.3rem;flex-shrink:0;overflow-x:auto;max-width:60%;padding:2px 0;scrollbar-width:thin; }
.wa-chat-actions::-webkit-scrollbar { height:5px; }
.wa-chat-actions::-webkit-scrollbar-thumb { background:#cbd5e1;border-radius:3px; }
.wa-chat-actions button { padding:5px 10px;font-size:.72rem;border:1px solid var(--border);background:#fff;border-radius:6px;cursor:pointer;color:var(--text);white-space:nowrap;flex-shrink:0;font-weight:600; }
.wa-chat-actions button:hover { background:<?= $accentLight ?>;border-color:<?= $accentColor ?>; }
.wa-chat-actions .btn-primary-sm { background:<?= $accentColor ?>;color:#fff;border-color:<?= $accentColor ?>; }
@media (max-width:900px) {
    .wa-chat-actions { max-width:100%;margin-left:0;margin-top:4px;width:100%; }
    .wa-chat-head { flex-wrap:wrap; }
}
.wa-chat-body { flex:1;overflow-y:auto;padding:1rem;background:#faf8f5; }
.wa-msg-row { display:flex;margin-bottom:.4rem; }
.wa-msg-row.left { justify-content:flex-start; }
.wa-msg-row.right { justify-content:flex-end; }
.wa-msg { max-width:70%;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:.5rem .75rem;font-size:.83rem;box-shadow:0 1px 2px rgba(0,0,0,.04); }
.wa-msg.sent { background:<?= $accentLight ?>;border-color:<?= $accentColor ?>; }
.wa-msg.bot  { background:#ede9fe;border-color:#c4b5fd; }
.wa-msg.deleted { opacity:.5;font-style:italic; }
.wa-msg:hover .wa-msg-actions { display:flex !important; }
.wa-msg-actions button { background:#fff !important; border:1px solid #d1d5db !important; border-radius:6px !important; width:28px !important; height:28px !important; font-size:.95rem !important; cursor:pointer; padding:0 !important; box-shadow:0 2px 6px rgba(0,0,0,.12); transition:transform .12s; }
.wa-msg-actions button:hover { transform:scale(1.12); background:#f9fafb !important; }
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
    <div style="margin-left:auto;display:flex;gap:.4rem;flex-wrap:wrap;justify-content:flex-end;">
        <button onclick="waAbrirNovaConversa()" class="btn btn-primary btn-sm" style="background:#B87333;" title="Iniciar nova conversa com cliente ou número novo">➕ Nova conversa</button>
        <a href="<?= url('modules/whatsapp/?canal=' . ($isComercial ? '24' : '21')) ?>" class="btn btn-outline btn-sm">
            Ir para <?= $isComercial ? 'DDD 24 (CX)' : 'DDD 21 (Comercial)' ?> →
        </a>
        <?php if (has_min_role('gestao')): ?>
            <button onclick="waImportarTodas()" class="btn btn-outline btn-sm" title="Importar lista de contatos (Multi Device não permite baixar mensagens antigas)">👥 Importar contatos</button>
            <button onclick="waAtualizarFotos(this)" class="btn btn-outline btn-sm" title="Busca foto de perfil do WhatsApp de cada contato. Se for cliente sem foto, salva no cadastro dele.">🖼️ Atualizar fotos</button>
            <button onclick="waAbrirMeuNome()" class="btn btn-outline btn-sm" title="Editar o nome que aparece acima das suas mensagens / na assinatura enviada ao cliente">✍️ Meu nome</button>
            <?php if (has_min_role('gestao')): ?>
            <button onclick="waToggleNomes(this)" id="btnToggleNomes"
                    class="btn btn-outline btn-sm"
                    title="Mostrar/ocultar o nome do atendente acima de cada mensagem — só afeta o chat INTERNO do Hub (equipe), não aparece pro cliente"
                    style="<?= $mostrarNomeAtendente === '1' ? 'background:#059669;color:#fff;border-color:#059669;' : 'background:#fff;color:#6b7280;' ?>">
                <?= $mostrarNomeAtendente === '1' ? 'Equipe vê quem atendeu: ON' : 'Equipe vê quem atendeu: OFF' ?>
            </button>
            <button onclick="waToggleAssinatura(this)" id="btnToggleAssinatura"
                    class="btn btn-outline btn-sm"
                    title="Liga/desliga a assinatura '— Nome' no FIM de cada mensagem enviada ao cliente. ISTO É O QUE O CLIENTE VÊ NO CELULAR."
                    style="<?= $assinaturaLigada === '1' ? 'background:#1e40af;color:#fff;border-color:#1e40af;' : 'background:#fff;color:#6b7280;' ?>">
                <?= $assinaturaLigada === '1' ? '📱 Cliente vê assinatura: ON' : '📱 Cliente vê assinatura: OFF' ?>
            </button>
            <?php endif; ?>
            <?php if (has_min_role('admin')): ?>
            <button onclick="waAbrirCoresAtendentes()" class="btn btn-outline btn-sm" title="Escolher uma cor pra cada atendente (borda da conversa)">🎨 Cores</button>
            <?php endif; ?>
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
                <button class="wa-filter" data-filter="arquivado" title="Ver conversas arquivadas (ficam ocultas por padrão)">📦 Arquiv.</button>
                <?php if ($etqAtDesbloqueadoId): ?>
                <button class="wa-filter" id="waBtnAtDesbloqueado" onclick="waFiltrarAtDesbloqueado()" style="background:#fef2f2;border-color:#dc2626;color:#991b1b;font-weight:700;" title="Leads com atendente ausente há mais de 30 min — precisam de resposta">🔓 AT Desbloq.</button>
                <?php endif; ?>
                <button class="wa-filter" id="waBtnFiltroEtq" onclick="waToggleFiltroEtqPopover(event)" style="position:relative;">🏷 Etiqueta</button>
                <select id="waFiltroAtendente" onchange="waSetFiltroAtendente(this.value)" class="wa-filter" style="padding:4px 8px;cursor:pointer;font-weight:700;" title="Filtrar por atendente — nome vem na cor configurada">
                    <option value="" style="color:#6b7280;font-weight:400;">👥 Atendente</option>
                    <option value="-1" style="color:#6b7280;font-weight:400;">👤 Minhas</option>
                    <option value="0" style="color:#6b7280;font-weight:400;">⚪ Sem atendente</option>
                    <?php foreach ($usuariosAtivos as $u):
                        $_cor = !empty($u['wa_color']) ? $u['wa_color'] : '#6b7280';
                    ?>
                        <option value="<?= (int)$u['id'] ?>" style="color:<?= e($_cor) ?>;font-weight:700;"><?= e(explode(' ', $u['name'])[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Chip da etiqueta selecionada (aparece só quando filtro ativo) -->
            <div id="waEtqChipAtivo" style="display:none;align-items:center;gap:6px;"></div>
            <!-- Popover com lista de etiquetas (escondido por default) -->
            <div id="waEtqPopoverFiltro" style="display:none;position:absolute;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:.5rem;z-index:300;min-width:200px;max-height:260px;overflow-y:auto;"></div>
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

        <!-- Dropdown de autocomplete slash (/) — pra escolher respostas rápidas digitando -->
        <div id="waSlashDrop" style="display:none;position:fixed;z-index:9999;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);width:380px;max-width:90vw;max-height:280px;overflow-y:auto;"></div>

        <!-- Barra de resposta (aparece quando clica em ↩ Responder numa mensagem) -->
        <div id="waReplyBar" style="display:none;align-items:center;gap:.5rem;padding:.45rem .75rem;background:#eff6ff;border-top:1px solid #bfdbfe;border-left:3px solid #2563eb;">
            <span style="font-size:.85rem;">↩</span>
            <div style="flex:1;min-width:0;">
                <div style="font-size:.68rem;color:#1e40af;font-weight:700;">Respondendo a <span id="waReplyQuem">...</span></div>
                <div id="waReplyPreview" style="font-size:.76rem;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
            </div>
            <button type="button" onclick="waCancelarResposta()" title="Cancelar resposta" style="background:transparent;border:1px solid #93c5fd;color:#1e40af;border-radius:6px;padding:2px 8px;cursor:pointer;font-size:.78rem;">✕</button>
        </div>

        <!-- Input de mensagem (escondido até abrir uma conversa) -->
        <div class="wa-chat-input" id="waChatInput" style="display:none;">
            <button class="wa-btn-tpl" onclick="waToggleTemplates()" title="Respostas rápidas">📋</button>
            <button class="wa-btn-tpl" onclick="document.getElementById('waFile').click()" title="Anexar imagem ou documento">📎</button>
            <button class="wa-btn-tpl" onclick="document.getElementById('waSticker').click()" title="Enviar figurinha (.webp / imagem)">🎭</button>
            <button class="wa-btn-tpl" id="waBtnMic" onclick="waGravarAudio()" title="Gravar áudio">🎤</button>
            <input type="file" id="waFile" style="display:none;" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar">
            <input type="file" id="waSticker" style="display:none;" accept="image/webp,image/png,image/jpeg,image/gif" onchange="waEnviarSticker(this.files[0])">
            <textarea id="waInput" placeholder="Digite uma mensagem ou cole uma imagem (Ctrl+V)..." rows="1"></textarea>
            <!-- Barra de gravação (mostra no lugar do textarea enquanto grava) -->
            <div id="waRecBar" style="display:none;flex:1;align-items:center;gap:8px;padding:8px 10px;border:1px solid #ef4444;border-radius:8px;background:#fef2f2;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;animation:waPulse 1s infinite;"></span>
                <span style="font-size:.85rem;color:#991b1b;font-weight:600;">Gravando...</span>
                <span id="waRecTimer" style="font-size:.85rem;color:#991b1b;font-variant-numeric:tabular-nums;">00:00</span>
                <button onclick="waCancelarGravacao()" style="margin-left:auto;background:#fff;border:1px solid #fca5a5;color:#991b1b;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:.8rem;" title="Cancelar">✕ Cancelar</button>
            </div>
            <button class="wa-btn-send" id="waBtnSend" onclick="waEnviar()">➤ Enviar</button>
        </div>
        <style>@keyframes waPulse { 0%,100%{opacity:1}50%{opacity:.3} }</style>
    </div>
</div>

<script>
(function(){
    var canal  = '<?= e($canal) ?>';
    var apiUrl = '<?= module_url('whatsapp', 'api.php') ?>';
    var csrf   = '<?= e($csrfToken) ?>';
    // Status inicial vem da URL (?status=aguardando|em_atendimento|bot|nao_lidas|resolvido)
    // Útil pra linkar direto do Dashboard já filtrado.
    var filtroAtual = <?= json_encode(in_array($_GET['status'] ?? '', array('aguardando','em_atendimento','bot','nao_lidas','resolvido'), true) ? $_GET['status'] : 'todos') ?>;
    var buscaAtual  = '';
    var etiquetaFiltro = 0;
    var ETQ_AT_DESBLOQUEADO_ID = <?= (int)$etqAtDesbloqueadoId ?>;
    var atendenteFiltro = ''; // '' = todos, -1 = minhas, 0 = sem atendente, N = user id N
    var convAtiva   = null;
    var pollTimer   = null;
    var templatesCache = null;
    var etiquetasCache = null;
    var arquivoPendente = null; // {file, previewUrl}
    var convNomeAtual = ''; // nome do contato da conversa aberta (pra {{nome}})
    var PODE_DELEGAR = <?= $podeDelegar ? 'true' : 'false' ?>;
    var USUARIOS = <?= json_encode(array_map(function($u){ return array('id'=>(int)$u['id'],'name'=>$u['name']); }, $usuariosAtivos), JSON_UNESCAPED_UNICODE) ?>;
    var MEU_USER_ID = <?= (int)$user['id'] ?>;
    var MEU_NOME_ATUAL  = <?= json_encode($meuDisplayName, JSON_UNESCAPED_UNICODE) ?>; // nome já exibido (custom ou auto)
    var MEU_NOME_CUSTOM = <?= json_encode($meuDisplayCustom, JSON_UNESCAPED_UNICODE) ?>; // override salvo (ou '')
    var MEU_NOME_COMPLETO = <?= json_encode($user['name'] ?? '', JSON_UNESCAPED_UNICODE) ?>;

    // Cores manuais configuradas pelo admin (sobrepõem o hash automático)
    var WA_CORES_ATENDENTES = <?= json_encode((object)$atendentesCoresMap) ?>;

    // Retorna cor manual (se configurada) ou cor determinística por hash
    function corAtendente(userId) {
        if (!userId) return null;
        if (WA_CORES_ATENDENTES[userId]) return WA_CORES_ATENDENTES[userId];
        var h = 0, s = String(userId);
        for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) & 0xffff;
        var hue = h % 360;
        return 'hsl(' + hue + ', 60%, 50%)';
    }
    var mostrarNomeAtendente = <?= $mostrarNomeAtendente === '1' ? 'true' : 'false' ?>; // config

    function escapeHtml(s) { return (s||'').replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function iniciais(n) { if(!n) return '?'; var p=n.trim().split(/\s+/); return (p[0][0]+(p[1]?p[1][0]:'')).toUpperCase(); }
    // Renderiza HTML do avatar: grupo → 👥; senão prefere foto da Central VIP > foto do WhatsApp > iniciais.
    // onerror restaura iniciais se a imagem falhar (ex: URL Z-API expirada).
    function avatarHtml(c, nome) {
        if (c && +c.eh_grupo) return '👥';
        var src = '';
        // Prioridade: 1) foto do cliente cadastrado > 2) foto local da conversa
        // (permanente) > 3) URL temporária Z-API (expira em 48h, pode dar 404)
        if (c && c.client_foto_path) {
            src = '<?= url('/') ?>salavip/uploads/' + encodeURIComponent(c.client_foto_path);
        } else if (c && c.foto_perfil_local) {
            src = '<?= url('files/wa_fotos/') ?>' + encodeURIComponent(c.foto_perfil_local);
        } else if (c && c.foto_perfil_url) {
            src = c.foto_perfil_url;
        }
        if (!src) return iniciais(nome);
        var ini = iniciais(nome).replace(/'/g, "\\'");
        return '<img src="' + src + '" alt="" onerror="this.parentNode.innerHTML=\'' + ini + '\';">';
    }
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
    window.waSetFiltroAtendente = function(v) {
        atendenteFiltro = v;
        carregarLista();
    };

    function carregarLista() {
        var url = apiUrl + '?action=listar_conversas&canal=' + canal + '&status=' + filtroAtual + '&q=' + encodeURIComponent(buscaAtual);
        if (atendenteFiltro !== '') url += '&atendente=' + encodeURIComponent(atendenteFiltro);
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
                // Status visual: prioriza bot ativo quando for o caso
                var statusVis = c.status || 'aguardando';
                if (+c.bot_ativo && statusVis !== 'resolvido') statusVis = 'bot_ativo';
                // Se o usuário logado é o atendente, marca 'mine' (borda direita verde)
                var ehMinha = (+c.atendente_id === MEU_USER_ID) ? 'mine' : '';
                // Borda esquerda com cor do atendente (se houver); se delegada, bordas mais espessa.
                var cor = corAtendente(c.atendente_id);
                var borderStyle = '';
                if (cor) {
                    var espessura = +c.delegada ? '5px' : '3px';
                    borderStyle = 'border-left:' + espessura + ' solid ' + cor + ';';
                }
                html += '<div class="wa-conv '+isActive+' '+ehMinha+'" data-id="'+c.id+'" data-status="'+statusVis+'" style="'+borderStyle+'" onclick="waAbrir('+c.id+')">';
                html += '  <div class="wa-avatar">' + avatarHtml(c, nome) + '</div>';
                html += '  <div class="wa-conv-info">';
                html += '    <div class="wa-conv-name">';
                if (+c.fixada) html += '<span title="Conversa fixada" style="color:#B87333;margin-right:3px;">📌</span>';
                html += escapeHtml(nome);
                // Pill de status: aparece se resolvido, aguardando, em_atendimento ou bot
                var pillMap = { resolvido:'✓ Resolvido', aguardando:'⏳ Aguard.', em_atendimento:'● Em atend.', bot_ativo:'🤖 Bot' };
                if (pillMap[statusVis]) {
                    var pillCls = statusVis === 'bot_ativo' ? 'bot' : statusVis;
                    html += ' <span class="wa-conv-status-pill '+pillCls+'">' + pillMap[statusVis] + '</span>';
                } else if (+c.bot_ativo) {
                    html += '<span class="wa-bot-badge">🤖 BOT</span>';
                }
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
        var souEuAtendente = (+c.atendente_id === <?= (int)$user['id'] ?>);
        var estaDelegada = !!(+c.delegada);
        var podeEnviar   = (+c.lock_pode_enviar === 1); // backend decidiu: livre, eu sou atendente, ou admin

        // Assumir só aparece quando a conversa está LIVRE pra mim:
        // - sem atendente (lock_pode_enviar=1 e atendente_id vazio)
        // - ou passou 30 minutos sem atividade (lock já destravou)
        // - ou sou admin (PODE_DELEGAR bypassa)
        // Se outro atendente já assumiu e há atividade recente, o botão some —
        // só Amanda/Luiz podem realocar via "Delegar".
        if (!souEuAtendente && podeEnviar) {
            actions += '<button class="btn-primary-sm" onclick="waAssumir()">👤 Assumir</button>';
        }
        // Delegação disponível em ambos os canais — Amanda/Luiz podem delegar qualquer conversa.
        // No canal 24 (colaborativo), delegar também bloqueia os outros atendentes até destravar.
        if (PODE_DELEGAR) {
            var tipTxt = c.canal === '24'
                ? 'Delegar para outro atendente. Lembrete: o canal 24 é colaborativo — ao delegar, os outros atendentes ficam bloqueados de enviar até destravar (ou 30min sem atividade).'
                : 'Delegar para outro atendente (trava para que só ele possa assumir). Se ficar 30 minutos sem interação, destrava automaticamente.';
            actions += '<button onclick="waAbrirDelegar()" style="background:#7c3aed;color:#fff;border-color:#7c3aed;" title="' + tipTxt + '">🎯 Delegar</button>';
            if (estaDelegada) {
                actions += '<button onclick="waRemoverDelegacao()" style="background:#fee2e2;border-color:#fca5a5;color:#991b1b;" title="Remover delegação (libera pra qualquer um assumir)">🔓 Destravar</button>';
            }
        }
        // Mesclar é util em ambos os canais (duplicatas Multi-Device acontecem nos 2)
        if (PODE_DELEGAR) {
            actions += '<button onclick="waAbrirMesclar()" title="Mesclar esta conversa com outra do mesmo contato (útil pra casos de duplicata por Multi-Device / @lid)">🔗 Mesclar</button>';
        }
        // Fixar/desfixar conversa no topo da lista
        if (+c.fixada) {
            actions += '<button onclick="waPinConversa()" style="background:#B87333;color:#fff;border-color:#B87333;" title="Desfixar conversa do topo">📌 Fixada</button>';
        } else {
            actions += '<button onclick="waPinConversa()" title="Fixar esta conversa no topo da lista (só aparece pra você e equipe no Hub)">📌 Fixar</button>';
        }
        if (c.status !== 'resolvido') {
            actions += '<button onclick="waResolver()">✅ Resolver</button>';
        }
        actions += '<button onclick="waCriarChamado()" title="Abrir chamado no Helpdesk vinculado a este cliente">📋 Chamado</button>';
        if (c.client_id) actions += '<button onclick="waAbrirProcesso(' + c.client_id + ')" title="Abrir a pasta do processo vinculado a este cliente" style="background:#B87333;color:#fff;border-color:#B87333;">⚖️ Processo</button>';
        if (c.client_id) actions += '<button onclick="waEnviarLinkPortal()" title="Gerar novo link de ativação da Central VIP e enviar por WhatsApp" style="background:#6366f1;color:#fff;border-color:#6366f1;">🔑 Portal</button>';
        actions += '<button onclick="waArquivar()" title="Arquivar">🗄</button>';
        actions += '</div>';

        var subTxt = formatTel(c.telefone);
        if (c.atendente_name) {
            var atLabel = c.atendente_name;
            if (+c.delegada) atLabel = '🎯 ' + atLabel + ' (delegada)';
            subTxt += ' · 👤 ' + atLabel;
        }
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

        // Card de mensagens fixadas (no topo do chat, antes das mensagens normais)
        var pinnedHtml = '';
        if (d.fixadas && d.fixadas.length) {
            pinnedHtml = '<div style="background:#fef3c7;border:1px solid #fbbf24;border-left:4px solid #B87333;border-radius:8px;padding:8px 12px;margin-bottom:12px;">'
                       + '<div style="font-size:.7rem;font-weight:700;color:#92400e;margin-bottom:4px;">📌 ' + d.fixadas.length + ' mensagem(ns) fixada(s)</div>';
            d.fixadas.forEach(function(f) {
                var preview = (f.conteudo || '').substring(0, 140);
                if (f.conteudo && f.conteudo.length > 140) preview += '...';
                pinnedHtml += '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-top:1px dashed #fde68a;cursor:pointer;" onclick="waScrollToMsg(' + f.id + ')">'
                           +  '<span style="font-size:.72rem;color:#78350f;flex:1;">' + (f.direcao === 'recebida' ? '👤' : '📤') + ' ' + escapeHtml(preview) + '</span>'
                           +  '<button onclick="event.stopPropagation();waPinMsg(' + f.id + ')" title="Desfixar" style="background:#fbbf24;border:none;color:#fff;border-radius:4px;font-size:.7rem;padding:2px 6px;cursor:pointer;">✕</button>'
                           +  '</div>';
            });
            pinnedHtml += '</div>';
        }

        if (!d.mensagens.length) {
            body.innerHTML = pinnedHtml + '<div class="wa-chat-empty"><div class="wa-chat-empty-ico">📭</div><div>Nenhuma mensagem ainda.</div></div>';
        } else {
            var html = pinnedHtml;
            d.mensagens.forEach(function(m){
                var dir = m.direcao === 'recebida' ? 'left' : 'right';
                var cls = 'wa-msg';
                if (m.direcao === 'enviada') cls += ' sent';
                if (+m.enviado_por_bot) cls += ' bot';
                if (m.status === 'deletada') cls += ' deleted';
                html += '<div class="wa-msg-row '+dir+'" data-msg-id="'+m.id+'">';
                html += '<div class="'+cls+'" style="position:relative;">';
                // Botões hover (apagar/editar/responder) só pra mensagens enviadas pelo Hub e não deletadas
                if (m.direcao === 'enviada' && m.status !== 'deletada' && m.zapi_message_id) {
                    html += '<div class="wa-msg-actions" style="position:absolute;top:2px;right:2px;display:none;gap:3px;">';
                    html += '<button onclick="waResponderMsg(\''+(m.zapi_message_id||'')+'\','+m.id+')" title="Responder a esta mensagem" style="background:rgba(255,255,255,.9);border:1px solid #e5e7eb;border-radius:4px;width:22px;height:22px;font-size:.75rem;cursor:pointer;padding:0;">↩️</button>';
                    html += '<button onclick="waAbrirReacaoPicker(this,'+m.id+')" title="Reagir" style="background:rgba(255,255,255,.9);border:1px solid #e5e7eb;border-radius:4px;width:22px;height:22px;font-size:.75rem;cursor:pointer;padding:0;">😀</button>';
                    if (m.tipo === 'texto') html += '<button onclick="waEditarMsg('+m.id+')" title="Editar (até 15 min)" style="background:rgba(255,255,255,.9);border:1px solid #e5e7eb;border-radius:4px;width:22px;height:22px;font-size:.7rem;cursor:pointer;padding:0;">✏️</button>';
                    html += '<button onclick="waPinMsg('+m.id+')" title="'+(+m.pinned?'Desfixar':'Fixar no topo')+'" style="background:'+(+m.pinned?'#fef3c7':'rgba(255,255,255,.9)')+';border:1px solid '+(+m.pinned?'#fbbf24':'#e5e7eb')+';border-radius:4px;width:22px;height:22px;font-size:.7rem;cursor:pointer;padding:0;">📌</button>';
                    html += '<button onclick="waDeletarMsg('+m.id+')" title="Apagar" style="background:rgba(255,255,255,.9);border:1px solid #e5e7eb;border-radius:4px;width:22px;height:22px;font-size:.7rem;cursor:pointer;padding:0;">🗑</button>';
                    html += '</div>';
                }
                // Hover de reagir/responder/fixar também pra mensagens recebidas
                if (m.direcao === 'recebida' && m.status !== 'deletada' && m.zapi_message_id) {
                    html += '<div class="wa-msg-actions" style="position:absolute;top:2px;right:2px;display:none;gap:3px;">';
                    html += '<button onclick="waResponderMsg(\''+(m.zapi_message_id||'')+'\','+m.id+')" title="Responder a esta mensagem" style="background:rgba(255,255,255,.9);border:1px solid #e5e7eb;border-radius:4px;width:22px;height:22px;font-size:.75rem;cursor:pointer;padding:0;">↩️</button>';
                    html += '<button onclick="waAbrirReacaoPicker(this,'+m.id+')" title="Reagir" style="background:rgba(255,255,255,.9);border:1px solid #e5e7eb;border-radius:4px;width:22px;height:22px;font-size:.75rem;cursor:pointer;padding:0;">😀</button>';
                    html += '<button onclick="waPinMsg('+m.id+')" title="'+(+m.pinned?'Desfixar':'Fixar no topo')+'" style="background:'+(+m.pinned?'#fef3c7':'rgba(255,255,255,.9)')+';border:1px solid '+(+m.pinned?'#fbbf24':'#e5e7eb')+';border-radius:4px;width:22px;height:22px;font-size:.7rem;cursor:pointer;padding:0;">📌</button>';
                    html += '</div>';
                }

                // Citação (quoted) — mensagem respondida aparece em bloco acima
                if (m.reply_to_conteudo) {
                    var qlabel = (m.reply_to_direcao === 'enviada') ? 'Você' : 'Contato';
                    var qcolor = (m.reply_to_direcao === 'enviada') ? '#059669' : '#6366f1';
                    var preview = String(m.reply_to_conteudo).substring(0, 120);
                    html += '<div style="border-left:3px solid '+qcolor+';background:rgba(0,0,0,.03);padding:3px 8px;margin-bottom:4px;border-radius:4px;font-size:.75rem;">'
                          + '<div style="font-weight:700;color:'+qcolor+';font-size:.68rem;">↩ ' + qlabel + '</div>'
                          + '<div style="color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(preview) + '</div>'
                          + '</div>';
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
                        html += '<audio src="'+escapeHtml(m.arquivo_url)+'" controls style="width:240px;margin-bottom:4px;margin-top:28px;display:block;"></audio>';
                        if (m.transcricao) {
                            html += '<div class="wa-transcricao" style="font-size:.76rem;color:#374151;background:#f3f4f6;border-left:3px solid #6366f1;padding:4px 8px;border-radius:4px;margin-top:2px;max-width:260px;"><span style="color:#6366f1;font-weight:700;">📝 Transcrição:</span> '+escapeHtml(m.transcricao)+'</div>';
                        } else {
                            html += '<button onclick="waTranscrever('+m.id+',this)" style="background:#6366f1;color:#fff;border:none;padding:3px 8px;border-radius:5px;font-size:.7rem;cursor:pointer;margin-top:2px;display:block;">📝 Transcrever</button>';
                        }
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
                // Reações: minha (do atendente) e do cliente. Bolinhas pequenas abaixo da mensagem.
                if (m.minha_reacao || m.reacao_cliente) {
                    html += '<div style="display:flex;gap:3px;margin-top:3px;flex-wrap:wrap;">';
                    if (m.minha_reacao) {
                        html += '<span title="Sua reação — clique pra remover" onclick="waReagir('+m.id+',\'\')" style="background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:1px 8px;font-size:.85rem;cursor:pointer;">' + escapeHtml(m.minha_reacao) + '</span>';
                    }
                    if (m.reacao_cliente) {
                        html += '<span title="Reação do contato" style="background:#fef3c7;border:1px solid #fde68a;border-radius:20px;padding:1px 8px;font-size:.85rem;">' + escapeHtml(m.reacao_cliente) + '</span>';
                    }
                    html += '</div>';
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
        var input = document.getElementById('waChatInput');
        input.style.display = 'flex';

        // Trava: se outro atendente assumiu e a trava ainda está ativa.
        // Regra: libera em 30min se cliente é última msg, ou 36h se equipe é última.
        // Remove banner antigo e cronômetro se existirem.
        var oldLock = document.getElementById('waLockBanner');
        if (oldLock) oldLock.remove();
        if (window._waLockTimer) { clearInterval(window._waLockTimer); window._waLockTimer = null; }

        var travada = (+c.lock_pode_enviar === 0);
        if (travada) {
            var motivo = c.lock_motivo || 'atendente_ativo';
            var segAte = parseInt(c.lock_segundos_ate || 0, 10);
            var liberaEm = Date.now() + (segAte * 1000);

            var banner = document.createElement('div');
            banner.id = 'waLockBanner';
            banner.style.cssText = 'padding:.6rem .8rem;background:#fef3c7;border-top:1px solid #fcd34d;border-bottom:1px solid #fcd34d;color:#78350f;font-size:.8rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;';

            var motivoTxt = motivo === 'cliente_esperando'
                ? 'cliente está esperando resposta'
                : 'atendente ainda no controle';
            banner.innerHTML =
                '<span>🔒 <strong>Em atendimento por ' + escapeHtml(c.lock_atendente_name || 'outro atendente') + '</strong></span>' +
                '<span style="opacity:.85;">— ' + motivoTxt + '</span>' +
                '<span id="waLockTimer" style="margin-left:auto;background:rgba(217,119,6,.15);padding:2px 10px;border-radius:10px;font-weight:700;font-variant-numeric:tabular-nums;"></span>';
            input.parentNode.insertBefore(banner, input);

            function fmtDur(s) {
                if (s <= 0) return 'liberando...';
                if (s < 3600) {
                    var m = Math.floor(s / 60), ss = s % 60;
                    return m + 'min ' + ('0'+ss).slice(-2) + 's';
                }
                var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
                return h + 'h ' + ('0'+m).slice(-2) + 'min';
            }
            function atualizarLockTimer() {
                var restante = Math.max(0, Math.round((liberaEm - Date.now()) / 1000));
                var el = document.getElementById('waLockTimer');
                if (!el) { clearInterval(window._waLockTimer); return; }
                el.textContent = '⏱ Libera em ' + fmtDur(restante);
                if (restante <= 0) {
                    clearInterval(window._waLockTimer);
                    window._waLockTimer = null;
                    // Recarrega a conversa pra refletir o novo estado (sem trava)
                    if (convAtiva) window.waAbrir(convAtiva);
                }
            }
            atualizarLockTimer();
            window._waLockTimer = setInterval(atualizarLockTimer, 10000);

            ['waInput','waBtnSend','waBtnMic'].forEach(function(id){
                var el = document.getElementById(id); if (el) el.disabled = true;
            });
            input.querySelectorAll('.wa-btn-tpl').forEach(function(b){ b.disabled = true; b.style.opacity = .4; });
        } else {
            ['waInput','waBtnSend','waBtnMic'].forEach(function(id){
                var el = document.getElementById(id); if (el) el.disabled = false;
            });
            input.querySelectorAll('.wa-btn-tpl').forEach(function(b){ b.disabled = false; b.style.opacity = 1; });
            document.getElementById('waInput').focus();
        }
    }

    // ── ENVIAR MENSAGEM ou ARQUIVO PENDENTE ─────────────
    // ── RESPONDER MENSAGEM ──
    // Estado: { zapiMessageId, preview, direcao }
    var _waReplyTo = null;
    window.waResponderMsg = function(zapiMessageId, localMsgId) {
        if (!zapiMessageId) { alert('Mensagem sem ID do WhatsApp — não pode ser respondida.'); return; }
        // Busca o texto da mensagem no DOM pra preview
        var row = document.querySelector('[data-msg-id="' + localMsgId + '"]');
        var preview = '';
        var dir = 'recebida';
        if (row) {
            dir = row.classList.contains('right') ? 'enviada' : 'recebida';
            var conteudo = row.querySelector('.wa-msg > div[style*="white-space:pre-wrap"]');
            if (conteudo) preview = conteudo.textContent.trim();
            if (!preview) {
                var img = row.querySelector('.wa-msg img');
                if (img) preview = '🖼️ Imagem';
            }
            if (!preview) {
                var aud = row.querySelector('.wa-msg audio');
                if (aud) preview = '🎤 Áudio';
            }
            if (!preview) preview = '(mídia)';
        }
        _waReplyTo = { zapiMessageId: zapiMessageId, preview: preview, direcao: dir };
        document.getElementById('waReplyBar').style.display = 'flex';
        document.getElementById('waReplyQuem').textContent = dir === 'enviada' ? 'sua mensagem' : 'mensagem do contato';
        document.getElementById('waReplyPreview').textContent = preview.substring(0, 140);
        // Foca no input pra a pessoa digitar a resposta
        var inp = document.getElementById('waInput');
        if (inp) inp.focus();
    };
    window.waCancelarResposta = function() {
        _waReplyTo = null;
        document.getElementById('waReplyBar').style.display = 'none';
    };

    window.waEnviar = function() {
        if (!convAtiva) return;
        // Se está GRAVANDO agora, para e envia direto (flag setada, envio acontece no onstop)
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            recEnviarAoParar = true;
            recCancelada = false;
            mediaRecorder.stop();
            return;
        }
        // Se tem áudio gravado aguardando envio, envia ele (prioridade sobre texto/arquivo)
        if (audioPronto) {
            var a = audioPronto;
            // Limpa a referencia ANTES do envio pra UI nao bloquear proxima gravacao
            audioPronto = null;
            mostrarBarraGravacao(false);
            enviarAudioBlob(a.blob, a.mime);
            if (a.previewUrl) { try { URL.revokeObjectURL(a.previewUrl); } catch(e){} }
            return;
        }
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
        if (_waReplyTo && _waReplyTo.zapiMessageId) {
            fd.append('reply_to_message_id', _waReplyTo.zapiMessageId);
        }

        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            if (d.error) { alert('Erro: ' + d.error); return; }
            document.getElementById('waInput').value = '';
            waCancelarResposta(); // limpa estado de resposta após enviar
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
        if (e.target.id !== 'waInput') return;
        var slashDrop = document.getElementById('waSlashDrop');
        var slashAberto = slashDrop && slashDrop.style.display === 'block';

        // Enter com dropdown aberto: escolhe o item selecionado, não envia
        if (slashAberto && e.key === 'Enter') {
            e.preventDefault();
            var sel = slashDrop.querySelector('.wa-slash-item.selected');
            if (sel) sel.click();
            return;
        }
        if (slashAberto && e.key === 'Escape') {
            e.preventDefault();
            slashDrop.style.display = 'none';
            return;
        }
        if (slashAberto && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            waSlashNavegar(e.key === 'ArrowDown' ? 1 : -1);
            return;
        }
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            waEnviar();
        }
    });

    // ── AUTOCOMPLETE com / (slash command) ──────────────
    // Digite / seguido do nome da resposta rápida. Usa o mesmo templatesCache
    // já carregado pelo botão de templates.
    function waGarantirTemplates(cb) {
        if (templatesCache !== null) { cb(); return; }
        fetch(apiUrl + '?action=listar_templates&canal=' + canal).then(function(r){ return r.json(); }).then(function(d){
            if (d.ok) templatesCache = d.templates; else templatesCache = [];
            cb();
        }).catch(function(){ templatesCache = []; cb(); });
    }

    var _waSlashStart = -1;  // posição onde começa o /
    function waSlashFechar() {
        var drop = document.getElementById('waSlashDrop');
        if (drop) drop.style.display = 'none';
        _waSlashStart = -1;
    }

    function waSlashNavegar(dir) {
        var drop = document.getElementById('waSlashDrop');
        if (!drop) return;
        var items = drop.querySelectorAll('.wa-slash-item');
        if (!items.length) return;
        var atual = -1;
        items.forEach(function(it, i){ if (it.classList.contains('selected')) atual = i; });
        atual = (atual + dir + items.length) % items.length;
        items.forEach(function(it, i){ it.classList.toggle('selected', i === atual); });
        var sel = items[atual];
        if (sel && sel.scrollIntoView) sel.scrollIntoView({block:'nearest'});
    }

    function waSlashRender(query) {
        var drop = document.getElementById('waSlashDrop');
        if (!drop || !templatesCache) return;
        var q = (query || '').toLowerCase();
        // Filtra: primeiro por atalho (match exato no começo), depois por nome (substring)
        var resultados = templatesCache.filter(function(t){
            var atalho = (t.atalho || '').toLowerCase();
            var nome = (t.nome || '').toLowerCase();
            if (q === '') return true;
            if (atalho && atalho.indexOf(q) === 0) return true;      // atalho começa com q
            if (nome.indexOf(q) !== -1) return true;                  // nome contém q
            return false;
        }).slice(0, 8);
        if (!resultados.length) { drop.style.display = 'none'; return; }
        var html = '';
        resultados.forEach(function(t, i){
            var preview = (t.conteudo || '').substr(0, 90).replace(/\n/g,' ');
            // Preferência: mostra /atalho se tem atalho, senão /nome
            var label = t.atalho ? '/' + t.atalho : '/' + (t.nome || '').toLowerCase().replace(/\s+/g,'');
            html += '<div class="wa-slash-item ' + (i === 0 ? 'selected' : '') + '" data-id="' + t.id + '" style="padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #f3f4f6;">'
                  + '<div style="display:flex;align-items:center;gap:.4rem;">'
                  + '<div style="font-weight:700;font-size:.82rem;color:#052228;">' + escapeHtml(label) + '</div>'
                  + (t.atalho ? '<div style="font-size:.68rem;color:#94a3b8;">— ' + escapeHtml(t.nome) + '</div>' : '')
                  + '</div>'
                  + '<div style="font-size:.7rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(preview) + '</div>'
                  + '</div>';
        });
        drop.innerHTML = html;

        // Posiciona o dropdown ACIMA do input (position:fixed = relativo ao viewport)
        var inp = document.getElementById('waInput');
        var r = inp.getBoundingClientRect();
        var alturaEstimada = Math.min(280, resultados.length * 52);
        drop.style.left = Math.max(8, r.left) + 'px';
        drop.style.top  = Math.max(8, r.top - alturaEstimada - 6) + 'px';
        drop.style.display = 'block';

        // Handlers nos itens
        drop.querySelectorAll('.wa-slash-item').forEach(function(el){
            el.addEventListener('mouseenter', function(){
                drop.querySelectorAll('.wa-slash-item').forEach(function(x){ x.classList.remove('selected'); });
                el.classList.add('selected');
            });
            el.addEventListener('click', function(){
                var id = parseInt(el.getAttribute('data-id'), 10);
                var tpl = templatesCache.find(function(t){ return +t.id === id; });
                if (!tpl) return;
                // Substitui o /query pelo texto do template (com variáveis resolvidas)
                var inp = document.getElementById('waInput');
                var txt = inp.value;
                var antes = txt.substring(0, _waSlashStart);
                var primeiroNome = (convNomeAtual || '').split(/\s+/)[0] || '';
                var agora = new Date();
                var conteudo = (tpl.conteudo || '')
                    .replace(/\{\{nome\}\}/gi, primeiroNome)
                    .replace(/\{\{data\}\}/gi, agora.toLocaleDateString('pt-BR'))
                    .replace(/\{\{hora\}\}/gi, agora.toTimeString().substr(0,5));
                inp.value = antes + conteudo;
                waSlashFechar();
                inp.focus();
                // Move cursor pro fim
                inp.selectionStart = inp.selectionEnd = inp.value.length;
            });
        });
    }

    // Listener do input: detecta /palavra
    document.addEventListener('input', function(e){
        if (e.target.id !== 'waInput') return;
        var inp = e.target;
        var val = inp.value;
        var pos = inp.selectionStart || 0;
        // Acha o último "/" antes do cursor que está no início ou após espaço/quebra
        var trecho = val.substring(0, pos);
        var match = trecho.match(/(^|\s)\/([\wáéíóúçãõâêîôûà-]{0,30})$/i);
        if (!match) { waSlashFechar(); return; }
        var query = match[2];
        _waSlashStart = pos - query.length - 1; // posição do /
        waGarantirTemplates(function(){ waSlashRender(query); });
    });

    // Fecha dropdown ao clicar fora
    document.addEventListener('click', function(e){
        if (!e.target.closest('#waSlashDrop') && e.target.id !== 'waInput') waSlashFechar();
    });

    // ── ENVIAR STICKER ──────────────────────────────────
    window.waEnviarSticker = function(file) {
        if (!convAtiva) { alert('Selecione uma conversa primeiro.'); document.getElementById('waSticker').value=''; return; }
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) { alert('Sticker maior que 2 MB.'); document.getElementById('waSticker').value=''; return; }
        var fd = new FormData();
        fd.append('action', 'enviar_sticker');
        fd.append('conversa_id', convAtiva);
        fd.append('sticker', file, file.name || 'sticker.webp');
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            document.getElementById('waSticker').value = '';
            if (d.error) { alert('Erro: ' + d.error); return; }
            window.waAbrir(convAtiva);
            carregarLista();
        }).catch(function(err){
            document.getElementById('waSticker').value = '';
            alert('Falha: ' + err);
        });
    };

    // ── REAGIR A UMA MENSAGEM ────────────────────────────
    window.waReagir = function(msgId, emoji) {
        var fd = new FormData();
        fd.append('action', 'enviar_reacao');
        fd.append('mensagem_id', msgId);
        fd.append('emoji', emoji);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            if (d.error) { alert('Erro: ' + d.error); return; }
            window.waAbrir(convAtiva);
        });
        var pop = document.getElementById('waReacaoPopover');
        if (pop) pop.remove();
    };

    window.waAbrirReacaoPicker = function(btn, msgId) {
        var old = document.getElementById('waReacaoPopover');
        if (old) old.remove();
        var pop = document.createElement('div');
        pop.id = 'waReacaoPopover';
        pop.style.cssText = 'position:absolute;background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:4px 8px;box-shadow:0 4px 16px rgba(0,0,0,.15);z-index:500;display:flex;gap:4px;';
        var emojis = ['❤️','👍','👎','😂','😮','😢','🙏','🔥'];
        emojis.forEach(function(e){
            var b = document.createElement('button');
            b.textContent = e;
            b.style.cssText = 'background:none;border:none;font-size:1.2rem;cursor:pointer;padding:4px;border-radius:6px;';
            b.onmouseover = function(){ b.style.background = '#f3f4f6'; };
            b.onmouseout  = function(){ b.style.background = 'none'; };
            b.onclick = function(ev){ ev.stopPropagation(); window.waReagir(msgId, e); };
            pop.appendChild(b);
        });
        var remover = document.createElement('button');
        remover.textContent = '✕';
        remover.title = 'Remover reação';
        remover.style.cssText = 'background:none;border:none;font-size:.9rem;cursor:pointer;padding:4px 6px;border-radius:6px;color:#6b7280;';
        remover.onclick = function(ev){ ev.stopPropagation(); window.waReagir(msgId, ''); };
        pop.appendChild(remover);
        var rect = btn.getBoundingClientRect();
        pop.style.top = (rect.top + window.scrollY - 42) + 'px';
        pop.style.left = (rect.left + window.scrollX) + 'px';
        document.body.appendChild(pop);
        setTimeout(function(){
            document.addEventListener('click', function closer(e){
                if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', closer); }
            });
        }, 10);
    };

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

    // ── GRAVADOR DE ÁUDIO (MediaRecorder) ───────────────
    var mediaRecorder = null;
    var recChunks = [];
    var recStream = null;
    var recTimerInt = null;
    var recInicio = 0;
    var recCancelada = false;
    var recEnviarAoParar = false; // true quando Amanda clica ➤ Enviar durante a gravação
    var audioPronto = null; // {blob, mime, duracaoMs, previewUrl} — áudio gravado, aguardando envio

    function paraTimer() {
        if (recTimerInt) { clearInterval(recTimerInt); recTimerInt = null; }
    }
    function fecharStream() {
        if (recStream) {
            recStream.getTracks().forEach(function(t){ t.stop(); });
            recStream = null;
        }
    }
    function mostrarBarraGravacao(mostrar) {
        var bar = document.getElementById('waRecBar');
        document.getElementById('waInput').style.display = mostrar ? 'none' : '';
        var btn = document.getElementById('waBtnMic');
        if (mostrar) {
            // Restaura HTML original da barra (caso tenha virado "áudio pronto" antes)
            bar.innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;animation:waPulse 1s infinite;"></span>' +
                '<span style="font-size:.85rem;color:#991b1b;font-weight:600;">Gravando...</span>' +
                '<span id="waRecTimer" style="font-size:.85rem;color:#991b1b;font-variant-numeric:tabular-nums;">00:00</span>' +
                '<button onclick="waCancelarGravacao()" style="margin-left:auto;background:#fff;border:1px solid #fca5a5;color:#991b1b;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:.8rem;" title="Cancelar">✕ Cancelar</button>';
            bar.style.display = 'flex';
            btn.textContent = '⏹';
            btn.title = 'Parar gravação (o áudio fica pronto; clique ➤ Enviar pra enviar)';
            btn.style.background = '#ef4444';
            btn.style.color = '#fff';
        } else {
            bar.style.display = 'none';
            btn.textContent = '🎤';
            btn.title = 'Gravar áudio';
            btn.style.background = '';
            btn.style.color = '';
        }
    }

    // Mostra a barra no modo "áudio gravado, aguardando envio"
    function mostrarBarraAudioPronto() {
        if (!audioPronto) return;
        var bar = document.getElementById('waRecBar');
        var durS = Math.max(1, Math.round(audioPronto.duracaoMs / 1000));
        var mm = Math.floor(durS / 60), ss = durS % 60;
        var durText = ('0'+mm).slice(-2) + ':' + ('0'+ss).slice(-2);
        // Revoga URL anterior se tiver
        if (audioPronto.previewUrl) { try { URL.revokeObjectURL(audioPronto.previewUrl); } catch(e){} }
        audioPronto.previewUrl = URL.createObjectURL(audioPronto.blob);
        bar.style.borderColor = '#059669';
        bar.style.background = '#f0fdf4';
        bar.innerHTML = '<span style="font-size:1rem;">✓</span>' +
            '<span style="font-size:.8rem;color:#065f46;font-weight:600;">Áudio pronto — ' + durText + '</span>' +
            '<audio controls src="' + audioPronto.previewUrl + '" style="height:32px;flex:1;min-width:0;max-width:260px;"></audio>' +
            '<button onclick="waRegravarAudio()" style="background:#fff;border:1px solid #cbd5e1;color:#475569;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:.78rem;" title="Gravar de novo">🎤 Regravar</button>' +
            '<button onclick="waCancelarGravacao()" style="background:#fff;border:1px solid #fca5a5;color:#991b1b;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:.78rem;" title="Descartar">✕ Descartar</button>';
        bar.style.display = 'flex';
        document.getElementById('waInput').style.display = 'none';
        // Botão mic volta ao neutro (não está mais gravando)
        var btn = document.getElementById('waBtnMic');
        btn.textContent = '🎤';
        btn.title = 'Já há um áudio gravado — clique ➤ Enviar ou ✕ Descartar';
        btn.style.background = '';
        btn.style.color = '';
    }

    window.waRegravarAudio = function() {
        limparAudioPronto();
        window.waGravarAudio();
    };

    function limparAudioPronto() {
        if (audioPronto && audioPronto.previewUrl) {
            try { URL.revokeObjectURL(audioPronto.previewUrl); } catch(e){}
        }
        audioPronto = null;
    }

    window.waGravarAudio = function() {
        if (!convAtiva) { alert('Selecione uma conversa primeiro.'); return; }

        // Se já estiver gravando, clicar de novo = PARAR (mas NÃO envia).
        // O blob gravado fica aguardando em audioPronto até Amanda clicar ➤ Enviar.
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            recCancelada = false;
            mediaRecorder.stop();
            return;
        }

        // Se já tem áudio pronto aguardando envio, não começa outro sem descartar
        if (audioPronto) {
            alert('Já existe um áudio gravado. Clique em ➤ Enviar ou ✕ Descartar antes de gravar outro.');
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Seu navegador não suporta gravação de áudio.');
            return;
        }

        // Mensagem amigável com passo a passo pra desbloquear microfone
        function mostrarAjudaMicrofone(titulo) {
            alert(titulo + '\n\n' +
                  'Como liberar:\n' +
                  '1️⃣ Clique no cadeado 🔒 (ou ⚙️ / ⓘ) ao lado da URL no topo do navegador\n' +
                  '2️⃣ Procure "Microfone" na lista\n' +
                  '3️⃣ Mude pra "Permitir" (ou remova a regra de bloqueio)\n' +
                  '4️⃣ Feche e abra esta aba de novo (Ctrl+F5)\n\n' +
                  'Se não encontrar a opção, vá em:\n' +
                  '• Chrome: chrome://settings/content/microphone → remover ferreiraesa.com.br dos bloqueados\n' +
                  '• Edge: edge://settings/content/microphone → mesma coisa');
        }

        // Pré-check: se a permissão já está 'denied', não adianta chamar getUserMedia.
        // A API Permissions pode não estar disponível em todos os browsers — nesse caso só tentamos.
        var iniciar = function() {
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream){
                recStream = stream;
                recChunks = [];
                recCancelada = false;
                var mime = '';
                if (window.MediaRecorder) {
                    if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) mime = 'audio/webm;codecs=opus';
                    else if (MediaRecorder.isTypeSupported('audio/webm')) mime = 'audio/webm';
                    else if (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) mime = 'audio/ogg;codecs=opus';
                    else if (MediaRecorder.isTypeSupported('audio/mp4')) mime = 'audio/mp4';
                }
                try {
                    mediaRecorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
                } catch (err) {
                    alert('Não foi possível iniciar o gravador: ' + err);
                    fecharStream();
                    return;
                }

                mediaRecorder.ondataavailable = function(e){ if (e.data && e.data.size) recChunks.push(e.data); };
                mediaRecorder.onstop = function(){
                    paraTimer();
                    fecharStream();
                    var devoEnviar = recEnviarAoParar;
                    recEnviarAoParar = false;
                    if (recCancelada || recChunks.length === 0) {
                        mostrarBarraGravacao(false);
                        mediaRecorder = null;
                        return;
                    }

                    var usedMime = mediaRecorder.mimeType || 'audio/webm';
                    var blob = new Blob(recChunks, { type: usedMime });
                    var duracao = Date.now() - recInicio;
                    mediaRecorder = null;
                    if (duracao < 500) {
                        mostrarBarraGravacao(false);
                        alert('Áudio curto demais.');
                        return;
                    }
                    // Se Amanda clicou ➤ Enviar durante a gravação, envia direto (não guarda pra revisão)
                    if (devoEnviar) {
                        mostrarBarraGravacao(false);
                        enviarAudioBlob(blob, usedMime);
                        return;
                    }
                    // ⏹ apenas PARA — áudio fica aguardando Amanda clicar ➤ Enviar
                    audioPronto = { blob: blob, mime: usedMime, duracaoMs: duracao, previewUrl: null };
                    mostrarBarraAudioPronto();
                };

                mediaRecorder.start();
                recInicio = Date.now();
                mostrarBarraGravacao(true);
                recTimerInt = setInterval(function(){
                    var s = Math.floor((Date.now() - recInicio) / 1000);
                    var mm = Math.floor(s / 60), ss = s % 60;
                    document.getElementById('waRecTimer').textContent =
                        ('0'+mm).slice(-2) + ':' + ('0'+ss).slice(-2);
                    if (s >= 300) {
                        recCancelada = false;
                        mediaRecorder.stop();
                    }
                }, 250);
            }).catch(function(err){
                // Mensagens específicas por tipo de erro
                if (err && err.name === 'NotAllowedError') {
                    mostrarAjudaMicrofone('🚫 O microfone está BLOQUEADO pra este site.');
                } else if (err && err.name === 'NotFoundError') {
                    alert('🎤 Nenhum microfone encontrado.\n\nVerifique se seu microfone está conectado e reconhecido pelo Windows (botão Iniciar → Configurações → Sistema → Som).');
                } else if (err && err.name === 'NotReadableError') {
                    alert('🎤 O microfone está em uso por outro programa.\n\nFeche outros aplicativos que possam estar usando (Zoom, Teams, Meet, WhatsApp Desktop, Discord) e tente novamente.');
                } else if (err && err.name === 'AbortError') {
                    alert('🎤 Acesso ao microfone foi interrompido. Tente novamente.');
                } else {
                    alert('Erro ao acessar microfone: ' + (err && err.name ? err.name + ' — ' : '') + (err && err.message ? err.message : err));
                }
            });
        };

        // SEMPRE tenta direto. A API Permissions às vezes retorna 'denied' mesmo
        // quando o site estaria autorizado — só o getUserMedia é autoridade final.
        iniciar();
    };

    window.waCancelarGravacao = function() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            recCancelada = true;
            mediaRecorder.stop();
            // Limpa áudio pronto se houver (não deveria, mas garante)
            limparAudioPronto();
        } else {
            paraTimer();
            fecharStream();
            limparAudioPronto();
            mostrarBarraGravacao(false);
        }
    };

    function enviarAudioBlob(blob, mime) {
        if (!convAtiva) return;
        var ext = 'webm';
        if (mime.indexOf('ogg') >= 0) ext = 'ogg';
        else if (mime.indexOf('mp4') >= 0) ext = 'm4a';

        var fd = new FormData();
        fd.append('action', 'enviar_audio');
        fd.append('conversa_id', convAtiva);
        fd.append('audio', blob, 'voice_' + Date.now() + '.' + ext);
        fd.append('csrf_token', csrf);

        var btn = document.getElementById('waBtnSend');
        btn.disabled = true; btn.textContent = 'Enviando áudio...';

        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            if (d.error) { alert('Erro: ' + d.error); return; }
            window.waAbrir(convAtiva);
            carregarLista();
        }).catch(function(err){
            btn.disabled = false; btn.textContent = '➤ Enviar';
            alert('Falha ao enviar áudio: ' + err);
        });
    }

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
    window.waAssumir = function() {
        acaoConversa('assumir_atendimento').then(function(r){
            if (r && r.error) { alert(r.error); return; }
            window.waAbrir(convAtiva); carregarLista();
        });
    };

    // Modal de delegação — só aparece pra Amanda/Luiz (PODE_DELEGAR = true).
    // Quando delegada, só o alvo (ou outro admin) pode assumir.
    window.waAbrirDelegar = function() {
        if (!PODE_DELEGAR) return;
        if (!convAtiva) return;
        var optsHtml = USUARIOS.map(function(u){
            return '<option value="' + u.id + '">' + u.name + '</option>';
        }).join('');
        var overlay = document.createElement('div');
        overlay.id = 'waDelegarOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
        overlay.onclick = function(e){ if (e.target === overlay) overlay.remove(); };
        overlay.innerHTML = '<div style="background:#fff;border-radius:14px;padding:1.5rem;width:400px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.3);">'
            + '<h3 style="margin:0 0 .5rem;font-size:1rem;color:#052228;">🎯 Delegar conversa</h3>'
            + '<p style="margin:0 0 1rem;font-size:.78rem;color:#6b7280;">O atendente escolhido fica responsável. Ninguém mais poderá assumir até você remover a delegação — OU até a conversa ficar 30 minutos sem interação (então destrava sozinha).</p>'
            + '<label style="font-size:.75rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem;">Delegar para:</label>'
            + '<select id="waDelegarAlvo" style="width:100%;padding:.5rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:.85rem;margin-bottom:1rem;">'
            + '<option value="">Selecione...</option>' + optsHtml + '</select>'
            + '<div style="display:flex;gap:.5rem;justify-content:flex-end;">'
            + '<button onclick="document.getElementById(\'waDelegarOverlay\').remove()" style="padding:.45rem 1rem;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:.8rem;">Cancelar</button>'
            + '<button id="waBtnConfirmarDelegar" style="padding:.45rem 1rem;border:none;border-radius:8px;background:#7c3aed;color:#fff;cursor:pointer;font-weight:700;font-size:.8rem;">Delegar</button>'
            + '</div></div>';
        document.body.appendChild(overlay);
        document.getElementById('waBtnConfirmarDelegar').onclick = function() {
            var alvo = document.getElementById('waDelegarAlvo').value;
            if (!alvo) { alert('Escolha um atendente.'); return; }
            var fd = new FormData();
            fd.append('action', 'delegar_conversa');
            fd.append('csrf_token', csrf);
            fd.append('conversa_id', convAtiva);
            fd.append('atendente_id', alvo);
            this.disabled = true; this.textContent = 'Delegando...';
            fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(r){
                if (r && r.error) { alert(r.error); }
                else { overlay.remove(); window.waAbrir(convAtiva); carregarLista(); }
            });
        };
    };

    window.waRemoverDelegacao = function() {
        if (!PODE_DELEGAR || !convAtiva) return;
        if (!confirm('Remover a delegação? Qualquer atendente poderá assumir a conversa.')) return;
        acaoConversa('remover_delegacao').then(function(r){
            if (r && r.error) { alert(r.error); return; }
            window.waAbrir(convAtiva); carregarLista();
        });
    };

    // Modal de mesclagem de conversas duplicadas. Só Amanda/Luiz.
    // Traz candidatas automáticas + campo de busca livre (nome, telefone, #ID)
    // pra casos onde @lid não compartilha dígitos com telefone real.
    window.waAbrirMesclar = function() {
        if (!PODE_DELEGAR || !convAtiva) return;

        var overlay = document.createElement('div');
        overlay.id = 'waMesclarOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
        overlay.onclick = function(e){ if (e.target === overlay) overlay.remove(); };
        overlay.innerHTML = '<div style="background:#fff;border-radius:14px;padding:1.5rem;width:620px;max-width:92vw;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">'
            + '<h3 style="margin:0 0 .25rem;font-size:1rem;color:#052228;">🔗 Mesclar conversa</h3>'
            + '<p style="margin:0 0 .75rem;font-size:.78rem;color:#6b7280;">Escolha outra conversa do mesmo contato pra unificar. Todas as mensagens e etiquetas dela serão movidas pra conversa atualmente aberta. A outra será apagada.</p>'
            + '<div style="display:flex;gap:6px;margin-bottom:.75rem;">'
            +   '<input id="waMesclarBusca" type="text" placeholder="🔍 Buscar por nome, telefone ou #ID" style="flex:1;padding:.55rem .75rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:.85rem;">'
            +   '<button id="waMesclarTodas" type="button" style="padding:.55rem .75rem;border:1.5px solid #7c3aed;background:#7c3aed;color:#fff;border-radius:8px;font-size:.78rem;cursor:pointer;font-weight:700;white-space:nowrap;">Mostrar todas</button>'
            + '</div>'
            + '<div id="waMesclarLista" style="max-height:380px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;">Carregando...</div>'
            + '<p style="font-size:.72rem;color:#991b1b;margin:.75rem 0;">⚠ Ação irreversível.</p>'
            + '<div style="display:flex;gap:.5rem;justify-content:flex-end;">'
            + '<button onclick="document.getElementById(\'waMesclarOverlay\').remove()" style="padding:.45rem 1rem;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:.8rem;">Cancelar</button>'
            + '<button id="waBtnConfirmarMesclar" disabled style="padding:.45rem 1rem;border:none;border-radius:8px;background:#dc2626;color:#fff;cursor:pointer;font-weight:700;font-size:.8rem;opacity:.5;">Mesclar selecionada</button>'
            + '</div></div>';
        document.body.appendChild(overlay);

        document.getElementById('waMesclarTodas').onclick = function() {
            document.getElementById('waMesclarBusca').value = '';
            buscar('', true);
        };

        function renderCands(cands) {
            var lista = document.getElementById('waMesclarLista');
            if (!cands || cands.length === 0) {
                lista.innerHTML = '<div style="padding:1rem;text-align:center;color:#6b7280;font-size:.85rem;">Nenhuma conversa encontrada.<br><small>Tente buscar pelo telefone ou #ID.</small></div>';
                document.getElementById('waBtnConfirmarMesclar').disabled = true;
                document.getElementById('waBtnConfirmarMesclar').style.opacity = '.5';
                return;
            }
            var html = '';
            cands.forEach(function(c){
                html += '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border-bottom:1px solid #f3f4f6;cursor:pointer;">'
                    + '<input type="radio" name="waMesclarCand" value="' + c.id + '" style="margin-top:4px;">'
                    + '<div style="flex:1;min-width:0;">'
                    +   '<div style="font-weight:600;font-size:.85rem;color:#052228;">' + escapeHtml(c.nome_contato || '(sem nome)') + ' <span style="font-weight:400;color:#9ca3af;font-size:.72rem;">#' + c.id + '</span></div>'
                    +   '<div style="font-size:.72rem;color:#6b7280;font-family:monospace;">' + escapeHtml(c.telefone || '') + '</div>'
                    +   '<div style="font-size:.72rem;color:#6b7280;">' + (c.qt_msgs || 0) + ' msg(s) · ' + escapeHtml((c.ultima_mensagem || '').substring(0, 60)) + '</div>'
                    + '</div></label>';
            });
            lista.innerHTML = html;
            lista.querySelectorAll('input[name="waMesclarCand"]').forEach(function(r){
                r.addEventListener('change', function(){
                    var btn = document.getElementById('waBtnConfirmarMesclar');
                    btn.disabled = false; btn.style.opacity = '1';
                });
            });
        }

        function buscar(q, todas) {
            var url = apiUrl + '?action=listar_duplicatas&conversa_id=' + convAtiva
                    + (q ? '&q=' + encodeURIComponent(q) : '')
                    + (todas ? '&todas=1' : '');
            fetch(url).then(function(r){ return r.json(); }).then(function(d){
                if (d.error) { alert(d.error); overlay.remove(); return; }
                renderCands(d.candidatas || []);
            });
        }

        // Carga inicial: critério automático (nome/dígitos)
        buscar('');

        // Busca com debounce
        var debT;
        document.getElementById('waMesclarBusca').addEventListener('input', function(e){
            clearTimeout(debT);
            var v = e.target.value.trim();
            debT = setTimeout(function(){ buscar(v); }, 300);
        });

        document.getElementById('waBtnConfirmarMesclar').onclick = function() {
            var sel = document.querySelector('input[name="waMesclarCand"]:checked');
            if (!sel) { alert('Selecione uma conversa.'); return; }
            if (!confirm('Confirma? Esta ação é irreversível.')) return;
            var fd = new FormData();
            fd.append('action', 'mesclar_conversas');
            fd.append('csrf_token', csrf);
            fd.append('origem_id', sel.value); // a selecionada será absorvida
            fd.append('destino_id', convAtiva); // esta conversa aberta = destino
            this.disabled = true; this.textContent = 'Mesclando...';
            fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(r){
                if (r && r.error) { alert(r.error); return; }
                overlay.remove();
                window.waAbrir(convAtiva);
                carregarLista();
                alert('✓ Conversas mescladas.');
            });
        };
    };
    window.waToggleBot = function(ativar) {
        var action = ativar ? 'ativar_bot' : 'desativar_bot';
        acaoConversa(action).then(function(){ window.waAbrir(convAtiva); carregarLista(); });
    };

    // ── SALVAR ARQUIVO NO DRIVE ──────────────────────────
    window.waTranscrever = function(msgId, btn) {
        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Transcrevendo...';
        var fd = new FormData();
        fd.append('action', 'transcrever_audio');
        fd.append('mensagem_id', msgId);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            if (d.error) {
                btn.disabled = false;
                btn.textContent = original;
                alert('Erro: ' + d.error);
                return;
            }
            var box = document.createElement('div');
            box.className = 'wa-transcricao';
            box.style.cssText = 'font-size:.76rem;color:#374151;background:#f3f4f6;border-left:3px solid #6366f1;padding:4px 8px;border-radius:4px;margin-top:2px;max-width:260px;';
            box.innerHTML = '<span style="color:#6366f1;font-weight:700;">📝 Transcrição:</span> ' + escapeHtml(d.text || '');
            btn.parentNode.replaceChild(box, btn);
        }).catch(function(err){
            btn.disabled = false;
            btn.textContent = original;
            alert('Falha: ' + err);
        });
    };

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
            // Mapa status → { label, cor, icon } (espelha modules/operacional/index.php)
            var statusMap = {
                'aguardando_docs'         : { label: 'Contrato — Aguardando Docs', cor: '#f59e0b', icon: '📄' },
                'em_elaboracao'           : { label: 'Pasta Apta',                 cor: '#059669', icon: '✔️' },
                'em_andamento'            : { label: 'Em Execução',                cor: '#0ea5e9', icon: '⚙️' },
                'doc_faltante'            : { label: 'Doc Faltante',               cor: '#dc2626', icon: '⚠️' },
                'suspenso'                : { label: 'Suspenso',                   cor: '#5B2D8E', icon: '⏸️' },
                'aguardando_prazo'        : { label: 'Aguard. Distribuição',       cor: '#8b5cf6', icon: '⏳' },
                'distribuido'             : { label: 'Processo Distribuído',       cor: '#15803d', icon: '🏛️' },
                'kanban_prev'             : { label: 'Kanban PREV',                cor: '#3B4FA0', icon: '🏛️' },
                'parceria_previdenciario' : { label: 'Parceria',                   cor: '#06b6d4', icon: '🤝' },
                'cancelado'               : { label: 'Cancelado',                  cor: '#6b7280', icon: '❌' },
                'arquivado'               : { label: 'Arquivado',                  cor: '#6b7280', icon: '📦' }
            };
            function pilulaStatus(s) {
                var info = statusMap[s] || { label: s || '—', cor: '#6b7280', icon: '•' };
                return '<span style="display:inline-flex;align-items:center;gap:4px;background:'+info.cor+';color:#fff;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;">'+info.icon+' '+escapeHtml(info.label)+'</span>';
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
                html += '<div style="font-size:.75rem;color:#6b7280;margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">';
                if (c.case_type) html += '<span>'+escapeHtml(c.case_type)+'</span>';
                html += '<span style="color:#9ca3af;">Status:</span>'+pilulaStatus(c.status);
                html += '</div>';
                if (!hasFolder) html += '<div style="font-size:.72rem;color:#dc2626;margin-top:4px;">⚠️ Caso sem pasta no Drive — crie no Kanban Operacional primeiro</div>';
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

    // Fixar/desfixar mensagem no topo da conversa (só no Hub, não sincroniza com WhatsApp)
    window.waPinMsg = function(msgId) {
        var fd = new FormData();
        fd.append('action', 'pin_mensagem');
        fd.append('mensagem_id', msgId);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            if (d.error) { alert('Erro: ' + d.error); return; }
            window.waAbrir(convAtiva);
        });
    };

    // Fixar/desfixar conversa no topo da lista
    window.waPinConversa = function() {
        if (!convAtiva) return;
        var fd = new FormData();
        fd.append('action', 'pin_conversa');
        fd.append('conversa_id', convAtiva);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
            if (d.error) { alert('Erro: ' + d.error); return; }
            window.waAbrir(convAtiva);
            carregarLista();
        });
    };

    // Scrolla até a mensagem clicada no card de fixadas
    window.waScrollToMsg = function(msgId) {
        var row = document.querySelector('.wa-msg-row[data-msg-id="' + msgId + '"]');
        if (!row) return;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.style.transition = 'background .3s';
        row.style.background = '#fef3c7';
        setTimeout(function(){ row.style.background = ''; }, 1500);
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

    window.waEnviarLinkPortal = function() {
        if (!convAtiva) return;
        if (!confirm('Gerar novo link de ativação da Central VIP e abrir pra envio?\n\n(o link antigo será invalidado e um novo token de 72h será criado)')) return;
        var fd = new FormData();
        fd.append('action', 'gerar_link_salavip');
        fd.append('conversa_id', convAtiva);
        fd.append('csrf_token', csrf);
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.error) { alert('❌ ' + d.error); return; }
                if (d.ok) {
                    waSenderOpen({
                        telefone: d.telefone,
                        nome: d.client_name,
                        mensagem: d.mensagem,
                        canal: d.canal,
                        clientId: d.client_id,
                        onSuccess: function() { window.waAbrir(convAtiva); }
                    });
                }
            })
            .catch(function(err){ alert('Falha: ' + err); });
    };

    // Abrir pasta do processo vinculado ao cliente da conversa.
    // Se o cliente tem 1 processo: redireciona direto. Se tem vários: mostra picker.
    window.waAbrirProcesso = function(clientId) {
        if (!clientId) return;
        var opApi = '<?= module_url('operacional', 'api.php') ?>';
        var base = '<?= rtrim(url(''), '/') ?>';
        // Endpoint só aceita POST; buscar_casos_cliente é read-only (não precisa CSRF
        // válido, mas manda mesmo assim pra compatibilidade)
        var fd = new FormData();
        fd.append('action', 'buscar_casos_cliente');
        fd.append('client_id', clientId);
        fd.append('<?= CSRF_TOKEN_NAME ?>', window._FSA_CSRF || '<?= generate_csrf_token() ?>');
        fetch(opApi, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
            .then(function(r){ return r.json(); })
            .then(function(d){
                var casos = (d && d.casos) || [];
                if (!casos.length) {
                    alert('Esse cliente não tem processo cadastrado no Operacional.');
                    return;
                }
                if (casos.length === 1) {
                    window.open(base + '/modules/operacional/caso_ver.php?id=' + casos[0].id, '_blank');
                    return;
                }
                // Múltiplos casos: mostra picker simples
                var html = '<div id="waProcOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:1rem;">'
                         + '<div style="background:#fff;border-radius:14px;padding:1.25rem 1.5rem;max-width:440px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);">'
                         + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;">'
                         + '<h3 style="margin:0;font-size:1rem;color:#052228;">⚖️ Qual processo abrir?</h3>'
                         + '<button onclick="document.getElementById(\'waProcOverlay\').remove()" style="background:#f3f4f6;border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;">×</button>'
                         + '</div>'
                         + '<p style="font-size:.8rem;color:#64748b;margin-bottom:.75rem;">Este cliente tem ' + casos.length + ' processos. Escolha qual abrir:</p>'
                         + '<div style="max-height:340px;overflow-y:auto;display:flex;flex-direction:column;gap:.35rem;">';
                casos.forEach(function(cs){
                    var titulo = cs.title || ('Processo #' + cs.id);
                    var sub = '';
                    if (cs.case_number) sub += cs.case_number;
                    if (cs.status) sub += (sub ? ' · ' : '') + String(cs.status).replace(/_/g,' ');
                    html += '<a href="' + base + '/modules/operacional/caso_ver.php?id=' + cs.id + '" target="_blank"'
                          + ' style="display:block;padding:.6rem .8rem;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:var(--petrol-900);transition:background .15s;"'
                          + ' onmouseover="this.style.background=\'#f1f5f9\'" onmouseout="this.style.background=\'transparent\'">'
                          + '<div style="font-weight:700;font-size:.85rem;">' + escapeHtml(titulo) + '</div>'
                          + (sub ? '<div style="font-size:.7rem;color:#64748b;margin-top:2px;">' + escapeHtml(sub) + '</div>' : '')
                          + '</a>';
                });
                html += '</div></div></div>';
                var wrap = document.createElement('div');
                wrap.innerHTML = html;
                var overlay = wrap.firstChild;
                overlay.addEventListener('click', function(ev){ if (ev.target === overlay) overlay.remove(); });
                document.body.appendChild(overlay);
            })
            .catch(function(e){ alert('Erro ao buscar processos: ' + e.message); });
    };

    window.waCriarChamado = function() {
        if (!convAtiva) return;
        // Busca dados da conversa atual
        fetch(apiUrl + '?action=abrir_conversa&id=' + convAtiva).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok || !d.conversa) return;
            var c = d.conversa;
            var params = new URLSearchParams();
            if (c.client_id) params.set('client_id', c.client_id);
            // Pré-preenche título com o nome + assunto genérico
            var nome = c.nome_contato || c.client_name || c.lead_name || formatTel(c.telefone);
            params.set('title', 'Atendimento WhatsApp — ' + nome);
            params.set('client_name', nome);
            params.set('client_contact', c.telefone);
            // Últimas 3 mensagens recebidas como descrição de contexto
            var msgs = (d.mensagens || []).filter(function(m){ return m.direcao === 'recebida' && m.conteudo; }).slice(-3);
            if (msgs.length) {
                var desc = 'Contexto da conversa WhatsApp ('+ (c.canal === '21' ? 'Comercial' : 'CX') +'):\n\n';
                msgs.forEach(function(m){ desc += '• ' + m.conteudo.substring(0, 200) + '\n'; });
                desc += '\nLink do chat: ' + window.location.origin + '<?= module_url('whatsapp') ?>?canal=' + c.canal + '&conv=' + c.id;
                params.set('description', desc);
            }
            // Abre em nova aba (não perde a conversa)
            window.open('<?= module_url('helpdesk', 'novo.php') ?>?' + params.toString(), '_blank');
        });
    };
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
        var chip = document.getElementById('waEtqChipAtivo');
        var btnEtq = document.getElementById('waBtnFiltroEtq');
        var btnAtDes = document.getElementById('waBtnAtDesbloqueado');

        // Reset visual dos dois botões antes de redesenhar o estado
        if (btnEtq) { btnEtq.style.background = ''; btnEtq.style.color = ''; btnEtq.style.borderColor = ''; }
        if (btnAtDes) { btnAtDes.style.background = '#fef2f2'; btnAtDes.style.color = '#991b1b'; btnAtDes.style.borderColor = '#dc2626'; }

        if (etiquetaFiltro) {
            var ativa = etiquetasCache.find(function(e){ return +e.id === +etiquetaFiltro; });
            if (ativa) {
                chip.innerHTML = '<span style="font-size:.7rem;color:#6b7280;">Filtrando:</span>' +
                    '<span style="background:'+escapeHtml(ativa.cor)+';color:#fff;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:700;display:inline-flex;align-items:center;gap:4px;">' +
                    escapeHtml(ativa.nome) +
                    '<span onclick="waFiltrarPorEtiqueta(0)" style="cursor:pointer;opacity:.85;">✕</span></span>';
                chip.style.display = 'flex';

                // Se a ativa é a AT DESBLOQUEADO, destaca o botão atalho
                if (ETQ_AT_DESBLOQUEADO_ID && +ativa.id === +ETQ_AT_DESBLOQUEADO_ID) {
                    if (btnAtDes) { btnAtDes.style.background = '#dc2626'; btnAtDes.style.color = '#fff'; btnAtDes.style.borderColor = '#dc2626'; }
                } else if (btnEtq) {
                    btnEtq.style.background = ativa.cor;
                    btnEtq.style.color = '#fff';
                    btnEtq.style.borderColor = ativa.cor;
                }
            }
        } else {
            chip.innerHTML = '';
            chip.style.display = 'none';
        }
    }

    window.waToggleFiltroEtqPopover = function(ev) {
        ev.stopPropagation();
        var pop = document.getElementById('waEtqPopoverFiltro');
        if (pop.style.display === 'block') { pop.style.display = 'none'; return; }
        if (!etiquetasCache) return;
        // Posiciona popover logo abaixo do botão
        var btn = ev.target;
        var rect = btn.getBoundingClientRect();
        pop.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        pop.style.left = (rect.left + window.scrollX) + 'px';
        var html = '<div style="font-size:.65rem;color:#6b7280;font-weight:700;margin-bottom:4px;padding:0 4px;">FILTRAR POR ETIQUETA</div>';
        etiquetasCache.forEach(function(et){
            var active = (+etiquetaFiltro === +et.id);
            html += '<div style="display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:6px;cursor:pointer;'+(active?'background:#f3f4f6;':'')+'" onmouseover="this.style.background=\'#f9fafb\'" onmouseout="this.style.background=\''+(active?'#f3f4f6':'transparent')+'\'" onclick="waFiltrarPorEtiqueta('+et.id+');">';
            html += '<span style="background:'+escapeHtml(et.cor)+';color:#fff;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:600;">'+escapeHtml(et.nome)+'</span>';
            if (active) html += '<span style="margin-left:auto;color:#22c55e;">✓</span>';
            html += '</div>';
        });
        if (etiquetaFiltro) html += '<div style="border-top:1px solid #eee;margin-top:4px;padding-top:4px;"><div onclick="waFiltrarPorEtiqueta(0);" style="padding:5px 8px;color:#ef4444;font-size:.75rem;cursor:pointer;border-radius:6px;" onmouseover="this.style.background=\'#fee2e2\'" onmouseout="this.style.background=\'transparent\'">✕ Limpar filtro</div></div>';
        pop.innerHTML = html;
        pop.style.display = 'block';
    };

    window.waFiltrarPorEtiqueta = function(id) {
        etiquetaFiltro = +id;
        document.getElementById('waEtqPopoverFiltro').style.display = 'none';
        renderEtqFilters();
        carregarLista();
    };

    // Atalho: clica no botão "🔓 AT Desbloq." pra filtrar direto essa etiqueta
    window.waFiltrarAtDesbloqueado = function() {
        if (!ETQ_AT_DESBLOQUEADO_ID) return;
        // Toggle: se já tá filtrando por ela, desliga
        if (+etiquetaFiltro === +ETQ_AT_DESBLOQUEADO_ID) {
            waFiltrarPorEtiqueta(0);
        } else {
            waFiltrarPorEtiqueta(ETQ_AT_DESBLOQUEADO_ID);
        }
    };

    // Fecha popover ao clicar fora
    document.addEventListener('click', function(e){
        var pop = document.getElementById('waEtqPopoverFiltro');
        if (!pop) return;
        if (e.target.closest('#waEtqPopoverFiltro') || e.target.closest('#waBtnFiltroEtq')) return;
        pop.style.display = 'none';
    });
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

    // Abre modal de "Nova conversa" (2 opções: cliente existente ou número novo)
    window.waAbrirNovaConversa = function() {
        var canal = '<?= $canal ?>';
        var modal = document.getElementById('waNovaConvModal');
        if (!modal) {
            // Cria modal se não existe
            modal = document.createElement('div');
            modal.id = 'waNovaConvModal';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;justify-content:center;align-items:center;padding:20px;';
            modal.innerHTML =
                '<div style="background:#fff;border-radius:12px;max-width:520px;width:100%;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.3);">' +
                '  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">' +
                '    <h3 style="margin:0;font-size:1.05rem;color:#052228;">➕ Nova conversa (DDD ' + canal + ')</h3>' +
                '    <button onclick="waFecharNovaConv()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;">✕</button>' +
                '  </div>' +
                '  <div style="display:flex;gap:4px;margin-bottom:1rem;border-bottom:1px solid #e5e7eb;">' +
                '    <button id="waNovaTabA" onclick="waNovaTab(\'a\')" style="background:none;border:none;padding:.5rem 1rem;cursor:pointer;font-size:.85rem;font-weight:600;border-bottom:2px solid #B87333;color:#B87333;">👤 Cliente da base</button>' +
                '    <button id="waNovaTabB" onclick="waNovaTab(\'b\')" style="background:none;border:none;padding:.5rem 1rem;cursor:pointer;font-size:.85rem;font-weight:500;border-bottom:2px solid transparent;color:#64748b;">📞 Número novo</button>' +
                '  </div>' +
                '  <div id="waNovaConteudoA">' +
                '    <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;">Buscar cliente:</label>' +
                '    <input type="text" id="waNovaClienteBusca" placeholder="Digite nome do cliente..." autocomplete="off" style="width:100%;padding:8px 10px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.85rem;">' +
                '    <input type="hidden" id="waNovaClienteId">' +
                '    <div id="waNovaClienteList" style="max-height:180px;overflow-y:auto;margin-top:4px;border-radius:6px;"></div>' +
                '    <div id="waNovaClienteSel" style="margin-top:6px;font-size:.78rem;color:#15803d;"></div>' +
                '  </div>' +
                '  <div id="waNovaConteudoB" style="display:none;">' +
                '    <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;">Nome (opcional):</label>' +
                '    <input type="text" id="waNovaTelNome" placeholder="Ex: João Silva" style="width:100%;padding:8px 10px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.85rem;margin-bottom:.6rem;">' +
                '    <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;">Número com DDD:</label>' +
                '    <input type="text" id="waNovaTel" placeholder="Ex: 24991234567 ou 5524991234567" style="width:100%;padding:8px 10px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.85rem;font-family:monospace;">' +
                '    <div style="font-size:.7rem;color:#64748b;margin-top:4px;">DDI 55 adicionado automaticamente se faltar.</div>' +
                '  </div>' +
                '  <label style="font-size:.78rem;font-weight:600;display:block;margin:1rem 0 4px;">Primeira mensagem:</label>' +
                '  <textarea id="waNovaMsg" rows="4" placeholder="Olá! Aqui é do Ferreira & Sá Advocacia..." style="width:100%;padding:8px 10px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.85rem;resize:vertical;"></textarea>' +
                '  <div id="waNovaMsgErr" style="margin-top:.5rem;font-size:.78rem;"></div>' +
                '  <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">' +
                '    <button onclick="waFecharNovaConv()" class="btn btn-outline btn-sm">Cancelar</button>' +
                '    <button onclick="waEnviarNovaConv()" id="waNovaBtnEnv" class="btn btn-primary btn-sm" style="background:#B87333;">📤 Enviar</button>' +
                '  </div>' +
                '</div>';
            document.body.appendChild(modal);
            modal.addEventListener('click', function(e){ if (e.target === modal) waFecharNovaConv(); });

            // Autocomplete de clientes
            var inpBusca = document.getElementById('waNovaClienteBusca');
            var inpId = document.getElementById('waNovaClienteId');
            var listEl = document.getElementById('waNovaClienteList');
            var timer;
            inpBusca.addEventListener('input', function() {
                clearTimeout(timer);
                var q = this.value.trim();
                if (q.length < 2) { listEl.innerHTML = ''; return; }
                timer = setTimeout(function() {
                    fetch('<?= url('api/busca_global.php') ?>?q=' + encodeURIComponent(q))
                        .then(function(r){ return r.json(); })
                        .then(function(j) {
                            var clientes = (j.grupos && j.grupos.clientes) || [];
                            if (!clientes.length) { listEl.innerHTML = '<div style="padding:8px;color:#94a3b8;font-size:.78rem;">Nenhum cliente encontrado</div>'; return; }
                            var h = '';
                            clientes.slice(0,10).forEach(function(c) {
                                h += '<div onclick="waNovaSelCli(' + (c.id || 0) + ',\'' + escapeHtml(c.titulo).replace(/\'/g,"\\\\'") + '\')" style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.8rem;" onmouseover="this.style.background=\'#f1f5f9\'" onmouseout="this.style.background=\'\'">' + escapeHtml(c.titulo) + (c.subtitulo ? '<span style="color:#64748b;font-size:.72rem;"> · ' + escapeHtml(c.subtitulo) + '</span>' : '') + '</div>';
                            });
                            listEl.innerHTML = h;
                        });
                }, 250);
            });
        }
        modal.style.display = 'flex';
        // Reset
        document.getElementById('waNovaClienteBusca').value = '';
        document.getElementById('waNovaClienteId').value = '';
        document.getElementById('waNovaClienteList').innerHTML = '';
        document.getElementById('waNovaClienteSel').textContent = '';
        document.getElementById('waNovaTel').value = '';
        document.getElementById('waNovaTelNome').value = '';
        document.getElementById('waNovaMsg').value = '';
        document.getElementById('waNovaMsgErr').innerHTML = '';
        waNovaTab('a');
    };

    window.waFecharNovaConv = function() {
        var m = document.getElementById('waNovaConvModal');
        if (m) m.style.display = 'none';
    };

    window.waNovaTab = function(t) {
        document.getElementById('waNovaConteudoA').style.display = t === 'a' ? 'block' : 'none';
        document.getElementById('waNovaConteudoB').style.display = t === 'b' ? 'block' : 'none';
        var a = document.getElementById('waNovaTabA');
        var b = document.getElementById('waNovaTabB');
        a.style.color = t === 'a' ? '#B87333' : '#64748b';
        a.style.borderBottomColor = t === 'a' ? '#B87333' : 'transparent';
        b.style.color = t === 'b' ? '#B87333' : '#64748b';
        b.style.borderBottomColor = t === 'b' ? '#B87333' : 'transparent';
    };

    window.waNovaSelCli = function(id, nome) {
        document.getElementById('waNovaClienteId').value = id;
        document.getElementById('waNovaClienteBusca').value = nome;
        document.getElementById('waNovaClienteList').innerHTML = '';
        document.getElementById('waNovaClienteSel').innerHTML = '✅ Selecionado: <strong>' + escapeHtml(nome) + '</strong>';
    };

    window.waEnviarNovaConv = function() {
        var tabA = document.getElementById('waNovaConteudoA').style.display !== 'none';
        var msg = document.getElementById('waNovaMsg').value.trim();
        var err = document.getElementById('waNovaMsgErr');
        if (!msg) { err.innerHTML = '<span style="color:#b91c1c">❌ Digite a primeira mensagem</span>'; return; }

        var fd = new FormData();
        fd.append('action', 'nova_conversa');
        fd.append('csrf_token', csrf);
        fd.append('canal', '<?= $canal ?>');
        fd.append('mensagem', msg);

        if (tabA) {
            var cid = document.getElementById('waNovaClienteId').value;
            if (!cid) { err.innerHTML = '<span style="color:#b91c1c">❌ Selecione um cliente</span>'; return; }
            fd.append('client_id', cid);
        } else {
            var tel = document.getElementById('waNovaTel').value.trim();
            if (!tel || tel.replace(/\D/g,'').length < 10) { err.innerHTML = '<span style="color:#b91c1c">❌ Informe um número válido (com DDD)</span>'; return; }
            fd.append('telefone', tel);
            fd.append('nome', document.getElementById('waNovaTelNome').value.trim());
        }

        document.getElementById('waNovaBtnEnv').disabled = true;
        err.innerHTML = '<span style="color:#64748b">⏳ Enviando...</span>';

        fetch(apiUrl, { method:'POST', body:fd })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                document.getElementById('waNovaBtnEnv').disabled = false;
                if (d.error) { err.innerHTML = '<span style="color:#b91c1c">❌ ' + d.error + '</span>'; return; }
                err.innerHTML = '<span style="color:#15803d">✅ Conversa criada!</span>';
                setTimeout(function() {
                    waFecharNovaConv();
                    carregarLista();
                    if (d.conversa_id) window.waAbrir(d.conversa_id);
                }, 800);
            })
            .catch(function(e) {
                document.getElementById('waNovaBtnEnv').disabled = false;
                err.innerHTML = '<span style="color:#b91c1c">❌ Erro de rede: ' + e.message + '</span>';
            });
    };

    // Atualiza fotos de perfil em batch (25 por vez via backend).
    // Roda até não achar mais conversas sem foto (ou atingir 8 batches = 200).
    window.waAtualizarFotos = function(btn) {
        if (!confirm('Buscar fotos de perfil do WhatsApp de todos os contatos?\n\nSe o contato for cliente cadastrado e ainda não tiver foto no cadastro, a foto do WhatsApp será salva no perfil dele (o cliente pode alterar depois pela Central VIP).')) return;
        var total = 0, atualizadosClientes = 0, chamadas = 0;
        var original = btn.textContent;
        btn.disabled = true;
        function proxima() {
            chamadas++;
            btn.textContent = '🖼️ Buscando... (' + total + ')';
            var fd = new FormData();
            fd.append('action', 'sync_fotos_todas');
            fd.append('canal', canal);
            fd.append('limit', '25');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', apiUrl);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                var r = {};
                try { r = JSON.parse(xhr.responseText); } catch(e) {}
                if (r && r.ok) {
                    total += r.com_foto || 0;
                    atualizadosClientes += r.clientes_atualizados || 0;
                    if ((r.total || 0) >= 25 && chamadas < 8) {
                        setTimeout(proxima, 400);
                        return;
                    }
                }
                btn.disabled = false;
                btn.textContent = original;
                alert('✓ Fotos atualizadas.\n\nContatos com foto: ' + total + '\nClientes com cadastro atualizado: ' + atualizadosClientes);
                carregarLista();
            };
            xhr.onerror = function() {
                btn.disabled = false;
                btn.textContent = original;
                alert('Erro de rede ao atualizar fotos.');
            };
            xhr.send(fd);
        }
        proxima();
    };

    window.waSincronizar = function() {
        alert('⚠️ Limitação da Z-API\n\nA Z-API não permite baixar o histórico do WhatsApp na versão Multi Device (que é a única disponível hoje).\n\nTodas as mensagens NOVAS (após a configuração do webhook) são capturadas em tempo real — essas ficam salvas aqui para sempre.\n\nMensagens anteriores só ficam no WhatsApp Web ou no celular.');
    };

    // Toggle rápido "Mostrar nomes dos atendentes" — aplica a todos os usuários
    window.waToggleNomes = function(btn) {
        if (!confirm('Alternar a exibição do nome do atendente acima de cada mensagem no chat INTERNO do Hub?\n\nEssa preferência vale pra equipe inteira.')) return;
        var csrf2 = window._FSA_CSRF || csrf;
        var fd = new FormData();
        fd.append('action', 'toggle_mostrar_nomes');
        fd.append('csrf_token', csrf2);
        btn.disabled = true;
        var original = btn.innerHTML;
        btn.innerHTML = '⏳ Aplicando...';
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.error) { alert('Falha: ' + d.error); btn.innerHTML = original; btn.disabled = false; return; }
                location.reload();
            })
            .catch(function(e){ alert('Erro: ' + e.message); btn.innerHTML = original; btn.disabled = false; });
    };

    // Toggle "Assinatura automática no WhatsApp do cliente" (externo)
    window.waToggleAssinatura = function(btn) {
        if (!confirm('Alternar a assinatura automática "— Nome" no fim das mensagens enviadas ao cliente (WhatsApp do celular dele)?\n\nAfeta todas as mensagens enviadas daqui em diante.')) return;
        var csrf2 = window._FSA_CSRF || csrf;
        var fd = new FormData();
        fd.append('action', 'toggle_assinatura');
        fd.append('csrf_token', csrf2);
        btn.disabled = true;
        var original = btn.innerHTML;
        btn.innerHTML = '⏳ Aplicando...';
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.error) { alert('Falha: ' + d.error); btn.innerHTML = original; btn.disabled = false; return; }
                location.reload();
            })
            .catch(function(e){ alert('Erro: ' + e.message); btn.innerHTML = original; btn.disabled = false; });
    };

    // Modal "Cores dos atendentes" — admin escolhe uma cor fixa pra cada atendente.
    // A cor aparece na borda esquerda da conversa no inbox.
    window.waAbrirCoresAtendentes = function() {
        var PALETA = [
            { nome: 'Vermelho', cor: '#dc2626' },
            { nome: 'Verde', cor: '#059669' },
            { nome: 'Lilás', cor: '#8b5cf6' },
            { nome: 'Azul', cor: '#2563eb' },
            { nome: 'Rosa', cor: '#ec4899' },
            { nome: 'Laranja', cor: '#f97316' },
            { nome: 'Amarelo', cor: '#eab308' },
            { nome: 'Ciano', cor: '#0891b2' },
            { nome: 'Verde-claro', cor: '#84cc16' },
            { nome: 'Marrom', cor: '#92400e' },
            { nome: 'Cinza', cor: '#64748b' },
            { nome: 'Petróleo', cor: '#052228' },
        ];
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';
        overlay.onclick = function(e){ if (e.target === overlay) overlay.remove(); };

        var fd = new FormData();
        fd.append('action', 'listar_atendentes_cores');
        fd.append('csrf_token', window._FSA_CSRF || '<?= generate_csrf_token() ?>');
        fetch('<?= module_url('whatsapp', 'api.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (j.error) { alert('Erro: ' + j.error); overlay.remove(); return; }
                var paletaHtml = PALETA.map(function(p){
                    return '<span class="cor-opt" data-cor="' + p.cor + '" title="' + p.nome + '" style="display:inline-block;width:24px;height:24px;border-radius:50%;background:' + p.cor + ';cursor:pointer;border:2px solid #fff;box-shadow:0 0 0 1px #d1d5db;margin:0 3px 3px 0;"></span>';
                }).join('');
                var userRows = j.usuarios.map(function(u){
                    var cor = u.wa_color || '';
                    return '<div data-uid="' + u.id + '" style="display:flex;align-items:center;gap:.6rem;padding:.5rem;border-bottom:1px solid #f3f4f6;">' +
                           '  <div class="cor-preview" style="width:16px;height:40px;border-radius:3px;background:' + (cor || '#e5e7eb') + ';flex-shrink:0;"></div>' +
                           '  <div style="flex:1;"><div style="font-weight:700;font-size:.82rem;">' + escapeHtml(u.name) + '</div><div style="font-size:.68rem;color:#6b7280;text-transform:uppercase;">' + u.role + '</div></div>' +
                           '  <div class="cor-wrap" style="display:flex;flex-wrap:wrap;justify-content:flex-end;max-width:260px;">' + paletaHtml + '</div>' +
                           '  <input type="color" class="cor-custom" value="' + (cor || '#cccccc') + '" style="width:28px;height:28px;padding:0;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;" title="Cor personalizada">' +
                           '  <button class="cor-clear" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:4px;padding:3px 7px;font-size:.66rem;cursor:pointer;" title="Usar cor automática (hash)">✕</button>' +
                           '</div>';
                }).join('');
                overlay.innerHTML = '<div style="background:#fff;border-radius:14px;padding:1.25rem;width:640px;max-width:95vw;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">' +
                    '<h3 style="margin:0 0 .25rem;font-size:1rem;color:#052228;">🎨 Cores dos atendentes</h3>' +
                    '<p style="margin:0 0 .9rem;font-size:.75rem;color:#6b7280;">Escolha uma cor pra cada atendente. A cor aparece na borda esquerda da conversa no WhatsApp CRM. Mudanças são aplicadas imediatamente. ✕ volta pra cor automática.</p>' +
                    '<div id="coresList" style="border:1px solid #e5e7eb;border-radius:8px;">' + userRows + '</div>' +
                    '<div style="display:flex;justify-content:flex-end;margin-top:1rem;">' +
                    '  <button id="coresFechar" style="padding:.55rem 1.2rem;background:#052228;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.82rem;">Fechar</button>' +
                    '</div>' +
                    '</div>';
                document.body.appendChild(overlay);
                document.getElementById('coresFechar').onclick = function(){ overlay.remove(); location.reload(); };

                // Handlers: clique em swatch, color picker custom, botão limpar
                overlay.querySelectorAll('[data-uid]').forEach(function(row){
                    var uid = row.getAttribute('data-uid');
                    var preview = row.querySelector('.cor-preview');
                    row.querySelectorAll('.cor-opt').forEach(function(opt){
                        opt.onclick = function(){ salvarCor(uid, opt.getAttribute('data-cor'), preview, row.querySelector('.cor-custom')); };
                    });
                    row.querySelector('.cor-custom').onchange = function(e){
                        salvarCor(uid, e.target.value, preview, e.target);
                    };
                    row.querySelector('.cor-clear').onclick = function(){
                        salvarCor(uid, '', preview, row.querySelector('.cor-custom'));
                    };
                });

                function salvarCor(uid, cor, preview, picker) {
                    var fd2 = new FormData();
                    fd2.append('action', 'salvar_atendente_cor');
                    fd2.append('user_id', uid);
                    fd2.append('cor', cor);
                    fd2.append('csrf_token', window._FSA_CSRF || '<?= generate_csrf_token() ?>');
                    fetch('<?= module_url('whatsapp', 'api.php') ?>', { method: 'POST', body: fd2, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r){ return r.json(); })
                        .then(function(j2){
                            if (j2.error) { alert('Erro: ' + j2.error); return; }
                            preview.style.background = cor || '#e5e7eb';
                            if (picker && cor) picker.value = cor;
                        });
                }
            });
    };

    // Modal "Meu nome de atendimento" — nome que aparece acima das próprias
    // mensagens no chat interno e na assinatura enviada ao cliente.
    // Padrão: primeiro + último sobrenome do cadastro (ex: "Amanda Guedes Ferreira"
    // → "Amanda Ferreira"). Editável por cada usuário.
    window.waAbrirMeuNome = function() {
        var overlay = document.createElement('div');
        overlay.id = 'waMeuNomeOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
        overlay.onclick = function(e){ if (e.target === overlay) overlay.remove(); };
        // Computa padrão "primeiro + último" do nome completo pra mostrar como exemplo
        var partes = (MEU_NOME_COMPLETO || '').trim().split(/\s+/);
        var padraoAuto = partes.length >= 2 ? (partes[0] + ' ' + partes[partes.length-1]) : (partes[0] || '');
        overlay.innerHTML = '<div style="background:#fff;border-radius:14px;padding:1.5rem;width:480px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.3);">'
            + '<h3 style="margin:0 0 .25rem;font-size:1rem;color:#052228;">✍️ Meu nome de atendimento</h3>'
            + '<p style="margin:0 0 1rem;font-size:.78rem;color:#6b7280;">É o nome que aparece acima das suas mensagens no chat interno e na assinatura enviada ao cliente (se estiver ligada em Configurações).</p>'
            + '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.6rem .8rem;margin-bottom:1rem;font-size:.8rem;">'
            +   '<div style="color:#6b7280;font-size:.7rem;margin-bottom:2px;">Nome no cadastro:</div>'
            +   '<div style="font-weight:600;color:#052228;">' + escapeHtml(MEU_NOME_COMPLETO) + '</div>'
            +   '<div style="color:#6b7280;font-size:.7rem;margin:6px 0 2px;">Padrão automático (primeiro + último):</div>'
            +   '<div style="font-weight:600;color:#052228;">' + escapeHtml(padraoAuto) + '</div>'
            + '</div>'
            + '<label style="font-size:.75rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem;">Nome personalizado (deixe vazio pra usar o padrão):</label>'
            + '<input type="text" id="waMeuNomeInput" maxlength="100" value="' + escapeHtml(MEU_NOME_CUSTOM) + '" placeholder="' + escapeHtml(padraoAuto) + '" style="width:100%;padding:.6rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:.9rem;margin-bottom:1rem;">'
            + '<p style="font-size:.7rem;color:#6b7280;margin:-.5rem 0 1rem;">Para não mostrar nome algum, vá em <strong>⚙️ Configurações → Automações</strong> e desligue "Mostrar nome do atendente" e/ou "Assinatura do atendente".</p>'
            + '<div style="display:flex;gap:.5rem;justify-content:flex-end;">'
            + '<button onclick="document.getElementById(\'waMeuNomeOverlay\').remove()" style="padding:.45rem 1rem;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:.8rem;">Cancelar</button>'
            + '<button id="waBtnSalvarNome" style="padding:.45rem 1rem;border:none;border-radius:8px;background:#b08d6e;color:#fff;cursor:pointer;font-weight:700;font-size:.8rem;">Salvar</button>'
            + '</div></div>';
        document.body.appendChild(overlay);
        document.getElementById('waMeuNomeInput').focus();
        document.getElementById('waBtnSalvarNome').onclick = function() {
            var novo = document.getElementById('waMeuNomeInput').value.trim();
            var fd = new FormData();
            fd.append('action', 'salvar_display_name');
            fd.append('csrf_token', csrf);
            fd.append('wa_display_name', novo);
            this.disabled = true; this.textContent = 'Salvando...';
            fetch(apiUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(r){
                if (r && r.error) { alert(r.error); return; }
                MEU_NOME_ATUAL = r.display_name || novo || padraoAuto;
                MEU_NOME_CUSTOM = novo;
                overlay.remove();
                if (convAtiva) window.waAbrir(convAtiva);
                alert('✓ Nome salvo: ' + MEU_NOME_ATUAL);
            });
        };
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
    // Só pros botões de status com data-filter — evita capturar select de atendente
    // e o botão de etiqueta (que tem handler próprio).
    document.querySelectorAll('button.wa-filter[data-filter]').forEach(function(b){
        b.addEventListener('click', function(){
            document.querySelectorAll('button.wa-filter[data-filter]').forEach(function(x){ x.classList.remove('active'); });
            b.classList.add('active');
            filtroAtual = b.dataset.filter;
            carregarLista();
        });
        // Se a URL trouxe ?status=X, já marca o botão correspondente como ativo
        if (b.dataset.filter === filtroAtual) {
            document.querySelectorAll('button.wa-filter[data-filter]').forEach(function(x){ x.classList.remove('active'); });
            b.classList.add('active');
        }
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

    // Helpers pra polling não-destrutivo
    function conversaHash(d) {
        if (!d || !d.mensagens) return '';
        var last = d.mensagens[d.mensagens.length - 1] || {};
        // count + id da última + status da última (pra detectar update de status)
        return d.mensagens.length + '|' + (last.id || '') + '|' + (last.status || '') + '|' + (last.transcricao ? 'T' : '');
    }
    function audioTocando() {
        var audios = document.querySelectorAll('#waChatBody audio, #waChatBody video');
        for (var i = 0; i < audios.length; i++) {
            if (!audios[i].paused) return true;
        }
        return false;
    }
    var ultimoHashConv = '';

    // Polling a cada 5s: atualiza lista + conversa aberta
    carregarLista();
    pollTimer = setInterval(function(){
        carregarLista();
        if (convAtiva) {
            // Se tem áudio/vídeo tocando, não atualiza (pra não reiniciar a reprodução)
            if (audioTocando()) return;

            fetch(apiUrl + '?action=abrir_conversa&id=' + convAtiva).then(function(r){ return r.json(); }).then(function(d){
                if (d.ok && d.mensagens) {
                    var h = conversaHash(d);
                    if (h === ultimoHashConv) return; // nada mudou, não rerenderiza
                    ultimoHashConv = h;

                    var body = document.getElementById('waChatBody');
                    var scrollAtBottom = (body.scrollHeight - body.scrollTop - body.clientHeight) < 50;
                    renderConversa(d);
                    if (!scrollAtBottom) body.scrollTop = body.scrollTop;
                }
            });
        }
    }, 5000);

    // Ao abrir manualmente uma conversa, reseta o hash pra forçar render
    var _origAbrir = window.waAbrir;
    window.waAbrir = function(id) {
        ultimoHashConv = '';
        return _origAbrir.apply(this, arguments);
    };
})();
</script>

<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
