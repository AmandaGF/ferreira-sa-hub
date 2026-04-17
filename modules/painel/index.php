<?php
/**
 * Ferreira & Sá Hub — Painel do Dia
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$userId = current_user_id();
$userRole = current_user_role();
$isGestao = in_array($userRole, array('admin', 'gestao'));
$userName = explode(' ', current_user()['name'] ?? '')[0];

// Ver agenda de outro usuário (Admin/Gestão)
$viewUserId = ($isGestao && isset($_GET['user'])) ? (int)$_GET['user'] : $userId;

$hora = (int)date('G');
if ($hora < 12) { $saudacao = 'Bom dia'; $emoji = '☀️'; }
elseif ($hora < 18) { $saudacao = 'Boa tarde'; $emoji = '🌤️'; }
else { $saudacao = 'Boa noite'; $emoji = '🌙'; }

$diasSemana = array('Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado');
$meses = array('','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
$hoje = date('Y-m-d');
$diaSemana = $diasSemana[(int)date('w')];
$dataExtenso = (int)date('j') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y');

// ─── COLUNA 1: Agenda de Hoje ───
$agendaHoje = array();

// Eventos da agenda
try {
    $sql = "SELECT e.id, e.titulo, e.tipo, e.data_inicio, e.data_fim, e.local, e.meet_link, e.status,
                   c.name as client_name, cs.title as case_title, cs.case_number, u.name as resp_name
            FROM agenda_eventos e
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN cases cs ON cs.id = e.case_id
            LEFT JOIN users u ON u.id = e.responsavel_id
            WHERE DATE(e.data_inicio) = ? AND e.status NOT IN ('cancelado','remarcado')";
    if (!$isGestao || $viewUserId !== $userId) {
        $sql .= " AND e.responsavel_id = ?";
        $stmt = $pdo->prepare($sql . " ORDER BY e.data_inicio ASC");
        $stmt->execute(array($hoje, $viewUserId));
    } else {
        $stmt = $pdo->prepare($sql . " ORDER BY e.data_inicio ASC");
        $stmt->execute(array($hoje));
    }
    foreach ($stmt->fetchAll() as $ev) {
        $agendaHoje[] = array(
            'hora' => date('H:i', strtotime($ev['data_inicio'])),
            'titulo' => $ev['titulo'],
            'tipo' => $ev['tipo'] ?: 'compromisso',
            'badge' => '🔵',
            'cor' => '#2563eb',
            'detalhe' => ($ev['client_name'] ? $ev['client_name'] . ' · ' : '') . ($ev['case_title'] ?: ''),
            'link' => $ev['meet_link'] ?: null,
            'processo' => $ev['case_number'] ?: '',
            'concluido' => ($ev['status'] === 'realizado'),
            'id' => 'ev_' . $ev['id'],
        );
    }
} catch (Exception $e) {}

// Prazos fatais
try {
    $stmtP = $pdo->prepare(
        "SELECT p.id, p.descricao_acao, p.prazo_fatal, p.numero_processo, p.concluido, cs.title as case_title
         FROM prazos_processuais p LEFT JOIN cases cs ON cs.id = p.case_id
         WHERE p.prazo_fatal = ? AND p.concluido = 0 ORDER BY p.prazo_fatal"
    );
    $stmtP->execute(array($hoje));
    foreach ($stmtP->fetchAll() as $p) {
        $agendaHoje[] = array(
            'hora' => '⏰',
            'titulo' => $p['descricao_acao'],
            'tipo' => 'prazo',
            'badge' => '🔴',
            'cor' => '#dc2626',
            'detalhe' => $p['case_title'] ?: '',
            'link' => null,
            'processo' => $p['numero_processo'] ?: '',
            'concluido' => false,
            'id' => 'pz_' . $p['id'],
        );
    }
} catch (Exception $e) {}

// Tarefas com prazo hoje
try {
    $sqlT = "SELECT ct.id, ct.title, ct.status, ct.due_date, cs.title as case_title, cs.case_number
             FROM case_tasks ct LEFT JOIN cases cs ON cs.id = ct.case_id
             WHERE ct.due_date = ? AND ct.status != 'concluido'";
    if (!$isGestao) $sqlT .= " AND ct.assigned_to = $viewUserId";
    $stmtT = $pdo->prepare($sqlT . " ORDER BY ct.title");
    $stmtT->execute(array($hoje));
    foreach ($stmtT->fetchAll() as $t) {
        $agendaHoje[] = array(
            'hora' => '📋',
            'titulo' => $t['title'],
            'tipo' => 'tarefa',
            'badge' => '✅',
            'cor' => '#059669',
            'detalhe' => $t['case_title'] ?: '',
            'link' => null,
            'processo' => $t['case_number'] ?: '',
            'concluido' => false,
            'id' => 'tk_' . $t['id'],
        );
    }
} catch (Exception $e) {}

// Lembretes pessoais
try {
    $stmtL = $pdo->prepare("SELECT * FROM eventos_dia WHERE usuario_id = ? AND data_evento = ? ORDER BY hora_inicio ASC, criado_em ASC");
    $stmtL->execute(array($viewUserId, $hoje));
    $lembretesHoje = $stmtL->fetchAll();
    foreach ($lembretesHoje as $l) {
        if ($l['tipo'] !== 'lembrete') continue;
        $agendaHoje[] = array(
            'hora' => $l['hora_inicio'] ? date('H:i', strtotime($l['hora_inicio'])) : '💬',
            'titulo' => $l['titulo'],
            'tipo' => 'lembrete',
            'badge' => '💬',
            'cor' => '#6366f1',
            'detalhe' => '',
            'link' => $l['link_externo'] ?: null,
            'processo' => $l['processo_ref'] ?: '',
            'concluido' => (bool)$l['concluido'],
            'id' => 'ld_' . $l['id'],
        );
    }
} catch (Exception $e) {}

// Ordenar por hora
usort($agendaHoje, function($a, $b) {
    $ha = preg_replace('/[^0-9:]/', '99:99', $a['hora']);
    $hb = preg_replace('/[^0-9:]/', '99:99', $b['hora']);
    return strcmp($ha, $hb);
});

// ─── COLUNA 2: Resumo ───
$resumo = array();
try {
    $resumo['tarefas'] = (int)$pdo->prepare("SELECT COUNT(*) FROM case_tasks WHERE due_date = ? AND status != 'concluido'" . (!$isGestao ? " AND assigned_to = $userId" : ''))->execute(array($hoje)) ? (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
    // Recalcular corretamente
    $stR = $pdo->prepare("SELECT COUNT(*) FROM case_tasks WHERE due_date = ? AND status != 'concluido'" . (!$isGestao ? " AND assigned_to = $userId" : ''));
    $stR->execute(array($hoje));
    $resumo['tarefas'] = (int)$stR->fetchColumn();

    $stR2 = $pdo->prepare("SELECT COUNT(*) FROM agenda_eventos WHERE DATE(data_inicio) = ? AND tipo = 'audiencia' AND status NOT IN ('cancelado','remarcado')" . (!$isGestao ? " AND responsavel_id = $userId" : ''));
    $stR2->execute(array($hoje));
    $resumo['audiencias'] = (int)$stR2->fetchColumn();

    $resumo['prazos'] = (int)$pdo->query("SELECT COUNT(*) FROM prazos_processuais WHERE prazo_fatal = '$hoje' AND concluido = 0")->fetchColumn();
    $resumo['docs_faltantes'] = (int)$pdo->query("SELECT COUNT(*) FROM documentos_pendentes WHERE status = 'pendente'")->fetchColumn();

    if ($isGestao) {
        $resumo['cobrancas'] = (int)$pdo->query("SELECT COUNT(*) FROM honorarios_cobranca WHERE status NOT IN ('pago','cancelado')")->fetchColumn();
    }

    $resumo['chamados'] = (int)$pdo->prepare("SELECT COUNT(*) FROM tickets t LEFT JOIN ticket_assignees ta ON ta.ticket_id = t.id WHERE t.status IN ('aberto','em_andamento') AND (t.requester_id = ? OR ta.user_id = ?)")->execute(array($userId, $userId)) ? 0 : 0;
    $stCh = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE status IN ('aberto','em_andamento')");
    $stCh->execute();
    $resumo['chamados'] = (int)$stCh->fetchColumn();
} catch (Exception $e) {}

// Usuários (para Admin ver agenda de outro)
$users = array();
if ($isGestao) {
    $users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
}

$pageTitle = 'Painel do Dia';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pd-saudacao{background:linear-gradient(135deg,#052228,#0d3640);color:#fff;border-radius:var(--radius-lg);padding:1.25rem 1.5rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;}
.pd-saudacao h2{margin:0;font-size:1.15rem;font-weight:700;}
.pd-saudacao .sub{font-size:.78rem;color:rgba(255,255,255,.6);margin-top:.15rem;}
.pd-grid{display:grid;grid-template-columns:1.2fr .8fr .8fr;gap:1rem;margin-bottom:1.25rem;}
@media(max-width:1100px){.pd-grid{grid-template-columns:1fr;}}
.pd-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;}
.pd-card h3{font-size:.88rem;font-weight:700;color:var(--petrol-900);margin:0 0 .75rem;display:flex;align-items:center;gap:.4rem;}
.pd-timeline{position:relative;padding-left:16px;}
.pd-timeline::before{content:'';position:absolute;left:4px;top:0;bottom:0;width:2px;background:var(--border);}
.pd-ev{position:relative;margin-bottom:.6rem;padding-left:14px;}
.pd-ev::before{content:'';position:absolute;left:-15px;top:6px;width:10px;height:10px;border-radius:50%;border:2px solid #fff;}
.pd-ev.concluido{opacity:.5;text-decoration:line-through;}
.pd-ev-hora{font-size:.65rem;font-weight:700;color:var(--text-muted);margin-bottom:.1rem;}
.pd-ev-titulo{font-size:.82rem;font-weight:600;color:var(--petrol-900);}
.pd-ev-detalhe{font-size:.68rem;color:var(--text-muted);}
.pd-resumo-item{display:flex;align-items:center;gap:.6rem;padding:.5rem .6rem;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;transition:background .15s;border-radius:6px;}
.pd-resumo-item:hover{background:rgba(184,115,51,.06);}
.pd-resumo-num{font-size:1.2rem;font-weight:800;min-width:28px;text-align:center;}
.pd-resumo-label{font-size:.78rem;color:var(--text-muted);}
.pd-lembrete{display:flex;align-items:center;gap:.5rem;padding:.4rem .5rem;border-bottom:1px solid var(--border);font-size:.82rem;}
.pd-lembrete.done{opacity:.5;text-decoration:line-through;}
.pd-lembrete-check{width:18px;height:18px;border-radius:4px;border:2px solid var(--border);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.65rem;flex-shrink:0;transition:all .15s;}
.pd-lembrete-check:hover{border-color:#B87333;}
.pd-lembrete-check.done{background:#059669;border-color:#059669;color:#fff;}
.pd-empty{text-align:center;padding:2rem 1rem;color:var(--text-muted);font-size:.85rem;}
.pd-empty .big{font-size:2rem;margin-bottom:.5rem;}
</style>

<!-- Saudação -->
<div class="pd-saudacao">
    <div>
        <h2><?= $saudacao ?>, <?= e($userName) ?>! <?= $emoji ?></h2>
        <div class="sub">Hoje é <?= $diaSemana ?>, <?= $dataExtenso ?>.</div>
    </div>
    <?php if ($isGestao && count($users) > 1): ?>
    <div>
        <select onchange="location.href='?user='+this.value" style="font-size:.78rem;padding:4px 10px;border-radius:6px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.1);color:#fff;">
            <option value="<?= $userId ?>" style="color:#000;">Minha agenda</option>
            <?php foreach ($users as $u): if ((int)$u['id'] === $userId) continue; ?>
            <option value="<?= $u['id'] ?>" <?= $viewUserId === (int)$u['id'] ? 'selected' : '' ?> style="color:#000;"><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</div>

<div class="pd-grid">
    <!-- COLUNA 1: Agenda -->
    <div class="pd-card">
        <h3>📅 Agenda de Hoje (<?= count($agendaHoje) ?>)</h3>
        <?php if (empty($agendaHoje)): ?>
            <div class="pd-empty">
                <div class="big">🎉</div>
                Nenhum compromisso para hoje.<br>Aproveite para organizar suas pastas!
            </div>
        <?php else: ?>
            <div class="pd-timeline">
                <?php foreach ($agendaHoje as $ev): ?>
                <div class="pd-ev <?= $ev['concluido'] ? 'concluido' : '' ?>" style="--dot-color:<?= $ev['cor'] ?>;">
                    <style>.pd-ev::before{background:var(--dot-color,#888);}</style>
                    <div class="pd-ev-hora"><?= $ev['badge'] ?> <?= $ev['hora'] ?> <?php if ($ev['processo']): ?><span style="font-family:monospace;font-size:.6rem;opacity:.7;"><?= e($ev['processo']) ?></span><?php endif; ?></div>
                    <div class="pd-ev-titulo">
                        <?= e($ev['titulo']) ?>
                        <?php if ($ev['link']): ?>
                            <a href="<?= e($ev['link']) ?>" target="_blank" style="font-size:.65rem;background:#052228;color:#fff;padding:1px 6px;border-radius:3px;text-decoration:none;margin-left:4px;">Abrir</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($ev['detalhe']): ?><div class="pd-ev-detalhe"><?= e($ev['detalhe']) ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- COLUNA 2: Resumo -->
    <div class="pd-card">
        <h3>📊 Resumo do Dia</h3>
        <a href="<?= url('modules/tarefas/') ?>" class="pd-resumo-item">
            <div class="pd-resumo-num" style="color:#059669;">📋 <?= $resumo['tarefas'] ?? 0 ?></div>
            <div class="pd-resumo-label">tarefas pendentes hoje</div>
        </a>
        <a href="<?= url('modules/agenda/') ?>" class="pd-resumo-item">
            <div class="pd-resumo-num" style="color:#2563eb;">⚖️ <?= $resumo['audiencias'] ?? 0 ?></div>
            <div class="pd-resumo-label">audiências agendadas</div>
        </a>
        <a href="<?= url('modules/prazos/') ?>" class="pd-resumo-item">
            <div class="pd-resumo-num" style="color:#dc2626;">⏰ <?= $resumo['prazos'] ?? 0 ?></div>
            <div class="pd-resumo-label">prazos vencendo hoje</div>
        </a>
        <a href="<?= url('modules/operacional/?filtro=doc_faltante') ?>" class="pd-resumo-item">
            <div class="pd-resumo-num" style="color:#d97706;">📄 <?= $resumo['docs_faltantes'] ?? 0 ?></div>
            <div class="pd-resumo-label">documentos faltantes ativos</div>
        </a>
        <?php if ($isGestao): ?>
        <a href="<?= url('modules/cobranca_honorarios/?aba=fila') ?>" class="pd-resumo-item">
            <div class="pd-resumo-num" style="color:#dc2626;">💰 <?= $resumo['cobrancas'] ?? 0 ?></div>
            <div class="pd-resumo-label">cobranças em aberto</div>
        </a>
        <?php endif; ?>
        <a href="<?= url('modules/helpdesk/') ?>" class="pd-resumo-item">
            <div class="pd-resumo-num" style="color:#6366f1;">🔔 <?= $resumo['chamados'] ?? 0 ?></div>
            <div class="pd-resumo-label">chamados abertos</div>
        </a>
    </div>

    <!-- COLUNA 3: Lembretes -->
    <div class="pd-card">
        <h3 style="justify-content:space-between;">
            <span>💬 Lembretes</span>
            <button onclick="document.getElementById('modalLembrete').style.display='flex';" class="btn btn-primary btn-sm" style="font-size:.68rem;background:#B87333;padding:.2rem .6rem;">+ Lembrete</button>
        </h3>
        <div id="listaLembretes">
        <?php
        $lembretes = array();
        try {
            $stmtLR = $pdo->prepare("SELECT * FROM eventos_dia WHERE usuario_id = ? AND data_evento = ? AND tipo = 'lembrete' ORDER BY concluido ASC, hora_inicio ASC, criado_em ASC");
            $stmtLR->execute(array($viewUserId, $hoje));
            $lembretes = $stmtLR->fetchAll();
        } catch (Exception $e) {}
        if (empty($lembretes)): ?>
            <div class="pd-empty" style="padding:1rem;">
                <div class="big">📝</div>
                Nenhum lembrete para hoje.<br>Crie um clicando em "+ Lembrete".
            </div>
        <?php else: ?>
            <?php foreach ($lembretes as $l): $done = (bool)$l['concluido']; ?>
            <div class="pd-lembrete <?= $done ? 'done' : '' ?>">
                <div class="pd-lembrete-check <?= $done ? 'done' : '' ?>" onclick="toggleLembrete(<?= $l['id'] ?>, this)" title="<?= $done ? 'Desfazer' : 'Concluir' ?>"><?= $done ? '✓' : '' ?></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;"><?= e($l['titulo']) ?></div>
                    <?php if ($l['hora_inicio']): ?><div style="font-size:.65rem;color:var(--text-muted);"><?= date('H:i', strtotime($l['hora_inicio'])) ?></div><?php endif; ?>
                </div>
                <?php if ($l['prioridade'] === 'urgente'): ?><span style="font-size:.55rem;background:#dc2626;color:#fff;padding:1px 4px;border-radius:3px;font-weight:700;">URGENTE</span><?php endif; ?>
                <?php if ($l['prioridade'] === 'fatal'): ?><span style="font-size:.55rem;background:#7c2d12;color:#fff;padding:1px 4px;border-radius:3px;font-weight:700;">FATAL</span><?php endif; ?>
                <button onclick="excluirLembrete(<?= $l['id'] ?>)" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:.8rem;" title="Excluir">🗑️</button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Novo Lembrete -->
<div id="modalLembrete" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:420px;width:95%;box-shadow:0 20px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:1rem;margin:0 0 1rem;color:#052228;">💬 Novo Lembrete</h3>
    <form method="POST" action="<?= module_url('painel', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="criar_lembrete">
        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">O que precisa lembrar? *</label>
            <input type="text" name="titulo" class="form-input" required placeholder="Ex: Ligar para cliente às 14h">
        </div>
        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Horário</label>
                <input type="time" name="hora_inicio" class="form-input">
            </div>
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Prioridade</label>
                <select name="prioridade" class="form-select">
                    <option value="normal">Normal</option>
                    <option value="urgente">Urgente</option>
                    <option value="fatal">Fatal</option>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalLembrete').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Criar Lembrete</button>
        </div>
    </form>
</div></div>

<script>
function toggleLembrete(id, el) {
    var fd = new FormData();
    fd.append('action', 'toggle_lembrete');
    fd.append('id', id);
    fd.append('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    fetch('<?= module_url('painel', 'api.php') ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(r) { if (r.ok) location.reload(); });
}
function excluirLembrete(id) {
    if (!confirm('Excluir este lembrete?')) return;
    var fd = new FormData();
    fd.append('action', 'excluir_lembrete');
    fd.append('id', id);
    fd.append('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    fetch('<?= module_url('painel', 'api.php') ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(r) { if (r.ok) location.reload(); });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
