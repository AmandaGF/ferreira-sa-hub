<?php
/**
 * Ferreira & Sa Hub -- Central VIP -- Ver / Responder Thread
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pdo = db();
$threadId = (int)($_GET['thread_id'] ?? 0);

// ── Carregar thread ─────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT t.*, c.name as client_name, c.email as client_email, c.phone as client_phone
     FROM salavip_threads t
     JOIN clients c ON c.id = t.cliente_id
     WHERE t.id = ?"
);
$stmt->execute([$threadId]);
$thread = $stmt->fetch();

if (!$thread) {
    flash_set('error', 'Conversa nao encontrada.');
    redirect(module_url('salavip'));
}

$pageTitle = e($thread['assunto']);

// ── Marcar como lidas ───────────────────────────────────
$pdo->prepare(
    "UPDATE salavip_mensagens SET lida_equipe = 1 WHERE thread_id = ? AND lida_equipe = 0"
)->execute([$threadId]);

// ── POST: Responder ou Fechar ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? 'responder';

    if ($action === 'fechar_thread') {
        $motivo = trim($_POST['motivo_fechamento'] ?? '');
        $pdo->prepare("UPDATE salavip_threads SET status = 'fechada', atualizado_em = NOW() WHERE id = ?")->execute([$threadId]);
        // Registra motivo opcional como mensagem interna (só equipe vê — origem=conecta)
        if ($motivo !== '') {
            $userName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $userName->execute([current_user_id()]);
            $userName = $userName->fetchColumn() ?: 'Equipe';
            $pdo->prepare("INSERT INTO salavip_mensagens (thread_id, origem, remetente_id, remetente_nome, mensagem, lida_equipe, lida_cliente, criado_em)
                           VALUES (?, 'conecta', ?, ?, ?, 1, 0, NOW())")
                ->execute(array($threadId, current_user_id(), $userName, '[Conversa fechada sem resposta] Motivo: ' . $motivo));
        }
        audit_log('salavip_thread_fechar', 'salavip_threads', $threadId, $motivo);
        flash_set('success', 'Conversa fechada.');
        redirect(module_url('salavip'));
    }

    if ($action === 'apagar_msg') {
        $msgId = (int)($_POST['msg_id'] ?? 0);
        // Só apaga mensagens da EQUIPE (não do cliente)
        $pdo->prepare("DELETE FROM salavip_mensagens WHERE id = ? AND thread_id = ? AND origem = 'conecta'")
            ->execute(array($msgId, $threadId));
        audit_log('salavip_msg_apagar', 'salavip_mensagens', $msgId);
        flash_set('success', 'Mensagem apagada.');
        redirect(module_url('salavip', 'ver_mensagem.php?thread_id=' . $threadId));
    }

    if ($action === 'alterar_status') {
        $novoStatus = $_POST['novo_status'] ?? '';
        $validos = array('aberta','respondida','aguardando','fechada');
        if (in_array($novoStatus, $validos, true)) {
            $pdo->prepare("UPDATE salavip_threads SET status = ?, atualizado_em = NOW() WHERE id = ?")->execute(array($novoStatus, $threadId));
            audit_log('salavip_thread_status', 'salavip_threads', $threadId, "→ {$novoStatus}");
            flash_set('success', 'Status alterado para "' . $novoStatus . '".');
        } else {
            flash_set('error', 'Status inválido.');
        }
        redirect(module_url('salavip', 'ver_mensagem.php?thread_id=' . $threadId));
    }

    if ($action === 'responder') {
        $mensagem = trim($_POST['mensagem'] ?? '');
        if (!$mensagem) {
            flash_set('error', 'Digite uma mensagem.');
            redirect(module_url('salavip', 'ver_mensagem.php?thread_id=' . $threadId));
        }

        // Get current user name
        $userName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $userName->execute([current_user_id()]);
        $userName = $userName->fetchColumn() ?: 'Equipe';

        // Handle optional attachment
        $anexo = null;
        $anexoNome = null;
        if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
            $allowedExt = array('pdf', 'jpg', 'jpeg', 'png', 'docx');
            $ext = strtolower(pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt) && $_FILES['anexo']['size'] <= 10 * 1024 * 1024) {
                $dir = APP_ROOT . '/salavip/uploads/mensagens/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $anexo = uniqid('msg_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['anexo']['name']);
                move_uploaded_file($_FILES['anexo']['tmp_name'], $dir . $anexo);
                $anexoNome = $_FILES['anexo']['name'];
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO salavip_mensagens (thread_id, origem, remetente_id, remetente_nome, mensagem, anexo_path, anexo_nome, lida_equipe, lida_cliente, criado_em)
             VALUES (?, 'conecta', ?, ?, ?, ?, ?, 1, 0, NOW())"
        );
        $stmt->execute([$threadId, current_user_id(), $userName, $mensagem, $anexo, $anexoNome]);

        // Update thread status
        $pdo->prepare("UPDATE salavip_threads SET status = 'respondida', atualizado_em = NOW() WHERE id = ?")->execute([$threadId]);

        // Create notification for client (if notifications table exists)
        try {
            $pdo->prepare(
                "INSERT INTO notifications (user_id, type, title, message, link, created_at)
                 SELECT su.cliente_id, 'salavip', 'Nova resposta na Central VIP', ?, ?, NOW()
                 FROM salavip_usuarios su
                 JOIN salavip_threads t ON t.cliente_id = su.cliente_id
                 WHERE t.id = ?"
            )->execute([
                mb_strimwidth($mensagem, 0, 100, '...'),
                '/salavip/thread.php?id=' . $threadId,
                $threadId
            ]);
        } catch (Exception $e) {
            // Notifications table may not exist yet, ignore
        }

        audit_log('salavip_responder', 'salavip_threads', $threadId);
        flash_set('success', 'Resposta enviada.');
        redirect(module_url('salavip', 'ver_mensagem.php?thread_id=' . $threadId));
    }
}

// ── Carregar mensagens ──────────────────────────────────
$mensagens = $pdo->prepare(
    "SELECT * FROM salavip_mensagens WHERE thread_id = ? ORDER BY criado_em ASC"
);
$mensagens->execute([$threadId]);
$mensagens = $mensagens->fetchAll();

// ── Respostas padrão (seed em migrar_salavip_respostas.php) ──
$respostasPadrao = array();
try {
    $respostasPadrao = $pdo->query("SELECT id, titulo, texto FROM salavip_respostas_padrao WHERE ativo = 1 ORDER BY ordem, titulo")->fetchAll();
} catch (Exception $e) { /* tabela pode não existir ainda */ }

$statusLabels = array(
    'aberta' => 'Aberta', 'respondida' => 'Respondida', 'fechada' => 'Fechada',
    'aguardando' => 'Aguardando'
);
$statusBadge = array(
    'aberta' => 'warning', 'respondida' => 'success', 'fechada' => 'danger',
    'aguardando' => 'info'
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.msg-thread { display:flex; flex-direction:column; gap:.75rem; margin-bottom:1.5rem; }
.msg-bubble { max-width:75%; padding:.85rem 1rem; border-radius:var(--radius-lg); font-size:.88rem; line-height:1.5; }
.msg-bubble.equipe { background:var(--petrol-100); border:1px solid var(--petrol-300); align-self:flex-end; border-bottom-right-radius:4px; }
.msg-bubble.cliente { background:var(--bg-card); border:1px solid var(--border); align-self:flex-start; border-bottom-left-radius:4px; }
.msg-meta { font-size:.7rem; color:var(--text-muted); margin-top:.3rem; display:flex; justify-content:space-between; gap:.5rem; }
.msg-sender { font-weight:700; font-size:.78rem; margin-bottom:.2rem; }
.msg-anexo { font-size:.75rem; margin-top:.35rem; padding-top:.25rem; border-top:1px solid rgba(0,0,0,.08); }
.thread-info { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:.5rem; font-size:.82rem; margin-bottom:1rem; }
.thread-info dt { color:var(--text-muted); font-size:.72rem; font-weight:600; text-transform:uppercase; }
.thread-info dd { color:var(--petrol-900); font-weight:500; margin:0 0 .5rem 0; }
</style>

<?php
// Detectar de onde o usuário veio pra o botão Voltar ficar consistente
$backUrl = module_url('salavip');
$backLabel = 'Voltar à Central VIP';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref && strpos($ref, '/modules/helpdesk/') !== false && strpos($ref, 'origem=clientes') !== false) {
    $backUrl = url('modules/helpdesk/?origem=clientes');
    $backLabel = 'Voltar aos Chamados de Clientes';
}
?>
<a href="<?= e($backUrl) ?>" class="btn btn-outline btn-sm mb-2">&larr; <?= e($backLabel) ?></a>

<!-- Thread Header -->
<div class="card mb-2">
    <div class="card-header" style="flex-wrap:wrap;gap:.5rem;">
        <div>
            <h3 style="margin-bottom:.2rem;"><?= e($thread['assunto']) ?></h3>
            <span class="text-sm text-muted">Por <?= e($thread['client_name']) ?> &middot; <?= date('d/m/Y H:i', strtotime($thread['criado_em'])) ?></span>
        </div>
        <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
            <span class="badge badge-<?= $statusBadge[$thread['status']] ?? 'gestao' ?>">
                <?= $statusLabels[$thread['status']] ?? e($thread['status']) ?>
            </span>
            <?php if (!empty($thread['categoria'])): ?>
                <span class="badge" style="background:var(--petrol-100);color:var(--petrol-900);"><?= e($thread['categoria']) ?></span>
            <?php endif; ?>
            <form method="POST" style="display:inline-flex;align-items:center;gap:4px;margin-left:.5rem;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="alterar_status">
                <label style="font-size:.7rem;color:var(--text-muted);">Alterar para:</label>
                <select name="novo_status" onchange="if(confirm('Alterar status para ' + this.options[this.selectedIndex].text + '?')) this.form.submit(); else this.value = '';" class="form-control" style="font-size:.75rem;padding:3px 8px;max-width:180px;">
                    <option value="">Escolher...</option>
                    <?php if ($thread['status'] !== 'aberta'): ?><option value="aberta">🟡 Reabrir (Aberta)</option><?php endif; ?>
                    <?php if ($thread['status'] !== 'respondida'): ?><option value="respondida">🔵 Em andamento</option><?php endif; ?>
                    <?php if ($thread['status'] !== 'aguardando'): ?><option value="aguardando">🟠 Aguardando cliente</option><?php endif; ?>
                    <?php if ($thread['status'] !== 'fechada'): ?><option value="fechada">✅ Fechar / Resolvido</option><?php endif; ?>
                </select>
            </form>
        </div>
    </div>
    <div class="card-body">
        <dl class="thread-info">
            <div>
                <dt>Cliente</dt>
                <dd><?= e($thread['client_name']) ?></dd>
            </div>
            <?php if (!empty($thread['client_email'])): ?>
            <div>
                <dt>Email</dt>
                <dd><?= e($thread['client_email']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($thread['client_phone'])): ?>
            <div>
                <dt>Telefone</dt>
                <dd><?= e($thread['client_phone']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($thread['processo_id'])): ?>
            <div>
                <dt>Processo</dt>
                <dd>
                    <?php
                    $proc = $pdo->prepare("SELECT case_number, title FROM cases WHERE id = ?");
                    $proc->execute([$thread['processo_id']]);
                    $proc = $proc->fetch();
                    echo $proc ? e($proc['case_number'] . ' - ' . $proc['title']) : '—';
                    ?>
                </dd>
            </div>
            <?php endif; ?>
        </dl>
    </div>
</div>

<!-- Messages -->
<div class="card mb-2">
    <div class="card-header"><h3>Mensagens</h3></div>
    <div class="card-body">
        <div class="msg-thread">
            <?php if (empty($mensagens)): ?>
                <p class="text-muted text-sm" style="text-align:center;">Nenhuma mensagem nesta conversa.</p>
            <?php else: ?>
                <?php foreach ($mensagens as $m): ?>
                    <div class="msg-bubble <?= $m['origem'] === 'conecta' ? 'equipe' : 'cliente' ?>" style="position:relative;">
                        <div class="msg-sender">
                            <?= e($m['remetente_nome']) ?>
                            <span style="font-weight:400;color:var(--text-muted);font-size:.7rem;">
                                (<?= $m['origem'] === 'conecta' ? 'Equipe' : 'Cliente' ?>)
                            </span>
                            <?php if ($m['origem'] === 'conecta'): ?>
                                <form method="POST" style="display:inline;float:right;" onsubmit="return confirm('Apagar esta mensagem? Esta ação não pode ser desfeita.');">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="apagar_msg">
                                    <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                                    <button type="submit" style="background:transparent;border:none;color:#ef4444;cursor:pointer;font-size:.8rem;padding:2px 6px;" title="Apagar mensagem">🗑</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div><?= nl2br(e($m['mensagem'])) ?></div>
                        <?php if (!empty($m['anexo_path'])): ?>
                            <div class="msg-anexo">
                                &#128206; <a href="<?= url('salavip/uploads/mensagens/' . $m['anexo_path']) ?>" target="_blank"><?= e($m['anexo_nome'] ?: $m['anexo_path']) ?></a>
                            </div>
                        <?php endif; ?>
                        <div class="msg-meta">
                            <span><?= date('d/m/Y H:i', strtotime($m['criado_em'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reply Form -->
<?php if ($thread['status'] !== 'fechada'): ?>
<div class="card mb-2">
    <div class="card-header"><h3>Responder</h3></div>
    <div class="card-body">
        <?php if (!empty($respostasPadrao)): ?>
            <?php
            // Helper: infere tema visual a partir do titulo (sem precisar de coluna na tabela).
            // Cada tema = [bg-light, border, text, hover-bg]
            $vipTemaResposta = function($titulo) {
                $t = mb_strtolower($titulo);
                if (strpos($t,'boas-vindas')!==false || strpos($t,'olá')!==false || strpos($t,'ola')!==false || strpos($t,'bem-vind')!==false) {
                    return array('#ecfdf5','#86efac','#065f46','#d1fae5'); // verde
                }
                if (strpos($t,'docs')!==false || strpos($t,'document')!==false || strpos($t,'recebido')!==false) {
                    return array('#eff6ff','#93c5fd','#1e40af','#dbeafe'); // azul
                }
                if (strpos($t,'análise')!==false || strpos($t,'analise')!==false || strpos($t,'analis')!==false || strpos($t,'aguard')!==false) {
                    return array('#fffbeb','#fcd34d','#92400e','#fef3c7'); // ambar
                }
                if (strpos($t,'duplicad')!==false || strpos($t,'fechad')!==false || strpos($t,'já')!==false) {
                    return array('#f3f4f6','#d1d5db','#374151','#e5e7eb'); // cinza
                }
                if (strpos($t,'contato')!==false || strpos($t,'ligar')!==false || strpos($t,'entraremos')!==false || strpos($t,'retorn')!==false) {
                    return array('#ecfeff','#67e8f9','#155e75','#cffafe'); // ciano
                }
                if (strpos($t,'audiência')!==false || strpos($t,'audiencia')!==false || strpos($t,'reunião')!==false || strpos($t,'reuniao')!==false) {
                    return array('#faf5ff','#d8b4fe','#6b21a8','#f3e8ff'); // roxo
                }
                if (strpos($t,'acordo')!==false || strpos($t,'negocia')!==false || strpos($t,'proposta')!==false) {
                    return array('#fff7ed','#fdba74','#9a3412','#ffedd5'); // laranja
                }
                if (strpos($t,'resolv')!==false || strpos($t,'conclu')!==false || strpos($t,'finaliz')!==false) {
                    return array('#ecfdf5','#34d399','#065f46','#d1fae5'); // esmeralda
                }
                return array('#f9fafb','#e5e7eb','#374151','#f3f4f6'); // neutro
            };
            ?>
            <div style="margin-bottom:.9rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.45rem;">
                    <div style="font-size:.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;">⚡ Respostas rápidas <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#9ca3af;font-size:.7rem;">— passe o mouse para ver o texto · clique para inserir</span></div>
                    <button type="button" id="btnLimparResp" onclick="vipLimparResposta()" style="background:none;border:none;color:#9ca3af;font-size:.72rem;cursor:pointer;padding:.2rem .5rem;border-radius:6px;display:none;" title="Limpar campo de resposta">↺ Limpar</button>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem;" id="vipRespostasRapidas">
                    <?php foreach ($respostasPadrao as $rp):
                        list($bg, $bd, $tx, $hov) = $vipTemaResposta($rp['titulo']);
                        $preview = mb_substr(trim($rp['texto']), 0, 220);
                        if (mb_strlen($rp['texto']) > 220) $preview .= '...';
                    ?>
                        <button type="button"
                                class="vip-rapida-btn"
                                data-texto="<?= htmlspecialchars($rp['texto'], ENT_QUOTES) ?>"
                                data-preview="<?= htmlspecialchars($preview, ENT_QUOTES) ?>"
                                style="background:<?= $bg ?>;border:1.5px solid <?= $bd ?>;color:<?= $tx ?>;padding:.45rem .85rem;border-radius:18px;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s ease;line-height:1.2;">
                            <?= e($rp['titulo']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <!-- Preview tooltip (criado por JS, mostrado on hover) -->
                <div id="vipPreviewTip" style="display:none;position:absolute;z-index:1000;background:#1f2937;color:#fff;font-size:.78rem;line-height:1.45;padding:.6rem .8rem;border-radius:8px;max-width:380px;box-shadow:0 4px 12px rgba(0,0,0,.25);white-space:pre-wrap;pointer-events:none;"></div>
            </div>

            <style>
            .vip-rapida-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 3px 8px rgba(0,0,0,.08);
                filter: brightness(0.97);
            }
            .vip-rapida-btn:active {
                transform: translateY(0);
            }
            .vip-rapida-btn.vip-selecionada {
                box-shadow: 0 0 0 2px currentColor, 0 2px 6px rgba(0,0,0,.08);
                font-weight: 700;
            }
            @keyframes vipPulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.06); }
                100% { transform: scale(1); }
            }
            .vip-rapida-btn.vip-pulse { animation: vipPulse .3s ease; }
            </style>

            <script>
            (function(){
                var tip = document.getElementById('vipPreviewTip');
                var btns = document.querySelectorAll('.vip-rapida-btn');
                var btnLimpar = document.getElementById('btnLimparResp');
                var txt = document.getElementById('txtResposta');

                btns.forEach(function(btn){
                    // Hover -> mostra preview tooltip
                    btn.addEventListener('mouseenter', function(e){
                        tip.textContent = btn.getAttribute('data-preview');
                        tip.style.display = 'block';
                        var r = btn.getBoundingClientRect();
                        tip.style.left = (window.scrollX + r.left) + 'px';
                        tip.style.top = (window.scrollY + r.bottom + 6) + 'px';
                    });
                    btn.addEventListener('mouseleave', function(){ tip.style.display = 'none'; });

                    // Click -> insere texto (sem destruir o que ja foi digitado)
                    btn.addEventListener('click', function(){
                        var novo = btn.getAttribute('data-texto');
                        var atual = (txt.value || '').trim();
                        if (atual && atual !== novo) {
                            // Anexa ao final em vez de sobrescrever
                            if (!confirm('Voce ja digitou algo. Deseja SUBSTITUIR pela resposta rapida? \n\n(Cancele para anexar ao final em vez de substituir.)')) {
                                txt.value = atual + '\n\n' + novo;
                            } else {
                                txt.value = novo;
                            }
                        } else {
                            txt.value = novo;
                        }
                        // Feedback visual
                        btns.forEach(function(b){ b.classList.remove('vip-selecionada'); });
                        btn.classList.add('vip-selecionada', 'vip-pulse');
                        setTimeout(function(){ btn.classList.remove('vip-pulse'); }, 300);
                        // Foca no textarea com cursor no final
                        txt.focus();
                        txt.setSelectionRange(txt.value.length, txt.value.length);
                        if (btnLimpar) btnLimpar.style.display = 'inline-block';
                    });
                });

                // Mostra/esconde botao limpar conforme o textarea
                if (txt) {
                    txt.addEventListener('input', function(){
                        if (btnLimpar) btnLimpar.style.display = (txt.value.length > 0 ? 'inline-block' : 'none');
                    });
                }
                window.vipLimparResposta = function(){
                    if (!txt) return;
                    if (txt.value.trim() && !confirm('Limpar o campo de resposta?')) return;
                    txt.value = '';
                    btns.forEach(function(b){ b.classList.remove('vip-selecionada'); });
                    if (btnLimpar) btnLimpar.style.display = 'none';
                    txt.focus();
                };
            })();
            </script>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="responder">

            <div class="mb-1">
                <textarea id="txtResposta" name="mensagem" class="form-control" rows="4" placeholder="Digite sua resposta (ou clique em uma resposta rápida acima)..." required></textarea>
            </div>

            <div class="mb-1">
                <label class="form-label">Anexo (opcional)</label>
                <input type="file" name="anexo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx">
            </div>

            <button type="submit" class="btn btn-primary">✉️ Enviar Resposta</button>
        </form>
    </div>
</div>

<!-- Fechar sem responder (separado do form de resposta) -->
<div class="card mb-2" style="border-color:#fca5a5;">
    <div class="card-body" style="padding:.8rem 1rem;">
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <strong style="color:#991b1b;font-size:.82rem;">🔒 Fechar sem responder</strong>
            <span class="text-sm text-muted" style="flex:1;min-width:200px;">útil pra chamados duplicados ou resolvidos por outro canal</span>
            <button type="button" onclick="document.getElementById('formFecharSem').style.display='block';" class="btn btn-outline btn-sm" style="color:#991b1b;border-color:#fca5a5;">Fechar</button>
        </div>
        <form method="POST" id="formFecharSem" style="display:none;margin-top:.6rem;padding-top:.6rem;border-top:1px solid #fee2e2;" onsubmit="return confirm('Fechar esta conversa SEM responder ao cliente?');">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="fechar_thread">
            <label class="form-label text-sm">Motivo (opcional — registrado internamente)</label>
            <input type="text" name="motivo_fechamento" class="form-control" placeholder="Ex: Duplicado do chamado #X / Resolvido por WhatsApp / etc.">
            <div style="margin-top:.5rem;display:flex;gap:.4rem;">
                <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;">Confirmar fechamento</button>
                <button type="button" onclick="document.getElementById('formFecharSem').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card mb-2">
    <div class="card-body" style="text-align:center;padding:1.5rem;">
        <p class="text-muted">Esta conversa foi fechada.</p>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
