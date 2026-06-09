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

// Self-heal: tabela de tracking de processos resumidos por thread VIP (Amanda 08/06/2026)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS salavip_processos_enviados (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        thread_id INT UNSIGNED NOT NULL,
        case_id INT UNSIGNED NOT NULL,
        mensagem_id INT UNSIGNED NULL,
        enviado_por INT UNSIGNED NOT NULL,
        enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_thread (thread_id),
        INDEX idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

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

    // ── AJAX: processos do cliente do thread + resumo IA + tracking ───
    // Amanda 08/06/2026: mesmo modal do helpdesk adaptado pra Central VIP.
    if ($action === 'processos_do_thread') {
        header('Content-Type: application/json; charset=utf-8');
        $clientId = (int)$thread['cliente_id'];
        if (!$clientId) { echo json_encode(array('error' => 'Thread sem cliente vinculado.')); exit; }

        // Cases ativos do cliente
        $stCases = $pdo->prepare(
            "SELECT id, title, case_number, court, status, ia_resumo, ia_resumo_em
             FROM cases
             WHERE client_id = ?
               AND status NOT IN ('arquivado','cancelado','concluido')
             ORDER BY updated_at DESC"
        );
        $stCases->execute(array($clientId));
        $cases = $stCases->fetchAll();

        $stUlt = $pdo->prepare(
            "SELECT id, data_andamento, descricao
             FROM case_andamentos
             WHERE case_id = ? AND visivel_cliente = 1
             ORDER BY data_andamento DESC, created_at DESC
             LIMIT 1"
        );
        $stMaxAnd = $pdo->prepare("SELECT MAX(created_at) FROM case_andamentos WHERE case_id = ?");
        $stEnviado = $pdo->prepare(
            "SELECT MAX(enviado_em) FROM salavip_processos_enviados
             WHERE thread_id = ? AND case_id = ?"
        );

        $out = array();
        foreach ($cases as $c) {
            $stUlt->execute(array($c['id']));
            $ult = $stUlt->fetch();
            $ultimoAndamento = $ult ? array(
                'id'        => (int)$ult['id'],
                'data'      => $ult['data_andamento'] ? date('d/m/Y', strtotime($ult['data_andamento'])) : '',
                'descricao' => (string)$ult['descricao'],
            ) : null;

            $resumoDesatualizado = false;
            if (!empty($c['ia_resumo']) && !empty($c['ia_resumo_em'])) {
                $stMaxAnd->execute(array($c['id']));
                $maxAnd = (string)$stMaxAnd->fetchColumn();
                if ($maxAnd && strtotime($maxAnd) > strtotime($c['ia_resumo_em'])) {
                    $resumoDesatualizado = true;
                }
            }

            $stEnviado->execute(array($threadId, $c['id']));
            $envEm = (string)$stEnviado->fetchColumn();

            $out[] = array(
                'id'                  => (int)$c['id'],
                'titulo'              => (string)$c['title'],
                'case_number'         => (string)($c['case_number'] ?? ''),
                'court'               => (string)($c['court'] ?? ''),
                'status'              => (string)($c['status'] ?? ''),
                'ia_resumo'           => !empty($c['ia_resumo']) ? (string)$c['ia_resumo'] : null,
                'ia_resumo_em'        => !empty($c['ia_resumo_em']) ? date('d/m/Y H:i', strtotime($c['ia_resumo_em'])) : null,
                'ia_resumo_desatualizado' => $resumoDesatualizado,
                'ultimo_andamento'    => $ultimoAndamento,
                'ja_enviado_em'       => $envEm ? date('d/m/Y H:i', strtotime($envEm)) : null,
            );
        }
        echo json_encode(array(
            'ok'      => true,
            'client'  => array('id' => $clientId, 'nome' => $thread['client_name']),
            'cases'   => $out,
        ));
        exit;
    }

    if ($action === 'salavip_gerar_resumo_caso') {
        header('Content-Type: application/json; charset=utf-8');
        $caseId = (int)($_POST['case_id'] ?? 0);
        if (!$caseId) { echo json_encode(array('error' => 'case_id obrigatório')); exit; }

        require_once APP_ROOT . '/core/functions_ia.php';
        $uid = current_user_id();

        $stC = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
        $stC->execute(array($caseId));
        $caso = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$caso) { echo json_encode(array('error' => 'Caso não encontrado.')); exit; }

        $stAnd = $pdo->prepare(
            "SELECT data_andamento, hora_andamento, tipo, descricao
             FROM case_andamentos WHERE case_id = ? ORDER BY data_andamento DESC, id DESC LIMIT 30"
        );
        $stAnd->execute(array($caseId));
        $ands = $stAnd->fetchAll(PDO::FETCH_ASSOC);

        $stTar = $pdo->prepare(
            "SELECT title, due_date FROM case_tasks
             WHERE case_id = ? AND tipo IS NOT NULL AND status != 'concluido' ORDER BY due_date ASC LIMIT 20"
        );
        $stTar->execute(array($caseId));
        $tarefas = $stTar->fetchAll(PDO::FETCH_ASSOC);

        $stDoc = $pdo->prepare(
            "SELECT descricao FROM documentos_pendentes WHERE case_id = ? AND status = 'pendente' ORDER BY id"
        );
        $stDoc->execute(array($caseId));
        $docsP = $stDoc->fetchAll(PDO::FETCH_COLUMN);

        $ctx = "PROCESSO: " . (($caso['title'] ?? '') ?: 'sem título') . "\n";
        if (!empty($caso['case_number'])) $ctx .= "CNJ: " . $caso['case_number'] . "\n";
        $ctx .= "Tipo: " . (($caso['case_type'] ?? '') ?: '—') . " | Status: " . (($caso['status'] ?? '') ?: '—') . "\n\n";
        $ctx .= "ANDAMENTOS (mais recentes primeiro):\n";
        if (!$ands) $ctx .= "  (sem andamentos registrados)\n";
        foreach ($ands as $a) {
            $dt = $a['data_andamento'] ? date('d/m/Y', strtotime($a['data_andamento'])) : '';
            $tx = trim(preg_replace('/\s+/', ' ', (string)$a['descricao']));
            if (mb_strlen($tx) > 250) $tx = mb_substr($tx,0,250) . '…';
            $ctx .= "  • {$dt} [{$a['tipo']}] {$tx}\n";
        }
        $ctx .= "\nTAREFAS PENDENTES:\n";
        if (!$tarefas) $ctx .= "  (nenhuma)\n";
        foreach ($tarefas as $t) {
            $dl = $t['due_date'] ? ' (até ' . date('d/m', strtotime($t['due_date'])) . ')' : '';
            $ctx .= "  • {$t['title']}{$dl}\n";
        }
        $ctx .= "\nDOCUMENTOS FALTANTES:\n";
        if (!$docsP) $ctx .= "  (nenhum)\n";
        foreach ($docsP as $d) $ctx .= "  • {$d}\n";

        $system = "Você é uma assistente jurídica do escritório Ferreira & Sá Advocacia. "
                . "Vai receber o estado de um processo (andamentos recentes, tarefas, documentos) "
                . "e deve produzir um RESUMO EXECUTIVO em 4 parágrafos curtos, na seguinte ordem:\n"
                . "1. **Situação atual**: onde o processo está hoje (1 frase direta).\n"
                . "2. **Último movimento relevante**: o que mais importa nos últimos andamentos (1-2 frases).\n"
                . "3. **Próximo passo previsto**: o que esperar ou o que o escritório precisa fazer (1-2 frases).\n"
                . "4. **Alertas**: prazo crítico, documento pendente, ponto de atenção (1 frase OU 'Nenhum alerta no momento').\n\n"
                . "REGRAS:\n"
                . "- Linguagem objetiva, jurídica mas clara, em português brasileiro.\n"
                . "- NÃO invente fatos que não estão nos andamentos.\n"
                . "- Use markdown (**negrito** nos rótulos como acima).\n"
                . "- Total: no máximo 12 linhas. Cada parágrafo: máximo 2 frases.";

        $r = ia_chamar(
            'resumo_caso',
            'claude-haiku-4-5',
            $system,
            array(array('role' => 'user', 'content' => $ctx)),
            array(
                'user_id'      => $uid,
                'max_tokens'   => 600,
                'temperature'  => 0.2,
                'contexto'     => 'case#' . $caseId . ' (via salavip)',
                'cache_system' => true,
                'bypass_user_whitelist' => true,
                'bypass_killswitch'     => true,
            )
        );

        if (!$r['ok']) {
            echo json_encode(array('error' => $r['erro'] ?: 'Falha na IA'));
            exit;
        }

        $pdo->prepare("UPDATE cases SET ia_resumo = ?, ia_resumo_em = NOW() WHERE id = ?")
            ->execute(array($r['texto'], $caseId));

        audit_log('IA_RESUMO_CASO', 'case', $caseId, 'via salavip tokens=' . $r['input_tokens'] . '/' . $r['output_tokens'] . ' R$' . $r['custo_brl']);

        echo json_encode(array(
            'ok'        => true,
            'texto'     => $r['texto'],
            'em'        => date('d/m/Y H:i'),
            'custo_brl' => $r['custo_brl'],
        ));
        exit;
    }

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
        $mensagemId = (int)$pdo->lastInsertId();

        // Amanda 08/06/2026: tracking dos processos incluidos via "Inserir status dos processos"
        $casesInc = $_POST['cases_incluidos'] ?? array();
        if (!is_array($casesInc)) $casesInc = array($casesInc);
        $casesInc = array_values(array_unique(array_filter(array_map('intval', $casesInc), function($v){ return $v > 0; })));
        if (!empty($casesInc)) {
            $stIns = $pdo->prepare("INSERT INTO salavip_processos_enviados (thread_id, case_id, mensagem_id, enviado_por) VALUES (?,?,?,?)");
            foreach ($casesInc as $cid) {
                try { $stIns->execute(array($threadId, $cid, $mensagemId, current_user_id())); } catch (Exception $e) {}
            }
        }

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

        <form id="formRespostaVip" method="POST" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="responder">

            <div style="margin-bottom:.5rem;">
                <button type="button" onclick="vpAbrirInserirProcessos(<?= (int)$threadId ?>)" class="btn btn-outline btn-sm" style="border-color:#0ea5e9;color:#0369a1;" title="Inserir resumo executivo dos processos do cliente (com resumo IA cacheado + tracking de já enviados)">📊 Inserir status dos processos</button>
            </div>

            <div class="mb-1">
                <textarea id="txtResposta" name="mensagem" class="form-control" rows="10" placeholder="Digite sua resposta (ou clique em uma resposta rápida acima)..." required style="width:100%;min-height:200px;resize:vertical;font-size:.92rem;line-height:1.5;padding:.85rem 1rem;"></textarea>
            </div>

            <div class="mb-1">
                <label class="form-label">Anexo (opcional)</label>
                <input type="file" name="anexo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx">
            </div>

            <button type="submit" class="btn btn-primary">✉️ Enviar Resposta</button>
        </form>

<!-- Modal: Inserir status dos processos (Amanda 08/06/2026 - adaptado pra Central VIP) -->
<div id="vpModalProcessos" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:14px;padding:1.4rem;max-width:820px;width:100%;max-height:92vh;overflow-y:auto;box-shadow:0 14px 40px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;">
            <h3 style="margin:0;color:#0f2140;">📊 Inserir resumo dos processos</h3>
            <button type="button" onclick="document.getElementById('vpModalProcessos').style.display='none'" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;">✕</button>
        </div>
        <div id="vpAviso" style="display:none;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:.5rem .7rem;font-size:.78rem;color:#92400e;margin-bottom:.8rem;"></div>
        <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:.6rem .8rem;font-size:.78rem;color:#1e40af;margin-bottom:.8rem;">
            💡 Mostramos o <strong>resumo executivo gerado pela IA</strong> de cada processo. Se ainda não foi gerado, você pode gerar pelo botão "✨ Gerar agora". Processos já enviados nesta conversa aparecem com ✅ e ficam desmarcados por padrão.
        </div>
        <label style="display:flex;align-items:center;gap:.45rem;cursor:pointer;font-size:.8rem;font-weight:600;margin-bottom:.7rem;padding:.45rem .6rem;background:#fafafa;border-radius:6px;border:1px solid #e5e7eb;">
            <input type="checkbox" id="vpSoPendentes" onchange="vpRender()" style="width:16px;height:16px;cursor:pointer;">
            <span>🎯 Esconder processos já enviados nesta conversa</span>
        </label>
        <div id="vpCorpo" style="min-height:120px;">
            <div style="text-align:center;padding:2rem;color:#6b7280;">Carregando processos...</div>
        </div>
        <div style="display:flex;justify-content:space-between;gap:.5rem;margin-top:1rem;padding-top:.8rem;border-top:1px solid #e5e7eb;">
            <button type="button" onclick="document.getElementById('vpModalProcessos').style.display='none'" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="button" id="vpBtnInserir" onclick="vpInserirNaResposta()" class="btn btn-primary btn-sm" style="background:#0ea5e9;">📥 Inserir resumo na resposta</button>
        </div>
    </div>
</div>

<script>
window.vpDados = null;
window.vpThreadId = null;
window.vpUrl = window.location.pathname + window.location.search;

function vpAbrirInserirProcessos(threadId) {
    var modal = document.getElementById('vpModalProcessos');
    var corpo = document.getElementById('vpCorpo');
    var aviso = document.getElementById('vpAviso');
    aviso.style.display = 'none';
    corpo.innerHTML = '<div style="text-align:center;padding:2rem;color:#6b7280;">Carregando processos...</div>';
    modal.style.display = 'flex';
    window.vpThreadId = threadId;

    var fd = new FormData();
    fd.append('action', 'processos_do_thread');
    fd.append('csrf_token', '<?= generate_csrf_token() ?>');
    fetch(window.vpUrl, {
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
        window.vpDados = d;
        vpRender();
    }).catch(function(e){
        aviso.style.display = 'block';
        aviso.textContent = '⚠️ Falha de rede: ' + e.message;
        corpo.innerHTML = '';
    });
}

function vpRender() {
    var d = window.vpDados;
    if (!d) return;
    var soPendentes = document.getElementById('vpSoPendentes').checked;
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
        html += '<input type="checkbox" class="vp-case" data-case-idx="'+idx+'" data-case-id="'+c.id+'" '+(defaultChecked?'checked':'')+' style="width:18px;height:18px;cursor:pointer;margin-top:2px;">';
        html += '<div style="flex:1;">';
        if (jaEnviado) {
            html += '<div style="background:#dcfce7;border:1px solid #86efac;color:#166534;display:inline-block;padding:2px 10px;border-radius:10px;font-size:.66rem;font-weight:700;margin-bottom:.35rem;">✅ Já enviado nesta conversa em '+vpEsc(c.ja_enviado_em)+'</div>';
        }
        html += '<div style="font-weight:700;color:#0f2140;font-size:.94rem;">' + vpEsc(c.titulo) + '</div>';
        html += '<div style="font-size:.72rem;color:#6b7280;margin-top:.15rem;">';
        if (c.case_number) html += '⚖️ ' + vpEsc(c.case_number);
        if (c.court) html += (c.case_number ? ' · ' : '') + '🏛️ ' + vpEsc(c.court);
        if (c.status) html += ' · 📊 ' + vpEsc(c.status.replace(/_/g,' '));
        html += '</div>';

        if (c.ia_resumo) {
            var corResumo = c.ia_resumo_desatualizado ? '#92400e' : '#0369a1';
            var bgResumo = c.ia_resumo_desatualizado ? '#fef3c7' : '#eff6ff';
            var borderResumo = c.ia_resumo_desatualizado ? '#fbbf24' : '#bfdbfe';
            html += '<div style="background:'+bgResumo+';border:1px solid '+borderResumo+';border-radius:8px;padding:.55rem .75rem;margin-top:.55rem;font-size:.8rem;color:#1f2937;white-space:pre-wrap;line-height:1.45;">';
            html += '<div style="font-size:.66rem;color:'+corResumo+';font-weight:700;margin-bottom:.3rem;">✨ RESUMO EXECUTIVO IA <span style="font-weight:400;opacity:.8;">· gerado em '+vpEsc(c.ia_resumo_em || '—')+'</span>';
            if (c.ia_resumo_desatualizado) html += ' <span style="background:#f59e0b;color:#fff;padding:1px 6px;border-radius:6px;margin-left:6px;font-size:.62rem;">DESATUALIZADO</span>';
            html += '</div>';
            html += vpEsc(c.ia_resumo);
            html += '</div>';
            if (c.ia_resumo_desatualizado) {
                html += '<button type="button" onclick="event.preventDefault();event.stopPropagation();vpGerarResumo('+c.id+','+idx+',this)" style="margin-top:.4rem;background:#0ea5e9;color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:.74rem;font-weight:600;cursor:pointer;">🔄 Atualizar resumo (há andamento novo)</button>';
            }
        } else if (c.ultimo_andamento) {
            html += '<div style="background:#fafafa;border:1px dashed #d1d5db;border-radius:8px;padding:.55rem .75rem;margin-top:.55rem;font-size:.78rem;color:#374151;">';
            html += '<div style="font-size:.66rem;color:#6b7280;font-weight:700;margin-bottom:.25rem;">📅 ÚLTIMO ANDAMENTO (sem resumo IA gerado)</div>';
            html += '<div style="font-weight:600;color:#0369a1;">' + vpEsc(c.ultimo_andamento.data) + '</div>';
            html += '<div style="margin-top:.15rem;">' + vpEsc(c.ultimo_andamento.descricao) + '</div>';
            html += '</div>';
            html += '<button type="button" onclick="event.preventDefault();event.stopPropagation();vpGerarResumo('+c.id+','+idx+',this)" style="margin-top:.4rem;background:#0ea5e9;color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:.74rem;font-weight:600;cursor:pointer;">✨ Gerar resumo IA agora (~20s)</button>';
        } else {
            html += '<div style="background:#fafafa;border:1px dashed #d1d5db;border-radius:8px;padding:.55rem .75rem;margin-top:.55rem;font-size:.78rem;color:#9ca3af;font-style:italic;">Sem resumo IA e sem andamentos visíveis ao cliente.</div>';
            html += '<button type="button" onclick="event.preventDefault();event.stopPropagation();vpGerarResumo('+c.id+','+idx+',this)" style="margin-top:.4rem;background:#0ea5e9;color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:.74rem;font-weight:600;cursor:pointer;">✨ Gerar resumo IA mesmo assim</button>';
        }
        html += '</div></label></div>';
    });

    if (nVisiveis === 0) {
        html = '<div style="text-align:center;padding:2rem;color:#6b7280;">Nenhum processo pendente (todos já foram enviados nesta conversa). Desmarque o filtro acima pra ver os já enviados.</div>';
    }
    document.getElementById('vpCorpo').innerHTML = html;
}

function vpGerarResumo(caseId, idx, btn) {
    var textoOrig = btn.innerHTML;
    btn.innerHTML = '⏳ Gerando... (até 30s)';
    btn.disabled = true;
    btn.style.opacity = '.6';
    btn.style.cursor = 'wait';

    var fd = new FormData();
    fd.append('action', 'salavip_gerar_resumo_caso');
    fd.append('case_id', caseId);
    fd.append('csrf_token', '<?= generate_csrf_token() ?>');
    fetch(window.vpUrl, {
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
        window.vpDados.cases[idx].ia_resumo = d.texto;
        window.vpDados.cases[idx].ia_resumo_em = d.em;
        window.vpDados.cases[idx].ia_resumo_desatualizado = false;
        vpRender();
    }).catch(function(e){
        alert('⚠️ Falha de rede: ' + e.message);
        btn.innerHTML = textoOrig;
        btn.disabled = false;
        btn.style.opacity = '';
        btn.style.cursor = '';
    });
}

function vpInserirNaResposta() {
    var d = window.vpDados;
    if (!d) return;
    var selecionados = document.querySelectorAll('.vp-case:checked');
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

    var ta = document.getElementById('txtResposta');
    if (ta) {
        ta.value = (ta.value ? ta.value + '\n\n' : '') + texto;
        ta.focus();
        ta.scrollTop = ta.scrollHeight;
    }

    // Limpa hiddens antigos e adiciona os novos
    var form = document.getElementById('formRespostaVip');
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

    document.getElementById('vpModalProcessos').style.display = 'none';
}

function vpEsc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
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
