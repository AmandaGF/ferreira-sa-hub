<?php
/**
 * Central VIP F&S — Chamados (Tickets)
 * Cliente pode abrir chamados que vão para o Helpdesk do Conecta
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// SLA por categoria (em horas úteis)
$slaConfig = array(
    'urgencia'      => array('horas' => 16, 'label' => '16 horas úteis'),
    'financeiro'    => array('horas' => 24, 'label' => '3 dias úteis'),
    'duvida'        => array('horas' => 24, 'label' => '3 dias úteis'),
    'atualizacao'   => array('horas' => 8,  'label' => '1 dia útil'),
    'documento'     => array('horas' => 24, 'label' => '3 dias úteis'),
    'outro'         => array('horas' => 40, 'label' => '5 dias úteis'),
);

// --- POST: Abrir novo chamado ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'abrir_chamado') {
    if (!salavip_validar_csrf($_POST['csrf_token'] ?? '')) {
        sv_flash('error', 'Token inválido.');
        sv_redirect('pages/chamados.php');
    }

    $assunto = trim($_POST['assunto'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'duvida');
    $processoId = (int)($_POST['processo_id'] ?? 0);

    if (!$assunto || !$descricao) {
        sv_flash('error', 'Assunto e descrição são obrigatórios.');
        sv_redirect('pages/chamados.php');
    }

    // Calcular SLA prazo
    $slaCat = isset($slaConfig[$categoria]) ? $slaConfig[$categoria] : $slaConfig['outro'];
    $slaHoras = $slaCat['horas'];
    // Calcular prazo SLA considerando horas úteis (8h/dia, seg-sex, 9h-18h)
    $slaPrazo = new DateTime();
    $horasRestantes = $slaHoras;
    while ($horasRestantes > 0) {
        $diaSemana = (int)$slaPrazo->format('N'); // 1=seg, 7=dom
        $hora = (int)$slaPrazo->format('G');
        if ($diaSemana <= 5 && $hora >= 9 && $hora < 18) {
            $horasRestantes--;
        }
        $slaPrazo->modify('+1 hour');
    }
    $slaPrazoStr = $slaPrazo->format('Y-m-d H:i:s');

    // Mapear prioridade pela categoria
    $prioridade = 'normal';
    if ($categoria === 'urgencia') $prioridade = 'urgente';

    try {
        // Criar ticket no Helpdesk
        $pdo->prepare(
            "INSERT INTO tickets (title, category, priority, status, requester_id, client_id, case_id, origem, sla_prazo, created_at, updated_at)
             VALUES (?, ?, ?, 'aberto', NULL, ?, ?, 'salavip', ?, NOW(), NOW())"
        )->execute(array($assunto, $categoria, $prioridade, $clienteId, $processoId ?: null, $slaPrazoStr));
        $ticketId = (int)$pdo->lastInsertId();

        // Criar primeira mensagem do ticket
        $pdo->prepare(
            "INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, user_id, message, created_at)
             VALUES (?, 'cliente', ?, NULL, ?, NOW())"
        )->execute(array($ticketId, $clienteId, $descricao));

        // Push pra equipe (admin+gestao+cx) — cliente acabou de abrir chamado via Central VIP
        // Salavip não auto-inclui functions_push, carrega aqui se existir
        $_pushPath = __DIR__ . '/../../core/functions_push.php';
        if (file_exists($_pushPath)) { try { require_once $_pushPath; } catch (Exception $e) {} }
        if (function_exists('push_notify_role')) {
            try {
                // Nome do cliente pra mensagem
                $stNome = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
                $stNome->execute(array($clienteId));
                $nomeCli = (string)$stNome->fetchColumn();
                $tituloPush = ($prioridade === 'urgente' ? '🔥 ' : '🔔 ') . 'Novo chamado Central VIP #' . $ticketId;
                $corpoPush = ($nomeCli ? $nomeCli . ' · ' : '') . $assunto;
                $urlPush = '/conecta/modules/helpdesk/ver.php?id=' . $ticketId;
                push_notify_role(array('admin','gestao','cx'), $tituloPush, $corpoPush, $urlPush, $prioridade === 'urgente');
            } catch (Exception $e) {}
        }

        sv_flash('success', 'Chamado #' . $ticketId . ' aberto com sucesso! Nossa equipe responderá em breve.');
    } catch (Exception $e) {
        sv_flash('error', 'Erro ao abrir chamado: ' . $e->getMessage());
    }
    sv_redirect('pages/chamados.php');
}

// --- Buscar processos do cliente ---
$processos = array();
try {
    $stmtProc = $pdo->prepare(
        "SELECT id, title, case_number FROM cases WHERE client_id = ? AND salavip_ativo = 1 AND status NOT IN ('cancelado','arquivado') ORDER BY title"
    );
    $stmtProc->execute(array($clienteId));
    $processos = $stmtProc->fetchAll();
} catch (Exception $e) {}

// --- Buscar chamados do cliente ---
$chamados = array();
try {
    $stmtCh = $pdo->prepare(
        "SELECT t.*,
                c.title as processo_titulo,
                (SELECT COUNT(*) FROM ticket_messages tm WHERE tm.ticket_id = t.id) as total_msgs,
                (SELECT tm2.message FROM ticket_messages tm2 WHERE tm2.ticket_id = t.id ORDER BY tm2.created_at DESC LIMIT 1) as ultima_msg,
                (SELECT tm3.sender_type FROM ticket_messages tm3 WHERE tm3.ticket_id = t.id ORDER BY tm3.created_at DESC LIMIT 1) as ultimo_remetente
         FROM tickets t
         LEFT JOIN cases c ON c.id = t.case_id
         WHERE t.client_id = ?
         ORDER BY t.updated_at DESC"
    );
    $stmtCh->execute(array($clienteId));
    $chamados = $stmtCh->fetchAll();
} catch (Exception $e) {}

$statusMap = array(
    'aberto' => array('#6366f1', 'Aberto'),
    'em_andamento' => array('#d97706', 'Em andamento'),
    'aguardando' => array('#0ea5e9', 'Aguardando'),
    'resolvido' => array('#059669', 'Resolvido'),
    'cancelado' => array('#dc2626', 'Cancelado'),
);

$categoriaLabels = array(
    'urgencia' => 'Urgência',
    'financeiro' => 'Financeiro / Pagamento',
    'duvida' => 'Dúvida',
    'atualizacao' => 'Atualização de dados',
    'documento' => 'Solicitação de documento',
    'outro' => 'Outro',
);

$pageTitle = 'Chamados';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- SLA Info Card -->
<div class="sv-card" style="margin-bottom:1rem;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border:1px solid var(--sv-border);">
    <h4 style="margin:0 0 .6rem;font-size:.85rem;color:var(--sv-accent);">⏱️ Prazos de Atendimento (SLA)</h4>
    <p style="font-size:.75rem;color:var(--sv-text-muted);margin:0 0 .5rem;">
        Nosso compromisso com você: cada tipo de chamado tem um prazo máximo de resposta.
    </p>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;">
        <?php
        $slaDisplay = array(
            array('🚨', 'Urgência', '16 horas úteis', '#dc2626'),
            array('💰', 'Financeiro', '3 dias úteis', '#d97706'),
            array('❓', 'Dúvidas', '3 dias úteis', '#6366f1'),
            array('📄', 'Documentos', '3 dias úteis', '#0ea5e9'),
            array('📋', 'Atualização Cadastral', '1 dia útil', '#059669'),
            array('📌', 'Outros', '5 dias úteis', '#6b7280'),
        );
        foreach ($slaDisplay as $sla): ?>
        <div style="background:#fff;border-radius:8px;padding:.5rem .6rem;border-left:3px solid <?= $sla[3] ?>;display:flex;align-items:center;gap:.4rem;">
            <span style="font-size:1rem;"><?= $sla[0] ?></span>
            <div>
                <div style="font-size:.72rem;font-weight:700;color:var(--sv-text);"><?= $sla[1] ?></div>
                <div style="font-size:.65rem;color:<?= $sla[3] ?>;font-weight:600;"><?= $sla[2] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Abrir novo chamado -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
        <h3 style="margin:0;">Abrir Novo Chamado</h3>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= salavip_gerar_csrf() ?>">
        <input type="hidden" name="action" value="abrir_chamado">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem;">
            <div>
                <label style="font-size:.8rem;font-weight:600;color:var(--sv-text-muted);display:block;margin-bottom:.3rem;">Assunto *</label>
                <input type="text" name="assunto" class="sv-input" required placeholder="Descreva resumidamente sua solicitação" style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--sv-border);border-radius:8px;font-size:.88rem;background:var(--sv-bg);color:var(--sv-text);">
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;color:var(--sv-text-muted);display:block;margin-bottom:.3rem;">Categoria</label>
                <select name="categoria" id="selCategoria" onchange="mostrarSLA()" style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--sv-border);border-radius:8px;font-size:.88rem;background:var(--sv-bg);color:var(--sv-text);">
                    <option value="duvida">❓ Dúvida</option>
                    <option value="documento">📄 Solicitação de documento</option>
                    <option value="atualizacao">📋 Atualização de dados</option>
                    <option value="financeiro">💰 Financeiro / Pagamento</option>
                    <option value="urgencia">🚨 Urgência</option>
                    <option value="outro">📌 Outro</option>
                </select>
                <div id="slaInfo" style="font-size:.68rem;margin-top:.25rem;font-weight:600;color:#6366f1;">
                    ⏱️ Prazo de resposta: 3 dias úteis
                </div>
            </div>
        </div>

        <?php if (!empty($processos)): ?>
        <div style="margin-bottom:.75rem;">
            <label style="font-size:.8rem;font-weight:600;color:var(--sv-text-muted);display:block;margin-bottom:.3rem;">Processo relacionado (opcional)</label>
            <select name="processo_id" style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--sv-border);border-radius:8px;font-size:.88rem;background:var(--sv-bg);color:var(--sv-text);">
                <option value="">— Nenhum (assunto geral) —</option>
                <?php foreach ($processos as $proc): ?>
                <option value="<?= $proc['id'] ?>"><?= sv_e($proc['title']) ?><?= $proc['case_number'] ? ' (' . sv_e($proc['case_number']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div style="margin-bottom:.75rem;">
            <label style="font-size:.8rem;font-weight:600;color:var(--sv-text-muted);display:block;margin-bottom:.3rem;">Descrição *</label>
            <textarea name="descricao" rows="4" required placeholder="Descreva com detalhes sua solicitação ou dúvida..." style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--sv-border);border-radius:8px;font-size:.88rem;background:var(--sv-bg);color:var(--sv-text);resize:vertical;font-family:inherit;"></textarea>
        </div>

        <button type="submit" class="sv-btn sv-btn-gold">Enviar Chamado</button>
    </form>
</div>

<!-- Meus Chamados -->
<div class="sv-card">
    <h3>Meus Chamados</h3>
    <?php if (empty($chamados)): ?>
        <p class="sv-empty">Você ainda não abriu nenhum chamado.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.6rem;">
            <?php foreach ($chamados as $ch):
                $st = isset($statusMap[$ch['status']]) ? $statusMap[$ch['status']] : array('#888', ucfirst($ch['status'] ?? ''));
                $isRespondido = ($ch['ultimo_remetente'] ?? '') !== 'cliente';
                $catLabel = isset($categoriaLabels[$ch['category']]) ? $categoriaLabels[$ch['category']] : ucfirst($ch['category'] ?? '');
                $slaCat = isset($slaConfig[$ch['category']]) ? $slaConfig[$ch['category']] : $slaConfig['outro'];

                // Calcular status do SLA
                $slaStatus = '';
                $slaCor = '';
                if ($ch['sla_prazo'] && $ch['status'] !== 'resolvido' && $ch['status'] !== 'cancelado') {
                    $agora = new DateTime();
                    $prazo = new DateTime($ch['sla_prazo']);
                    if ($agora > $prazo) {
                        $slaStatus = 'SLA excedido';
                        $slaCor = '#dc2626';
                    } else {
                        $diff = $agora->diff($prazo);
                        $horasRestantes = $diff->h + ($diff->days * 24);
                        if ($horasRestantes <= 4) {
                            $slaStatus = 'SLA próximo do limite';
                            $slaCor = '#d97706';
                        } else {
                            $slaStatus = 'Dentro do prazo';
                            $slaCor = '#059669';
                        }
                    }
                }
            ?>
            <a href="<?= SALAVIP_BASE_URL ?>/pages/chamado_ver.php?id=<?= $ch['id'] ?>" style="text-decoration:none;color:inherit;display:block;border:1px solid var(--sv-border);border-radius:10px;padding:.8rem 1rem;transition:all .2s;<?= $isRespondido && $ch['status'] !== 'resolvido' ? 'border-left:3px solid var(--sv-accent);' : '' ?>" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.08)';this.style.transform='translateY(-1px)'" onmouseout="this.style.boxShadow='';this.style.transform=''">
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem;">
                    <strong style="color:var(--sv-accent);font-size:.85rem;">#<?= $ch['id'] ?></strong>
                    <span style="font-weight:600;color:var(--sv-text);font-size:.9rem;"><?= sv_e($ch['title']) ?></span>
                    <span style="background:<?= $st[0] ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.68rem;font-weight:600;"><?= $st[1] ?></span>
                    <span style="background:rgba(0,0,0,.06);color:var(--sv-text-muted);padding:2px 8px;border-radius:9999px;font-size:.62rem;font-weight:600;"><?= $catLabel ?></span>
                    <?php if ($isRespondido && $ch['status'] !== 'resolvido'): ?>
                    <span style="background:#ecfdf5;color:#059669;padding:2px 8px;border-radius:9999px;font-size:.65rem;font-weight:700;">Nova resposta</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.82rem;color:var(--sv-text-muted);">
                    <?= sv_e(mb_strimwidth($ch['ultima_msg'] ?? '', 0, 120, '...')) ?>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.4rem;flex-wrap:wrap;gap:.3rem;">
                    <span style="font-size:.72rem;color:var(--sv-text-muted);">
                        <?= date('d/m/Y H:i', strtotime($ch['created_at'])) ?>
                        <?php if ($ch['processo_titulo']): ?> · <?= sv_e($ch['processo_titulo']) ?><?php endif; ?>
                        · <?= $ch['total_msgs'] ?> msg<?= $ch['total_msgs'] != 1 ? 's' : '' ?>
                    </span>
                    <?php if ($slaStatus): ?>
                    <span style="font-size:.65rem;font-weight:700;color:<?= $slaCor ?>;display:flex;align-items:center;gap:.2rem;">
                        ⏱️ <?= $slaStatus ?> · Prazo: <?= $slaCat['label'] ?>
                    </span>
                    <?php elseif ($ch['status'] === 'resolvido'): ?>
                    <span style="font-size:.65rem;font-weight:700;color:#059669;">✅ Resolvido</span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function mostrarSLA() {
    var cat = document.getElementById('selCategoria').value;
    var slas = {
        'duvida':      '⏱️ Prazo de resposta: 3 dias úteis',
        'documento':   '⏱️ Prazo de resposta: 3 dias úteis',
        'atualizacao': '⏱️ Prazo de resposta: 1 dia útil',
        'financeiro':  '⏱️ Prazo de resposta: 3 dias úteis',
        'urgencia':    '🚨 Prazo de resposta: 16 horas úteis',
        'outro':       '⏱️ Prazo de resposta: 5 dias úteis'
    };
    var cores = {
        'duvida': '#6366f1', 'documento': '#0ea5e9', 'atualizacao': '#059669',
        'financeiro': '#d97706', 'urgencia': '#dc2626', 'outro': '#6b7280'
    };
    var info = document.getElementById('slaInfo');
    info.textContent = slas[cat] || slas['outro'];
    info.style.color = cores[cat] || '#6b7280';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
