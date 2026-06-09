<?php
/**
 * Ferreira & Sá Hub — Ver Chamado (completo)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$ticketId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.*, u.name as requester_name FROM tickets t
     LEFT JOIN users u ON u.id = t.requester_id WHERE t.id = ?'
);
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) { flash_set('error', 'Chamado não encontrado.'); redirect(module_url('helpdesk')); }

$pageTitle = 'Chamado #' . $ticket['id'];

// Responsáveis
$assignees = $pdo->prepare(
    'SELECT u.id, u.name FROM ticket_assignees ta JOIN users u ON u.id = ta.user_id WHERE ta.ticket_id = ?'
);
$assignees->execute([$ticketId]);
$assignees = $assignees->fetchAll();
$assigneeIds = array_column($assignees, 'id');

// Mensagens
$messages = $pdo->prepare(
    'SELECT tm.*, u.name as user_name FROM ticket_messages tm
     LEFT JOIN users u ON u.id = tm.user_id WHERE tm.ticket_id = ? ORDER BY tm.created_at ASC'
);
$messages->execute([$ticketId]);
$messages = $messages->fetchAll();

// Anexos (agrupados por message_id)
$anexosPorMsg = array();
try {
    $anexStmt = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id = ? ORDER BY id ASC");
    $anexStmt->execute(array($ticketId));
    foreach ($anexStmt->fetchAll() as $a) {
        $mid = (int)$a['message_id'];
        if (!isset($anexosPorMsg[$mid])) $anexosPorMsg[$mid] = array();
        $anexosPorMsg[$mid][] = $a;
    }
} catch (Exception $e) {}

// Cliente e Processo vinculados
$linkedClient = null; $linkedCase = null;
if (!empty($ticket['client_id'])) {
    $lc = $pdo->prepare("SELECT id, name, phone, email, cpf FROM clients WHERE id = ?");
    $lc->execute(array($ticket['client_id']));
    $linkedClient = $lc->fetch();
}
if (!empty($ticket['case_id'])) {
    $lcs = $pdo->prepare("SELECT id, title, case_number, case_type, status, court, responsible_user_id FROM cases WHERE id = ?");
    $lcs->execute(array($ticket['case_id']));
    $linkedCase = $lcs->fetch();
    if ($linkedCase && $linkedCase['responsible_user_id']) {
        $ru = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $ru->execute(array($linkedCase['responsible_user_id']));
        $linkedCase['responsible_name'] = $ru->fetchColumn() ?: null;
    }
}

// Outros chamados do mesmo cliente/processo
$relatedTickets = array();
if (!empty($ticket['client_id']) || !empty($ticket['case_id'])) {
    $relWhere = array();
    $relParams = array();
    if (!empty($ticket['client_id'])) {
        $relWhere[] = 'client_id = ?';
        $relParams[] = $ticket['client_id'];
    }
    if (!empty($ticket['case_id'])) {
        $relWhere[] = 'case_id = ?';
        $relParams[] = $ticket['case_id'];
    }
    $relParams[] = $ticketId;
    $relatedTickets = $pdo->prepare(
        "SELECT id, title, status, priority, created_at FROM tickets WHERE (" . implode(' OR ', $relWhere) . ") AND id != ? ORDER BY created_at DESC LIMIT 5"
    );
    $relatedTickets->execute($relParams);
    $relatedTickets = $relatedTickets->fetchAll();
}

$statusLabels = array('aberto' => 'Aberto', 'em_andamento' => 'Em andamento', 'aguardando' => 'Aguardando', 'resolvido' => 'Resolvido', 'cancelado' => 'Cancelado');
$statusBadge = array('aberto'=>'warning','em_andamento'=>'info','aguardando'=>'gestao','resolvido'=>'success','cancelado'=>'danger');
$priorBadge = array('urgente'=>'danger','normal'=>'gestao','baixa'=>'colaborador');
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

// Tempo aberto
$createdTs = strtotime($ticket['created_at']);
$tempoAberto = '';
if ($ticket['status'] === 'resolvido' && $ticket['resolved_at']) {
    $diff = strtotime($ticket['resolved_at']) - $createdTs;
    $tempoAberto = 'Resolvido em ' . format_duration($diff);
} else {
    $diff = time() - $createdTs;
    $tempoAberto = 'Aberto há ' . format_duration($diff);
}

function format_duration($seconds) {
    if ($seconds < 3600) return round($seconds / 60) . ' min';
    if ($seconds < 86400) return round($seconds / 3600) . 'h';
    $d = floor($seconds / 86400);
    $h = round(($seconds % 86400) / 3600);
    return $d . 'd' . ($h > 0 ? ' ' . $h . 'h' : '');
}

// SLA: prazo está atrasado?
$prazoAtrasado = false;
if ($ticket['due_date'] && !in_array($ticket['status'], array('resolvido', 'cancelado'))) {
    $prazoAtrasado = strtotime($ticket['due_date']) < strtotime('today');
}

// Helper: destacar @menções no texto (já HTML-escapado)
function highlight_mentions($text) {
    return preg_replace('/@([A-Za-zÀ-ÿ]+(?:\s[A-Za-zÀ-ÿ]+)?)/', '<span class="mention">@$1</span>', $text);
}

require_once APP_ROOT . '/templates/layout_start.php';
echo voltar_ao_processo_html();
?>

<style>
.tk-grid { display:grid; grid-template-columns:1fr 300px; gap:1rem; }
@media(max-width:900px){ .tk-grid{grid-template-columns:1fr;} }
.tk-info { display:grid; grid-template-columns:1fr 1fr; gap:.6rem; font-size:.82rem; }
.tk-info .tk-label { color:var(--text-muted); font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
.tk-info .tk-val { color:var(--petrol-900); font-weight:500; }
.msg-list { display:flex; flex-direction:column; gap:.75rem; }
.msg-item { padding:1rem; border-radius:var(--radius); border:1px solid var(--border); }
.msg-item.own { background:var(--petrol-100); border-color:var(--petrol-300); }
.msg-header { display:flex; justify-content:space-between; margin-bottom:.35rem; }
.msg-user { font-weight:700; font-size:.82rem; color:var(--petrol-900); }
.msg-date { font-size:.72rem; color:var(--text-muted); }
.msg-text { font-size:.88rem; white-space:pre-wrap; }
.msg-text .mention { background:#3B4FA0; color:#fff; font-weight:700; padding:2px 8px; border-radius:12px; cursor:default; font-size:.82em; letter-spacing:.3px; }
.mention-wrap { position:relative; }
.mention-dropdown { position:absolute; bottom:100%; left:0; right:0; background:#fff; border:1.5px solid var(--border); border-radius:8px; max-height:180px; overflow-y:auto; z-index:200; box-shadow:0 -4px 16px rgba(0,0,0,.12); display:none; }
.mention-dropdown.show { display:block; }
.mention-item { padding:.45rem .75rem; font-size:.82rem; cursor:pointer; display:flex; align-items:center; gap:.5rem; }
.mention-item:hover, .mention-item.active { background:#eff6ff; }
.mention-item .mi-avatar { width:24px; height:24px; border-radius:50%; background:var(--petrol-900); color:#fff; font-size:.6rem; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.mention-item .mi-name { font-weight:600; color:var(--petrol-900); }
.mention-item .mi-role { font-size:.68rem; color:var(--text-muted); margin-left:auto; }
.tk-sidebar .card { margin-bottom:.75rem; }
.tk-sidebar .card-body { padding:.75rem; }
.tk-sidebar h4 { font-size:.78rem; font-weight:700; color:var(--petrol-900); margin-bottom:.5rem; }
.tk-related { font-size:.78rem; padding:.4rem 0; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
.tk-related:last-child { border-bottom:none; }
.tk-time { font-size:.72rem; color:var(--text-muted); display:flex; align-items:center; gap:.3rem; }
</style>

<a href="<?= module_url('helpdesk') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<div class="tk-grid">
<!-- COLUNA PRINCIPAL -->
<div>

<!-- Cabeçalho -->
<div class="card mb-2">
    <div class="card-header" style="flex-wrap:wrap;gap:.5rem;">
        <div>
            <h3 style="margin-bottom:.2rem;">
                <?php if (!empty($ticket['pinned'])): ?><span title="Fixado no topo">📌</span> <?php endif; ?>
                #<?= $ticket['id'] ?> — <?= e($ticket['title']) ?>
            </h3>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <span class="text-sm text-muted">Por <?= e($ticket['requester_name']) ?> · <?= data_hora_br($ticket['created_at']) ?></span>
                <span class="tk-time"><?= $prazoAtrasado ? '🔴' : '🕐' ?> <?= $tempoAberto ?></span>
                <button type="button" onclick="toggleTicketPin(<?= (int)$ticket['id'] ?>, this)"
                        style="background:<?= !empty($ticket['pinned']) ? '#fbbf24' : 'transparent' ?>;border:1px solid <?= !empty($ticket['pinned']) ? '#d97706' : '#cbd5e1' ?>;color:<?= !empty($ticket['pinned']) ? '#78350f' : '#64748b' ?>;padding:3px 9px;border-radius:6px;font-size:.7rem;font-weight:600;cursor:pointer;"
                        title="<?= !empty($ticket['pinned']) ? 'Desafixar do topo' : 'Fixar no topo da lista' ?>">
                    <?= !empty($ticket['pinned']) ? '📌 Fixado — clique p/ desafixar' : '📌 Fixar no topo' ?>
                </button>
            </div>
        </div>
        <div class="flex gap-1" style="flex-wrap:wrap;">
            <span class="badge badge-<?= $statusBadge[$ticket['status']] ?? 'gestao' ?>">
                <?= $statusLabels[$ticket['status']] ?? $ticket['status'] ?>
            </span>
            <span class="badge badge-<?= $priorBadge[$ticket['priority']] ?? 'gestao' ?>">
                <?= ucfirst(e($ticket['priority'])) ?>
            </span>
            <?php if ($ticket['category']): ?>
            <span class="badge" style="background:var(--petrol-100);color:var(--petrol-900);"><?= e($ticket['category']) ?></span>
            <?php endif; ?>
            <?php if ($ticket['department']): ?>
            <span class="badge" style="background:#f0f9ff;color:#0369a1;"><?= e($ticket['department']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($ticket['description']): ?>
            <p style="white-space:pre-wrap;font-size:.9rem;margin-bottom:1rem;line-height:1.5;"><?= nl2br(e($ticket['description'])) ?></p>
        <?php else: ?>
            <p class="text-muted text-sm" style="font-style:italic;">Sem descrição</p>
        <?php endif; ?>

        <div class="tk-info" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
            <!-- Cliente -->
            <div>
                <div class="tk-label">Cliente</div>
                <div class="tk-val">
                    <?php if ($linkedClient): ?>
                        <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $linkedClient['id']) ?>" style="color:var(--petrol-900);font-weight:600;">
                            <?= e($linkedClient['name']) ?>
                        </a>
                        <?php if ($linkedClient['cpf']): ?>
                            <span style="color:var(--text-muted);font-size:.72rem;margin-left:.3rem;">(<?= e($linkedClient['cpf']) ?>)</span>
                        <?php endif; ?>
                    <?php elseif ($ticket['client_name']): ?>
                        <?= e($ticket['client_name']) ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Contato -->
            <div>
                <div class="tk-label">Contato</div>
                <div class="tk-val">
                    <?php
                    $phone = $linkedClient ? ($linkedClient['phone'] ?: '') : '';
                    if (!$phone) $phone = $ticket['client_contact'] ?? '';
                    $email = $linkedClient ? ($linkedClient['email'] ?: '') : '';
                    ?>
                    <?php if ($phone): ?>
                        <?php $_hdClientId = (int)($linkedClient['id'] ?? $ticket['client_id'] ?? 0); ?>
                        <?php $_hdNome = $linkedClient ? $linkedClient['name'] : ($ticket['client_name'] ?: ''); ?>
                        <a href="javascript:void(0)" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $phone) ?>',nome:<?= e(json_encode($_hdNome)) ?>,clientId:<?= $_hdClientId ?>})" style="color:#25D366;font-weight:600;">
                            📱 <?= e($phone) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($email): ?>
                        <span style="margin-left:.5rem;">📧 <?= e($email) ?></span>
                    <?php endif; ?>
                    <?php if (!$phone && !$email): ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Processo -->
            <div>
                <div class="tk-label">Processo vinculado</div>
                <div class="tk-val">
                    <?php if ($linkedCase): ?>
                        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $linkedCase['id']) ?>" style="color:var(--petrol-900);font-weight:600;">
                            📁 <?= e($linkedCase['title']) ?>
                        </a>
                        <?php if ($linkedCase['case_number']): ?>
                            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.15rem;">Nr: <?= e($linkedCase['case_number']) ?></div>
                        <?php endif; ?>
                        <?php if ($linkedCase['case_type']): ?>
                            <div style="font-size:.72rem;color:var(--text-muted);">Tipo: <?= e($linkedCase['case_type']) ?></div>
                        <?php endif; ?>
                        <?php if ($linkedCase['court']): ?>
                            <div style="font-size:.72rem;color:var(--text-muted);">Vara: <?= e($linkedCase['court']) ?></div>
                        <?php endif; ?>
                    <?php elseif ($ticket['case_number']): ?>
                        📁 <?= e($ticket['case_number']) ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Prazo / SLA -->
            <div>
                <div class="tk-label">Prazo / SLA</div>
                <div class="tk-val">
                    <?php if ($ticket['due_date']): ?>
                        <span style="<?= $prazoAtrasado ? 'color:#dc2626;font-weight:700;' : '' ?>">
                            <?= $prazoAtrasado ? '🔴 ATRASADO — ' : '⏰ ' ?><?= data_br($ticket['due_date']) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">Sem prazo definido</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Responsáveis -->
            <div>
                <div class="tk-label">Responsáveis</div>
                <div class="tk-val">
                    <?php if (!empty($assignees)): ?>
                        <?php foreach ($assignees as $a): ?>
                            <span style="display:inline-block;background:var(--petrol-100);color:var(--petrol-900);padding:1px 8px;border-radius:10px;font-size:.75rem;margin:1px 2px;">
                                <?= e($a['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-muted">Sem responsável</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Solicitante -->
            <div>
                <div class="tk-label">Solicitante</div>
                <div class="tk-val"><?= e($ticket['requester_name']) ?></div>
            </div>
            <?php if ($linkedCase && !empty($linkedCase['responsible_name'])): ?>
            <div>
                <div class="tk-label">Responsável pelo Processo</div>
                <div class="tk-val"><?= e($linkedCase['responsible_name']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($ticket['resolved_at']): ?>
            <div>
                <div class="tk-label">Resolvido em</div>
                <div class="tk-val"><?= data_hora_br($ticket['resolved_at']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Mensagens -->
<div class="card mb-2">
    <div class="card-header"><h3>Mensagens (<?= count($messages) ?>)</h3></div>
    <div class="card-body">
        <?php if (empty($messages)): ?>
            <p class="text-muted text-sm">Nenhuma mensagem ainda.</p>
        <?php else: ?>
            <div class="msg-list">
                <?php foreach ($messages as $msg): ?>
                <div class="msg-item <?= (int)$msg['user_id'] === current_user_id() ? 'own' : '' ?>">
                    <div class="msg-header">
                        <span class="msg-user"><?= e($msg['user_name']) ?></span>
                        <span style="display:flex;align-items:center;gap:.5rem;">
                            <span class="msg-date"><?= data_hora_br($msg['created_at']) ?></span>
                            <?php if ((int)$msg['user_id'] === current_user_id() || has_role('admin')): ?>
                            <button type="button" onclick="apagarMsg(<?= (int)$msg['id'] ?>)" title="Apagar mensagem" style="background:none;border:none;cursor:pointer;font-size:.75rem;opacity:.4;padding:0;line-height:1;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.4">🗑️</button>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="msg-text"><?= nl2br(highlight_mentions(e($msg['message']))) ?></div>
                    <?php
                    $anexosMsg = $anexosPorMsg[(int)$msg['id']] ?? array();
                    if (!empty($anexosMsg)): ?>
                    <div style="margin-top:.5rem; display:flex; flex-wrap:wrap; gap:6px;">
                        <?php foreach ($anexosMsg as $a):
                            $isImg = strpos($a['arquivo_mime'] ?? '', 'image/') === 0;
                            $downloadUrl = module_url('helpdesk', 'download.php?id=' . (int)$a['id']);
                            $kb = round((int)$a['arquivo_tamanho'] / 1024);
                        ?>
                            <?php if ($isImg): ?>
                                <a href="<?= e($downloadUrl) ?>" target="_blank" style="text-decoration:none;" title="<?= e($a['arquivo_nome']) ?> (<?= $kb ?>KB)">
                                    <img src="<?= e($downloadUrl) ?>" style="max-width:240px; max-height:200px; border:1px solid #e5e7eb; border-radius:8px; display:block;">
                                </a>
                            <?php else:
                                $ico = strpos($a['arquivo_mime'] ?? '','pdf')!==false ? '📄' :
                                       (strpos($a['arquivo_mime'] ?? '','word')!==false ? '📝' :
                                       (strpos($a['arquivo_mime'] ?? '','sheet')!==false || strpos($a['arquivo_mime'] ?? '','excel')!==false ? '📊' : '📎')); ?>
                                <a href="<?= e($downloadUrl) ?>" target="_blank" style="display:inline-flex; gap:6px; align-items:center; padding:6px 10px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#052228; font-size:.78rem;">
                                    <span><?= $ico ?></span>
                                    <span><?= e($a['arquivo_nome']) ?></span>
                                    <span style="color:#6b7280; font-size:.68rem;">(<?= $kb ?>KB)</span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Nova mensagem -->
        <form method="POST" action="<?= module_url('helpdesk', 'api.php') ?>" enctype="multipart/form-data" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);" id="formMsgHelpdesk" onsubmit="return validarEnvioMsgHelpdesk(this);">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_message">
            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
            <div class="mention-wrap">
                <div class="mention-dropdown" id="mentionDropdown"></div>
                <textarea name="message" id="msgTextarea" class="form-textarea" rows="3" placeholder="Escreva uma mensagem... Use @nome para mencionar alguém. Você pode enviar SÓ anexos (sem texto) também."></textarea>
            </div>

            <!-- Upload de anexos (printscreen, PDF, doc, etc.) -->
            <div id="hdAnexosPreview" style="display:none; margin-top:6px; padding:8px; background:#f9fafb; border:1px dashed #d1d5db; border-radius:8px;"></div>
            <input type="file" name="anexos[]" id="hdFileInput" multiple accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.txt" style="display:none;">

            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.35rem;gap:.5rem;flex-wrap:wrap;">
                <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                    <button type="button" onclick="document.getElementById('hdFileInput').click()" class="btn btn-outline btn-sm" title="Anexar printscreen, PDF ou documento (máx 5 arquivos, 10MB cada)">📎 Anexar</button>
                    <?php if (!empty($ticket['client_id'])): ?>
                    <button type="button" onclick="abrirInserirProcessos(<?= (int)$ticket['id'] ?>)" class="btn btn-outline btn-sm" style="border-color:#0ea5e9;color:#0369a1;" title="Inserir resumo dos processos do cliente (com últimos 3 andamentos e opção de tradução IA pra linguagem leiga)">📊 Inserir status dos processos</button>
                    <?php endif; ?>
                    <span style="font-size:.68rem;color:var(--text-muted);">💡 <strong>@</strong> menciona · Ctrl+V cola imagem direto</span>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Enviar</button>
            </div>
        </form>

<!-- Modal: Inserir status dos processos (Amanda 08/06/2026 v2 — resumo IA + tracking) -->
<div id="modalProcessos" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:14px;padding:1.4rem;max-width:820px;width:100%;max-height:92vh;overflow-y:auto;box-shadow:0 14px 40px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;">
            <h3 style="margin:0;color:var(--petrol-900);">📊 Inserir resumo dos processos</h3>
            <button onclick="document.getElementById('modalProcessos').style.display='none'" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;">✕</button>
        </div>
        <div id="mpAviso" style="display:none;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:.5rem .7rem;font-size:.78rem;color:#92400e;margin-bottom:.8rem;"></div>
        <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:.6rem .8rem;font-size:.78rem;color:#1e40af;margin-bottom:.8rem;">
            💡 Mostramos o <strong>resumo executivo gerado pela IA</strong> de cada processo. Se ainda não foi gerado, você pode gerar pelo botão "✨ Gerar agora". Processos já enviados neste chamado aparecem com ✅ e ficam desmarcados por padrão.
        </div>
        <label style="display:flex;align-items:center;gap:.45rem;cursor:pointer;font-size:.8rem;font-weight:600;margin-bottom:.7rem;padding:.45rem .6rem;background:#fafafa;border-radius:6px;border:1px solid #e5e7eb;">
            <input type="checkbox" id="mpSoPendentes" onchange="mpRender()" style="width:16px;height:16px;cursor:pointer;">
            <span>🎯 Esconder processos já enviados neste chamado</span>
        </label>
        <div id="mpCorpo" style="min-height:120px;">
            <div style="text-align:center;padding:2rem;color:#6b7280;">Carregando processos...</div>
        </div>
        <div style="display:flex;justify-content:space-between;gap:.5rem;margin-top:1rem;padding-top:.8rem;border-top:1px solid var(--border);">
            <button onclick="document.getElementById('modalProcessos').style.display='none'" class="btn btn-outline btn-sm">Cancelar</button>
            <button id="mpBtnInserir" onclick="mpInserirNaResposta()" class="btn btn-primary btn-sm" style="background:#0ea5e9;">📥 Inserir resumo na resposta</button>
        </div>
    </div>
</div>

<script>
window.mpDados = null;
window.mpTicketId = null;

function abrirInserirProcessos(ticketId) {
    var modal = document.getElementById('modalProcessos');
    var corpo = document.getElementById('mpCorpo');
    var aviso = document.getElementById('mpAviso');
    aviso.style.display = 'none';
    corpo.innerHTML = '<div style="text-align:center;padding:2rem;color:#6b7280;">Carregando processos...</div>';
    modal.style.display = 'flex';
    window.mpTicketId = ticketId;

    var fd = new FormData();
    fd.append('action', 'processos_do_chamado');
    fd.append('ticket_id', ticketId);
    fd.append('csrf_token', '<?= generate_csrf_token() ?>');
    fetch('<?= module_url("helpdesk", "api.php") ?>', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.error) {
            aviso.style.display = 'block';
            aviso.textContent = '⚠️ ' + d.error;
            corpo.innerHTML = '';
            return;
        }
        if (!d.cases || !d.cases.length) {
            corpo.innerHTML = '<div style="text-align:center;padding:2rem;color:#6b7280;">Este cliente não tem processos ativos cadastrados.</div>';
            return;
        }
        window.mpDados = d;
        mpRender();
    });
}

function mpRender() {
    var d = window.mpDados;
    if (!d) return;
    var soPendentes = document.getElementById('mpSoPendentes').checked;
    var html = '';
    var nVisiveis = 0;
    d.cases.forEach(function(c, idx) {
        if (soPendentes && c.ja_enviado_em) return;
        nVisiveis++;
        var jaEnviado = !!c.ja_enviado_em;
        var defaultChecked = !jaEnviado;
        var bgCard = jaEnviado ? '#f9fafb' : '#fff';
        var borderCard = jaEnviado ? '#d1d5db' : '#e5e7eb';

        html += '<div style="border:1px solid '+borderCard+';border-radius:10px;padding:.85rem;margin-bottom:.7rem;background:'+bgCard+';">';
        html += '<label style="display:flex;align-items:flex-start;gap:.55rem;cursor:pointer;">';
        html += '<input type="checkbox" class="mp-case" data-case-idx="'+idx+'" data-case-id="'+c.id+'" '+(defaultChecked?'checked':'')+' style="width:18px;height:18px;cursor:pointer;margin-top:2px;">';
        html += '<div style="flex:1;">';

        // Badge de "já enviado"
        if (jaEnviado) {
            html += '<div style="background:#dcfce7;border:1px solid #86efac;color:#166534;display:inline-block;padding:2px 10px;border-radius:10px;font-size:.66rem;font-weight:700;margin-bottom:.35rem;">✅ Já enviado neste chamado em '+mpEsc(c.ja_enviado_em)+'</div>';
        }

        // Header com info do processo
        html += '<div style="font-weight:700;color:var(--petrol-900);font-size:.94rem;">' + mpEsc(c.titulo) + '</div>';
        html += '<div style="font-size:.72rem;color:#6b7280;margin-top:.15rem;">';
        if (c.case_number) html += '⚖️ ' + mpEsc(c.case_number);
        if (c.court) html += (c.case_number ? ' · ' : '') + '🏛️ ' + mpEsc(c.court);
        if (c.status) html += ' · 📊 ' + mpEsc(c.status.replace(/_/g,' '));
        html += '</div>';

        // Conteúdo principal: resumo IA OU fallback
        if (c.ia_resumo) {
            var corResumo = c.ia_resumo_desatualizado ? '#92400e' : '#0369a1';
            var bgResumo = c.ia_resumo_desatualizado ? '#fef3c7' : '#eff6ff';
            var borderResumo = c.ia_resumo_desatualizado ? '#fbbf24' : '#bfdbfe';
            html += '<div id="mp_resumo_'+idx+'" style="background:'+bgResumo+';border:1px solid '+borderResumo+';border-radius:8px;padding:.55rem .75rem;margin-top:.55rem;font-size:.8rem;color:#1f2937;white-space:pre-wrap;line-height:1.45;">';
            html += '<div style="font-size:.66rem;color:'+corResumo+';font-weight:700;margin-bottom:.3rem;">✨ RESUMO EXECUTIVO IA <span style="font-weight:400;opacity:.8;">· gerado em '+mpEsc(c.ia_resumo_em || '—')+'</span>';
            if (c.ia_resumo_desatualizado) html += ' <span style="background:#f59e0b;color:#fff;padding:1px 6px;border-radius:6px;margin-left:6px;font-size:.62rem;">DESATUALIZADO</span>';
            html += '</div>';
            html += mpEsc(c.ia_resumo);
            html += '</div>';
            if (c.ia_resumo_desatualizado) {
                html += '<button type="button" onclick="event.preventDefault();event.stopPropagation();mpGerarResumo('+c.id+','+idx+')" style="margin-top:.4rem;background:#0ea5e9;color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:.74rem;font-weight:600;cursor:pointer;">🔄 Atualizar resumo (há andamento novo)</button>';
            }
        } else if (c.ultimo_andamento) {
            html += '<div style="background:#fafafa;border:1px dashed #d1d5db;border-radius:8px;padding:.55rem .75rem;margin-top:.55rem;font-size:.78rem;color:#374151;">';
            html += '<div style="font-size:.66rem;color:#6b7280;font-weight:700;margin-bottom:.25rem;">📅 ÚLTIMO ANDAMENTO (sem resumo IA gerado)</div>';
            html += '<div style="font-weight:600;color:#0369a1;">' + mpEsc(c.ultimo_andamento.data) + '</div>';
            html += '<div style="margin-top:.15rem;">' + mpEsc(c.ultimo_andamento.descricao) + '</div>';
            html += '</div>';
            html += '<button type="button" onclick="event.preventDefault();event.stopPropagation();mpGerarResumo('+c.id+','+idx+')" style="margin-top:.4rem;background:#0ea5e9;color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:.74rem;font-weight:600;cursor:pointer;">✨ Gerar resumo IA agora (~20s)</button>';
        } else {
            html += '<div style="background:#fafafa;border:1px dashed #d1d5db;border-radius:8px;padding:.55rem .75rem;margin-top:.55rem;font-size:.78rem;color:#9ca3af;font-style:italic;">Sem resumo IA e sem andamentos visíveis ao cliente.</div>';
            html += '<button type="button" onclick="event.preventDefault();event.stopPropagation();mpGerarResumo('+c.id+','+idx+')" style="margin-top:.4rem;background:#0ea5e9;color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:.74rem;font-weight:600;cursor:pointer;">✨ Gerar resumo IA mesmo assim</button>';
        }
        html += '</div></label></div>';
    });

    if (nVisiveis === 0) {
        html = '<div style="text-align:center;padding:2rem;color:#6b7280;">Nenhum processo pendente (todos já foram enviados neste chamado). Desmarque o filtro acima pra ver os já enviados.</div>';
    }
    document.getElementById('mpCorpo').innerHTML = html;
}

function mpGerarResumo(caseId, idx) {
    // Trava o botão clicado
    var btn = event.target;
    var textoOrig = btn.innerHTML;
    btn.innerHTML = '⏳ Gerando... (até 30s)';
    btn.disabled = true;
    btn.style.opacity = '.6';
    btn.style.cursor = 'wait';

    var fd = new FormData();
    fd.append('action', 'helpdesk_gerar_resumo_caso');
    fd.append('case_id', caseId);
    fd.append('csrf_token', '<?= generate_csrf_token() ?>');
    fetch('<?= module_url("helpdesk", "api.php") ?>', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.error) {
            alert('⚠️ ' + d.error);
            btn.innerHTML = textoOrig;
            btn.disabled = false;
            btn.style.opacity = '';
            btn.style.cursor = '';
            return;
        }
        // Atualiza o resumo no objeto local + re-renderiza
        window.mpDados.cases[idx].ia_resumo = d.texto;
        window.mpDados.cases[idx].ia_resumo_em = d.em;
        window.mpDados.cases[idx].ia_resumo_desatualizado = false;
        mpRender();
    }).catch(function(e){
        alert('⚠️ Falha de rede: ' + e.message);
        btn.innerHTML = textoOrig;
        btn.disabled = false;
        btn.style.opacity = '';
        btn.style.cursor = '';
    });
}

function mpInserirNaResposta() {
    var d = window.mpDados;
    if (!d) return;
    var selecionados = document.querySelectorAll('.mp-case:checked');
    if (!selecionados.length) { alert('Selecione ao menos 1 processo.'); return; }

    var texto = '📋 Atualizamos o status dos seus processos:\n\n';
    var idsIncluidos = [];
    selecionados.forEach(function(chk){
        var c = d.cases[parseInt(chk.dataset.caseIdx, 10)];
        idsIncluidos.push(c.id);
        texto += '━━━━━━━━━━━━━━━━━━━━━━━\n';
        texto += '🔹 ' + c.titulo + '\n';
        if (c.case_number) texto += '⚖️ Nº ' + c.case_number + '\n';
        if (c.court) texto += '🏛️ ' + c.court + '\n';
        texto += '\n';
        if (c.ia_resumo) {
            // Remove negritos markdown (**X**) pra ficar legível no chat
            var resumoLimpo = c.ia_resumo.replace(/\*\*(.+?)\*\*/g, '$1');
            texto += resumoLimpo + '\n';
        } else if (c.ultimo_andamento) {
            texto += 'Último andamento (' + c.ultimo_andamento.data + '):\n';
            texto += c.ultimo_andamento.descricao + '\n';
        } else {
            texto += '(sem movimentações recentes registradas)\n';
        }
        texto += '\n';
    });
    texto += '━━━━━━━━━━━━━━━━━━━━━━━\n\nQualquer dúvida, é só responder por aqui.\nAtenciosamente,\nEquipe Ferreira & Sá Advocacia';

    var ta = document.getElementById('msgTextarea');
    if (ta) {
        ta.value = (ta.value ? ta.value + '\n\n' : '') + texto;
        ta.focus();
        ta.scrollTop = ta.scrollHeight;
    }

    // Limpa hiddens antigos e adiciona os novos (cases_incluidos[])
    var form = document.getElementById('formMsgHelpdesk');
    if (form) {
        form.querySelectorAll('input[name="cases_incluidos[]"]').forEach(function(el){ el.remove(); });
        idsIncluidos.forEach(function(id){
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'cases_incluidos[]';
            inp.value = id;
            form.appendChild(inp);
        });
    }

    document.getElementById('modalProcessos').style.display = 'none';
}

function mpEsc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<script>
// Nilce r7 31/05/2026: clicar Enviar com mensagem E anexos vazios passava
// silenciosamente. Agora valida antes do submit (mensagem OU anexo).
function validarEnvioMsgHelpdesk(form) {
    var txt = (document.getElementById('msgTextarea').value || '').trim();
    var preview = document.getElementById('hdAnexosPreview');
    var temAnexo = preview && preview.style.display === 'block';
    if (!txt && !temAnexo) {
        alert('⚠️ Escreva uma mensagem ou anexe um arquivo antes de enviar.');
        document.getElementById('msgTextarea').focus();
        return false;
    }
    return true;
}
(function(){
    var fileInput = document.getElementById('hdFileInput');
    var preview = document.getElementById('hdAnexosPreview');
    var textarea = document.getElementById('msgTextarea');
    var form = document.getElementById('formMsgHelpdesk');
    var pendingFiles = [];

    function renderPreview() {
        if (pendingFiles.length === 0) { preview.style.display = 'none'; preview.innerHTML = ''; return; }
        preview.style.display = 'block';
        preview.innerHTML = pendingFiles.map(function(f, i){
            var icon = f.type.indexOf('image/') === 0 ? '🖼️' :
                       f.type.indexOf('pdf') >= 0 ? '📄' :
                       f.type.indexOf('word') >= 0 ? '📝' :
                       f.type.indexOf('sheet') >= 0 || f.type.indexOf('excel') >= 0 ? '📊' : '📎';
            var kb = Math.round(f.size/1024);
            return '<div style="display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:4px 8px; margin:3px; font-size:.75rem;">' +
                   icon + ' ' + (f.name.length > 28 ? f.name.substring(0,25)+'…' : f.name) +
                   ' <span style="color:#6b7280;">(' + kb + 'KB)</span>' +
                   ' <button type="button" onclick="window._hdRemoveFile(' + i + ')" style="background:none;border:none;color:#dc2626;cursor:pointer;font-weight:700;padding:0 4px;">✕</button>' +
                   '</div>';
        }).join('');
    }

    window._hdRemoveFile = function(idx) {
        pendingFiles.splice(idx, 1);
        syncFileInput();
        renderPreview();
    };

    function syncFileInput() {
        // FileInput não aceita atribuição direta de FileList. Usamos DataTransfer.
        var dt = new DataTransfer();
        pendingFiles.forEach(function(f){ dt.items.add(f); });
        fileInput.files = dt.files;
    }

    fileInput.addEventListener('change', function(e){
        var files = Array.from(e.target.files || []);
        files.forEach(function(f){
            if (f.size > 10 * 1024 * 1024) { alert('Arquivo "' + f.name + '" maior que 10MB — pulando.'); return; }
            if (pendingFiles.length >= 5) { alert('Máx 5 anexos por mensagem.'); return; }
            pendingFiles.push(f);
        });
        syncFileInput();
        renderPreview();
    });

    // Ctrl+V de imagem direto no textarea anexa como arquivo
    textarea.addEventListener('paste', function(e){
        var items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            if (it.kind === 'file' && it.type.indexOf('image/') === 0) {
                var blob = it.getAsFile();
                if (!blob) continue;
                if (pendingFiles.length >= 5) { alert('Máx 5 anexos.'); return; }
                // Renomeia pra algo reconhecível
                var ts = new Date().toISOString().replace(/[:.]/g,'-');
                var ext = (it.type.split('/')[1] || 'png').split('+')[0];
                var newFile = new File([blob], 'printscreen_' + ts + '.' + ext, { type: it.type });
                pendingFiles.push(newFile);
                syncFileInput();
                renderPreview();
                e.preventDefault();
            }
        }
    });

    // Se não tiver texto E não tiver anexo, bloqueia submit
    form.addEventListener('submit', function(e){
        if (!textarea.value.trim() && pendingFiles.length === 0) {
            e.preventDefault();
            alert('Escreva uma mensagem ou anexe ao menos um arquivo.');
        }
    });
})();
</script>
    </div>
</div>

</div><!-- /coluna principal -->

<!-- SIDEBAR -->
<div class="tk-sidebar">

<!-- Ações -->
<div class="card">
    <div class="card-body">
        <h4>Ações</h4>
        <form method="POST" action="<?= module_url('helpdesk', 'api.php') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Status</label>
                <select name="status" class="form-select" style="font-size:.82rem;">
                    <?php foreach ($statusLabels as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $ticket['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Prioridade</label>
                <select name="priority" class="form-select" style="font-size:.82rem;">
                    <option value="baixa" <?= $ticket['priority'] === 'baixa' ? 'selected' : '' ?>>Baixa</option>
                    <option value="normal" <?= $ticket['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="urgente" <?= $ticket['priority'] === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Categoria</label>
                <select name="category" class="form-select" style="font-size:.82rem;">
                    <option value="">—</option>
                    <?php foreach (array('Prazo','Audiência','WhatsApp','Documentos','Administrativo','Outros') as $cat): ?>
                    <option value="<?= $cat ?>" <?= $ticket['category'] === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Setor</label>
                <select name="department" class="form-select" style="font-size:.82rem;">
                    <option value="">—</option>
                    <?php foreach (array('Operacional','Comercial','CX','Financeiro','Administrativo','Marketing') as $dep): ?>
                    <option value="<?= $dep ?>" <?= $ticket['department'] === $dep ? 'selected' : '' ?>><?= $dep ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Prazo / SLA</label>
                <input type="date" name="due_date" class="form-input" style="font-size:.82rem;" value="<?= e($ticket['due_date'] ?? '') ?>">
            </div>

            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label" style="font-size:.72rem;">Responsáveis</label>
                <div style="max-height:150px;overflow-y:auto;font-size:.78rem;">
                    <?php foreach ($users as $u): ?>
                        <label style="display:flex;align-items:center;gap:.3rem;padding:2px 0;cursor:pointer;">
                            <input type="checkbox" name="assignees[]" value="<?= $u['id'] ?>" <?= in_array($u['id'], $assigneeIds) ? 'checked' : '' ?>>
                            <?= e($u['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Salvar Alterações</button>
        </form>
    </div>
</div>

<!-- Vínculos -->
<div class="card">
    <div class="card-body">
        <h4>Vínculos</h4>
        <form method="POST" action="<?= module_url('helpdesk', 'api.php') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_links">
            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Cliente</label>
                <select name="client_id" id="sideClientSelect" class="form-select" style="font-size:.82rem;" onchange="loadSideCases()">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)($ticket['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Processo</label>
                <select name="case_id" id="sideCaseSelect" class="form-select" style="font-size:.82rem;">
                    <option value="">— Nenhum —</option>
                    <?php if ($linkedCase): ?>
                    <option value="<?= $linkedCase['id'] ?>" selected><?= e($linkedCase['title']) ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Nome cliente (texto)</label>
                <input type="text" name="client_name" class="form-input" style="font-size:.82rem;" value="<?= e($ticket['client_name'] ?? '') ?>">
            </div>

            <div class="form-group" style="margin-bottom:.5rem;">
                <label class="form-label" style="font-size:.72rem;">Contato</label>
                <input type="text" name="client_contact" class="form-input" style="font-size:.82rem;" value="<?= e($ticket['client_contact'] ?? '') ?>">
            </div>

            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label" style="font-size:.72rem;">Nr Processo (texto)</label>
                <input type="text" name="case_number" class="form-input" style="font-size:.82rem;" value="<?= e($ticket['case_number'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-outline btn-sm" style="width:100%;">Atualizar Vínculos</button>
        </form>
    </div>
</div>

<!-- Chamados relacionados -->
<?php if (!empty($relatedTickets)): ?>
<div class="card">
    <div class="card-body">
        <h4>Chamados Relacionados</h4>
        <?php foreach ($relatedTickets as $rt): ?>
        <div class="tk-related">
            <a href="<?= module_url('helpdesk', 'ver.php?id=' . $rt['id']) ?>" style="color:var(--petrol-900);font-weight:500;">
                #<?= $rt['id'] ?> <?= e(mb_substr($rt['title'], 0, 30)) ?><?= mb_strlen($rt['title']) > 30 ? '...' : '' ?>
            </a>
            <span class="badge badge-<?= $statusBadge[$rt['status']] ?? 'gestao' ?>" style="font-size:.6rem;">
                <?= $statusLabels[$rt['status']] ?? $rt['status'] ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Info -->
<div class="card">
    <div class="card-body">
        <h4>Informações</h4>
        <div style="font-size:.75rem;color:var(--text-muted);line-height:1.8;">
            <div>Criado: <?= data_hora_br($ticket['created_at']) ?></div>
            <div>Atualizado: <?= data_hora_br($ticket['updated_at']) ?></div>
            <?php if ($ticket['resolved_at']): ?>
            <div>Resolvido: <?= data_hora_br($ticket['resolved_at']) ?></div>
            <?php endif; ?>
            <div>Mensagens: <?= count($messages) ?></div>
        </div>
    </div>
</div>

</div><!-- /sidebar -->
</div><!-- /grid -->

<script>
function loadSideCases() {
    var clientId = document.getElementById('sideClientSelect').value;
    var select = document.getElementById('sideCaseSelect');
    select.innerHTML = '<option value="">— Nenhum —</option>';
    if (!clientId) return;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '<?= module_url("helpdesk", "novo.php") ?>?ajax_cases=1&client_id=' + clientId);
    xhr.onload = function() {
        try {
            var cases = JSON.parse(xhr.responseText);
            for (var i = 0; i < cases.length; i++) {
                var opt = document.createElement('option');
                opt.value = cases[i].id;
                opt.textContent = cases[i].title + (cases[i].case_number ? ' — ' + cases[i].case_number : '');
                select.appendChild(opt);
            }
        } catch(e) {}
    };
    xhr.send();
}
<?php if (!empty($ticket['client_id']) && empty($linkedCase)): ?>
loadSideCases();
<?php endif; ?>

// ── Fixar / Desafixar chamado no topo ──
function toggleTicketPin(ticketId, btn) {
    var original = btn.innerHTML;
    btn.innerHTML = '⏳ Atualizando...';
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'toggle_pin');
    fd.append('ticket_id', ticketId);
    fd.append('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    fetch('<?= module_url("helpdesk", "api.php") ?>', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j.error) { alert(j.error); btn.innerHTML = original; btn.disabled = false; return; }
            if (j.pinned) {
                btn.style.background = '#fbbf24'; btn.style.borderColor = '#d97706'; btn.style.color = '#78350f';
                btn.innerHTML = '📌 Fixado — clique p/ desafixar';
                btn.title = 'Desafixar do topo';
            } else {
                btn.style.background = 'transparent'; btn.style.borderColor = '#cbd5e1'; btn.style.color = '#64748b';
                btn.innerHTML = '📌 Fixar no topo';
                btn.title = 'Fixar no topo da lista';
            }
            btn.disabled = false;
        })
        .catch(function(){ alert('Erro ao atualizar.'); btn.innerHTML = original; btn.disabled = false; });
}

// ── Apagar mensagem ──
function apagarMsg(msgId) {
    if (!confirm('Apagar esta mensagem?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= module_url("helpdesk", "api.php") ?>';
    function addH(n, v) { var i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); }
    addH('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    addH('action', 'delete_message');
    addH('message_id', msgId);
    addH('ticket_id', '<?= $ticketId ?>');
    document.body.appendChild(form);
    form.submit();
}

// ── @Mention Autocomplete ──
(function(){
    var users = <?= json_encode(array_map(function($u) { return array('id' => (int)$u['id'], 'name' => $u['name'], 'initials' => mb_strtoupper(mb_substr($u['name'], 0, 2, 'UTF-8'), 'UTF-8')); }, $users), JSON_UNESCAPED_UNICODE) ?>;
    var textarea = document.getElementById('msgTextarea');
    var dropdown = document.getElementById('mentionDropdown');
    var activeIdx = -1;
    var mentionStart = -1;

    textarea.addEventListener('input', function() {
        var val = textarea.value;
        var pos = textarea.selectionStart;

        // Encontrar @ mais recente antes do cursor
        var before = val.substring(0, pos);
        var atIdx = before.lastIndexOf('@');

        if (atIdx === -1 || (atIdx > 0 && before[atIdx - 1] !== ' ' && before[atIdx - 1] !== '\n')) {
            closeMention();
            return;
        }

        var query = before.substring(atIdx + 1);
        // Se tem espaço duplo ou newline depois do @, fechar
        if (/\n/.test(query) || /\s{2,}/.test(query)) {
            closeMention();
            return;
        }

        mentionStart = atIdx;
        var q = query.toLowerCase();
        var filtered = [];
        for (var i = 0; i < users.length; i++) {
            var firstName = users[i].name.split(' ')[0].toLowerCase();
            var fullName = users[i].name.toLowerCase();
            if (firstName.indexOf(q) === 0 || fullName.indexOf(q) === 0) {
                filtered.push(users[i]);
            }
        }

        if (filtered.length === 0) {
            closeMention();
            return;
        }

        activeIdx = 0;
        renderDropdown(filtered);
    });

    textarea.addEventListener('keydown', function(e) {
        if (!dropdown.classList.contains('show')) return;
        var items = dropdown.querySelectorAll('.mention-item');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = (activeIdx + 1) % items.length;
            updateActive(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = (activeIdx - 1 + items.length) % items.length;
            updateActive(items);
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (items.length > 0 && activeIdx >= 0) {
                e.preventDefault();
                selectUser(items[activeIdx].dataset.name);
            }
        } else if (e.key === 'Escape') {
            closeMention();
        }
    });

    function renderDropdown(list) {
        var html = '';
        for (var i = 0; i < list.length; i++) {
            html += '<div class="mention-item' + (i === activeIdx ? ' active' : '') + '" data-name="' + esc(list[i].name.split(' ')[0]) + '" data-full="' + esc(list[i].name) + '">'
                + '<span class="mi-avatar">' + esc(list[i].initials) + '</span>'
                + '<span class="mi-name">' + esc(list[i].name) + '</span>'
                + '</div>';
        }
        dropdown.innerHTML = html;
        dropdown.classList.add('show');

        dropdown.querySelectorAll('.mention-item').forEach(function(item) {
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                selectUser(item.dataset.name);
            });
        });
    }

    function selectUser(firstName) {
        var val = textarea.value;
        var before = val.substring(0, mentionStart);
        var after = val.substring(textarea.selectionStart);
        textarea.value = before + '@' + firstName + ' ' + after;
        var newPos = mentionStart + firstName.length + 2;
        textarea.selectionStart = newPos;
        textarea.selectionEnd = newPos;
        textarea.focus();
        closeMention();
    }

    function closeMention() {
        dropdown.classList.remove('show');
        dropdown.innerHTML = '';
        activeIdx = -1;
        mentionStart = -1;
    }

    function updateActive(items) {
        items.forEach(function(it, i) {
            it.classList.toggle('active', i === activeIdx);
        });
    }

    textarea.addEventListener('blur', function() {
        setTimeout(closeMention, 150);
    });

    function esc(s) { if(!s)return''; var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
