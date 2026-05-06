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

$diasSemana = array('Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado');
$meses = array('','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
$hoje = date('Y-m-d');
$diaW = (int)date('w');
$diaSemana = $diasSemana[$diaW];
$dataExtenso = (int)date('j') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y');
$isFimSemana = ($diaW === 0 || $diaW === 6);
$isSexta = ($diaW === 5);

// Mensagens motivacionais para dias vazios (rotativas por dia do ano)
$idxMsg = (int)date('z') % 12;
if ($isFimSemana) {
    $saudacao = 'Bom ' . ($diaW === 6 ? 'sábado' : 'domingo');
    $emoji = '🌿';
    $msgsVazias = array(
        array('emoji' => '☕', 'titulo' => 'Hora de desacelerar.', 'sub' => 'Descanso não é pausa — é combustível para segunda.'),
        array('emoji' => '🌿', 'titulo' => 'Aproveite seu fim de semana!', 'sub' => 'O escritório pode esperar. Sua família e você não.'),
        array('emoji' => '📖', 'titulo' => 'Um bom livro, um café, um respiro.', 'sub' => 'Você merece esse tempo.'),
        array('emoji' => '🌊', 'titulo' => 'Desligue. Respire. Recomece.', 'sub' => 'Segunda-feira tem seu próprio ritmo.'),
        array('emoji' => '✨', 'titulo' => 'Hoje é dia de viver.', 'sub' => 'A advocacia continua amanhã — você é mais que ela.'),
        array('emoji' => '🏡', 'titulo' => 'Casa, família, silêncio.', 'sub' => 'Os melhores advogados sabem quando parar.'),
        array('emoji' => '🌸', 'titulo' => 'Permita-se não fazer nada.', 'sub' => 'O mundo gira sem você também — e tudo bem.'),
        array('emoji' => '🍃', 'titulo' => 'Descanse com intenção.', 'sub' => 'Presença com quem você ama vale mais que qualquer pauta.'),
        array('emoji' => '🎶', 'titulo' => 'Música, conversa, presença.', 'sub' => 'O que alimenta sua alma hoje?'),
        array('emoji' => '☁️', 'titulo' => 'Dia de leveza.', 'sub' => 'Seus processos estão seguros — relaxe.'),
        array('emoji' => '🌅', 'titulo' => 'Recarregue as energias.', 'sub' => 'Uma Amanda descansada vale por três.'),
        array('emoji' => '🤍', 'titulo' => 'Você fez o suficiente esta semana.', 'sub' => 'Agradeça, descanse e desfrute.'),
    );
} else {
    if ($hora < 12) { $saudacao = 'Bom dia'; $emoji = '☀️'; }
    elseif ($hora < 18) { $saudacao = 'Boa tarde'; $emoji = '🌤️'; }
    else { $saudacao = 'Boa noite'; $emoji = '🌙'; }

    $msgsSexta = array(
        array('emoji' => '🎉', 'titulo' => 'Sexta-feira leve para você!', 'sub' => 'Feche a semana com calma — o fim de semana chegou.'),
        array('emoji' => '🌟', 'titulo' => 'Última reta da semana.', 'sub' => 'Organize, delegue e descanse amanhã.'),
    );
    $msgsNormais = array(
        array('emoji' => '🎯', 'titulo' => 'Nenhum compromisso para hoje.', 'sub' => 'Aproveite para organizar pastas ou adiantar petições.'),
        array('emoji' => '✨', 'titulo' => 'Hoje está calmo!', 'sub' => 'Que tal revisar processos pendentes ou criar um artigo na Wiki?'),
        array('emoji' => '🚀', 'titulo' => 'Agenda livre — tempo precioso.', 'sub' => 'Use para o que você nunca tem tempo: estratégia.'),
        array('emoji' => '📚', 'titulo' => 'Dia para estudar e planejar.', 'sub' => 'Que tal ler um julgado interessante?'),
        array('emoji' => '💡', 'titulo' => 'Sem correria hoje.', 'sub' => 'Bons planos nascem em dias calmos.'),
        array('emoji' => '🎯', 'titulo' => 'Foco no que importa.', 'sub' => 'Seus processos agradecem o tempo dedicado.'),
        array('emoji' => '☕', 'titulo' => 'Café, silêncio e boas ideias.', 'sub' => 'Dia perfeito para adiantar tarefas.'),
        array('emoji' => '📝', 'titulo' => 'Dia de revisar e planejar.', 'sub' => 'Pequenos ajustes hoje = grandes resultados amanhã.'),
    );
    $msgsVazias = $isSexta ? array_merge($msgsSexta, $msgsNormais) : $msgsNormais;
}
$msgVazia = $msgsVazias[$idxMsg % count($msgsVazias)];

// ─── COLUNA 1: Agenda de Hoje ───
$agendaHoje = array();

// Eventos da agenda
try {
    $sql = "SELECT e.id, e.titulo, e.tipo, e.data_inicio, e.data_fim, e.local, e.meet_link, e.status,
                   e.case_id, c.name as client_name, cs.title as case_title, cs.case_number, u.name as resp_name
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
            'case_id' => (int)($ev['case_id'] ?? 0),
        );
    }
} catch (Exception $e) {}

// Prazos fatais
try {
    $stmtP = $pdo->prepare(
        "SELECT p.id, p.case_id, p.descricao_acao, p.prazo_fatal, p.numero_processo, p.concluido, cs.title as case_title
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
            'case_id' => (int)($p['case_id'] ?? 0),
        );
    }
} catch (Exception $e) {}

// Tarefas com prazo hoje
try {
    $sqlT = "SELECT ct.id, ct.case_id, ct.title, ct.status, ct.due_date, cs.title as case_title, cs.case_number
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
            'case_id' => (int)($t['case_id'] ?? 0),
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
    // Self-heal: coluna pra co-responsáveis (silenciosa se já existe)
    try { $pdo->exec("ALTER TABLE case_tasks ADD COLUMN assigned_extra_ids VARCHAR(500) NULL"); } catch (Exception $e) {}
    // Conta tarefas do dia: gestão vê todas; demais veem onde são responsável OU co-responsável
    $tarefaFiltro = !$isGestao ? " AND (assigned_to = $userId OR FIND_IN_SET($userId, assigned_extra_ids))" : '';
    $stR = $pdo->prepare("SELECT COUNT(*) FROM case_tasks WHERE due_date = ? AND status != 'concluido'" . $tarefaFiltro);
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
/* === Lembretes estilo post-it === */
.pd-lembretes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.6rem;padding:.4rem;}
.pd-postit{position:relative;padding:.7rem .65rem .55rem;border-radius:2px 14px 2px 2px;font-size:.82rem;line-height:1.3;box-shadow:2px 3px 8px rgba(0,0,0,.18),inset 0 0 0 1px rgba(0,0,0,.04);min-height:90px;display:flex;flex-direction:column;gap:.35rem;font-family:'Caveat','Comic Sans MS',cursive;transition:transform .15s, box-shadow .15s;}
.pd-postit:hover{transform:translateY(-2px) rotate(0deg) !important;box-shadow:3px 5px 14px rgba(0,0,0,.25);}
.pd-postit:nth-child(3n){transform:rotate(-1.2deg);}
.pd-postit:nth-child(3n+1){transform:rotate(.8deg);}
.pd-postit:nth-child(3n+2){transform:rotate(-.4deg);}
.pd-postit.cor-amarelo{background:linear-gradient(135deg,#fff9b1,#ffeaa7);}
.pd-postit.cor-rosa{background:linear-gradient(135deg,#fbcfe8,#f9a8d4);}
.pd-postit.cor-verde{background:linear-gradient(135deg,#bbf7d0,#86efac);}
.pd-postit.cor-azul{background:linear-gradient(135deg,#bfdbfe,#93c5fd);}
.pd-postit.cor-laranja{background:linear-gradient(135deg,#fed7aa,#fdba74);}
.pd-postit.cor-roxo{background:linear-gradient(135deg,#e9d5ff,#d8b4fe);}
.pd-postit.done{opacity:.55;}
.pd-postit.done .pd-postit-titulo{text-decoration:line-through;text-decoration-thickness:2px;color:#6b7280;}
.pd-postit-titulo{font-size:.95rem;font-weight:600;color:#1f2937;word-wrap:break-word;cursor:pointer;flex:1;}
.pd-postit-meta{font-size:.7rem;color:#374151;opacity:.8;font-family:inherit;}
.pd-postit-acoes{display:flex;gap:.3rem;justify-content:flex-end;margin-top:.2rem;font-family:system-ui;}
.pd-postit-acoes button{background:rgba(255,255,255,.6);border:none;cursor:pointer;font-size:.75rem;padding:2px 6px;border-radius:4px;transition:background .15s;}
.pd-postit-acoes button:hover{background:rgba(255,255,255,.95);}
.pd-postit-pri{position:absolute;top:-6px;right:8px;font-size:.55rem;padding:1px 6px;border-radius:99px;font-weight:700;letter-spacing:.5px;color:#fff;}
.pd-postit-pri.urgente{background:#dc2626;}
.pd-postit-pri.fatal{background:#7c2d12;}
/* Cores opções no modal e popover de troca */
.pd-cor-opt{width:24px;height:24px;border-radius:50%;cursor:pointer;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .15s;}
.pd-cor-opt:hover,.pd-cor-opt.sel{transform:scale(1.2);box-shadow:0 0 0 2px #B87333;}
.pd-cor-amarelo{background:#fff9b1;}
.pd-cor-rosa{background:#fbcfe8;}
.pd-cor-verde{background:#bbf7d0;}
.pd-cor-azul{background:#bfdbfe;}
.pd-cor-laranja{background:#fed7aa;}
.pd-cor-roxo{background:#e9d5ff;}
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
                <div class="big"><?= $msgVazia['emoji'] ?></div>
                <strong style="color:var(--petrol-900);font-size:.95rem;"><?= e($msgVazia['titulo']) ?></strong><br>
                <span style="font-style:italic;"><?= e($msgVazia['sub']) ?></span>
            </div>
        <?php else: ?>
            <div class="pd-timeline">
                <?php foreach ($agendaHoje as $ev):
                    $_cid = (int)($ev['case_id'] ?? 0);
                    $_href = $_cid > 0 ? url('modules/operacional/caso_ver.php?id=' . $_cid) : null;
                ?>
                <?php if ($_href): ?>
                <a href="<?= e($_href) ?>" class="pd-ev pd-ev-clickable <?= $ev['concluido'] ? 'concluido' : '' ?>" style="--dot-color:<?= $ev['cor'] ?>;text-decoration:none;color:inherit;display:block;" title="Abrir processo #<?= $_cid ?>">
                <?php else: ?>
                <div class="pd-ev <?= $ev['concluido'] ? 'concluido' : '' ?>" style="--dot-color:<?= $ev['cor'] ?>;">
                <?php endif; ?>
                    <style>.pd-ev::before{background:var(--dot-color,#888);} .pd-ev-clickable{transition:background .15s;} .pd-ev-clickable:hover{background:rgba(215,171,144,.12);}</style>
                    <div class="pd-ev-hora"><?= $ev['badge'] ?> <?= $ev['hora'] ?> <?php if ($ev['processo']): ?><span style="font-family:monospace;font-size:.6rem;opacity:.7;"><?= e($ev['processo']) ?></span><?php endif; ?></div>
                    <div class="pd-ev-titulo">
                        <?= e($ev['titulo']) ?>
                        <?php if ($ev['link']): ?>
                            <a href="<?= e($ev['link']) ?>" target="_blank" onclick="event.stopPropagation();" style="font-size:.65rem;background:#052228;color:#fff;padding:1px 6px;border-radius:3px;text-decoration:none;margin-left:4px;">Abrir Meet</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($ev['detalhe']): ?><div class="pd-ev-detalhe"><?= e($ev['detalhe']) ?></div><?php endif; ?>
                <?php if ($_href): ?>
                </a>
                <?php else: ?>
                </div>
                <?php endif; ?>
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
            <span style="display:flex;gap:.3rem;">
                <button onclick="abrirArquivados()" class="btn btn-outline btn-sm" style="font-size:.68rem;padding:.2rem .5rem;" title="Ver lembretes arquivados">📁</button>
                <button onclick="abrirModalLembrete()" class="btn btn-primary btn-sm" style="font-size:.68rem;background:#B87333;padding:.2rem .6rem;">+ Lembrete</button>
            </span>
        </h3>
        <div id="listaLembretes">
        <?php
        // Self-heal cor + arquivado
        try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN cor VARCHAR(20) DEFAULT 'amarelo'"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN arquivado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
        // Self-heal de vínculos
        try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN client_id INT UNSIGNED NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE eventos_dia ADD COLUMN case_id INT UNSIGNED NULL"); } catch (Exception $e) {}
        $lembretes = array();
        try {
            // Mostra lembretes de HOJE + em aberto atrasados (de dias passados, não concluídos, não arquivados)
            // + futuros (data > hoje) — pra Amanda ver lembretes que ela mesma agendou pra outras datas
            $stmtLR = $pdo->prepare("SELECT e.*,
                                            (e.data_evento < ? AND e.concluido = 0) AS atrasado,
                                            (e.data_evento > ?) AS futuro,
                                            c.name AS client_name,
                                            cs.title AS case_title,
                                            cs.case_number AS case_number
                                     FROM eventos_dia e
                                     LEFT JOIN clients c ON c.id = e.client_id
                                     LEFT JOIN cases cs ON cs.id = e.case_id
                                     WHERE e.usuario_id = ? AND e.tipo = 'lembrete'
                                       AND IFNULL(e.arquivado,0) = 0
                                       AND (e.data_evento = ? OR (e.data_evento < ? AND e.concluido = 0) OR e.data_evento > ?)
                                     ORDER BY atrasado DESC, e.data_evento ASC, e.concluido ASC, e.hora_inicio ASC, e.criado_em ASC");
            $stmtLR->execute(array($hoje, $hoje, $viewUserId, $hoje, $hoje, $hoje));
            $lembretes = $stmtLR->fetchAll();
        } catch (Exception $e) {}
        if (empty($lembretes)): ?>
            <div class="pd-empty" style="padding:1rem;">
                <div class="big">📝</div>
                Nenhum lembrete para hoje.<br>Crie um clicando em "+ Lembrete".
            </div>
        <?php else: ?>
            <div class="pd-lembretes-grid">
            <?php foreach ($lembretes as $l):
                $done = (bool)$l['concluido'];
                $cor = $l['cor'] ?? 'amarelo';
                if (!in_array($cor, array('amarelo','rosa','verde','azul','laranja','roxo'), true)) $cor = 'amarelo';
            ?>
            <div class="pd-postit cor-<?= e($cor) ?> <?= $done ? 'done' : '' ?>" data-lembrete-id="<?= $l['id'] ?>" onclick="clickPostit(event, <?= $l['id'] ?>)" style="cursor:pointer;" title="Clique para ver/editar; use o botão ✓ para marcar como cumprido">
                <?php if (!empty($l['atrasado'])): ?><span class="pd-postit-pri" style="background:#dc2626;">⚠ ATRASADO</span><?php endif; ?>
                <?php if (!empty($l['futuro']) && empty($l['atrasado'])): ?><span class="pd-postit-pri" style="background:#3b82f6;">📅 <?= date('d/m', strtotime($l['data_evento'])) ?></span><?php endif; ?>
                <?php if (empty($l['atrasado']) && empty($l['futuro']) && $l['prioridade'] === 'urgente'): ?><span class="pd-postit-pri urgente">URGENTE</span><?php endif; ?>
                <?php if (empty($l['atrasado']) && empty($l['futuro']) && $l['prioridade'] === 'fatal'): ?><span class="pd-postit-pri fatal">FATAL</span><?php endif; ?>
                <div class="pd-postit-titulo" title="Clique para ver as informações"><?= e($l['titulo']) ?></div>
                <?php if (!empty($l['atrasado'])): ?>
                    <div class="pd-postit-meta" style="color:#b91c1c;font-weight:600;">📅 de <?= date('d/m', strtotime($l['data_evento'])) ?></div>
                <?php endif; ?>
                <?php if ($l['hora_inicio']): ?><div class="pd-postit-meta">⏰ <?= date('H:i', strtotime($l['hora_inicio'])) ?></div><?php endif; ?>
                <?php if (!empty($l['client_name'])): ?>
                    <div class="pd-postit-meta">👤 <?= e($l['client_name']) ?></div>
                <?php endif; ?>
                <?php
                // Mostra info do processo: titulo OU numero (o que existir).
                $caseLabel = '';
                if (!empty($l['case_title'])) $caseLabel = $l['case_title'];
                elseif (!empty($l['case_number'])) $caseLabel = $l['case_number'];
                elseif (!empty($l['case_id'])) $caseLabel = 'Processo #' . $l['case_id'];
                if ($caseLabel): ?>
                    <div class="pd-postit-meta">⚖ <?= e(mb_substr($caseLabel, 0, 40)) ?><?= mb_strlen($caseLabel) > 40 ? '…' : '' ?></div>
                <?php endif; ?>
                <div class="pd-postit-acoes">
                    <button onclick="toggleLembrete(<?= $l['id'] ?>, this)" title="<?= $done ? 'Desfazer (desmarcar como cumprido)' : 'Marcar como cumprido (riscar)' ?>" style="background:<?= $done ? '#fee2e2' : '#d1fae5' ?>;"><?= $done ? '↩' : '✓' ?></button>
                    <button onclick="editarLembrete(<?= $l['id'] ?>)" title="Editar">✏</button>
                    <button onclick="abrirCorLembrete(<?= $l['id'] ?>, this)" title="Trocar cor">🎨</button>
                    <button onclick="arquivarLembrete(<?= $l['id'] ?>)" title="Arquivar (some daqui sem apagar)">📁</button>
                    <button onclick="excluirLembrete(<?= $l['id'] ?>)" title="Excluir definitivo" style="color:#dc2626;">🗑</button>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Lembrete (criar OU editar — depende de modoLembreteEdit/idLembreteEdit) -->
<div id="modalLembrete" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;overflow-y:auto;padding:1rem 0;">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:520px;width:95%;box-shadow:0 20px 40px rgba(0,0,0,.2);max-height:92vh;overflow-y:auto;">
    <h3 id="modalLembreteTitulo" style="font-size:1rem;margin:0 0 1rem;color:#052228;">💬 Novo Lembrete</h3>
    <form id="formLembrete" onsubmit="event.preventDefault();salvarLembrete();">
        <input type="hidden" id="lembreteId" value="">
        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">O que precisa lembrar? *</label>
            <input type="text" id="lembreteTitulo" class="form-input" required placeholder="Ex: Ligar para cliente às 14h">
        </div>
        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:120px;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Data</label>
                <input type="date" id="lembreteData" class="form-input">
            </div>
            <div style="flex:1;min-width:100px;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Horário</label>
                <input type="time" id="lembreteHora" class="form-input">
            </div>
            <div style="flex:1;min-width:100px;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Prioridade</label>
                <select id="lembretePrioridade" class="form-select">
                    <option value="normal">Normal</option>
                    <option value="urgente">Urgente</option>
                    <option value="fatal">Fatal</option>
                </select>
            </div>
        </div>
        <!-- Vincular cliente -->
        <div style="margin-bottom:.6rem;position:relative;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">👤 Vincular cliente (opcional)</label>
            <input type="text" id="lembreteClienteBusca" class="form-input" placeholder="Digite o nome do cliente..." oninput="buscarClienteLembrete(this.value)" autocomplete="off">
            <input type="hidden" id="lembreteClienteId" value="">
            <div id="lembreteClienteResultados" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:6px;max-height:200px;overflow-y:auto;z-index:10;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
        </div>
        <!-- Vincular caso -->
        <div style="margin-bottom:.6rem;position:relative;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">⚖ Vincular processo (opcional)</label>
            <input type="text" id="lembreteCasoBusca" class="form-input" placeholder="Digite título, CNJ ou cliente..." oninput="buscarCasoLembrete(this.value)" autocomplete="off">
            <input type="hidden" id="lembreteCasoId" value="">
            <div id="lembreteCasoResultados" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:6px;max-height:200px;overflow-y:auto;z-index:10;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
        </div>
        <!-- Atribuir a outro usuário (só na criação) -->
        <div id="lembreteAtribuirWrap" style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">📌 Atribuir a (opcional — quem recebe o lembrete)</label>
            <select id="lembreteAtribuidoA" class="form-select">
                <option value="">Eu mesma</option>
                <?php
                $usuariosAtivos = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 AND id <> " . (int)$userId . " ORDER BY name")->fetchAll();
                foreach ($usuariosAtivos as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <small style="font-size:.65rem;color:var(--text-muted);">Se atribuir, o usuário recebe push e o lembrete aparece no painel dele.</small>
        </div>
        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.3rem;">Cor do post-it</label>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;" id="lembreteCorWrap">
                <?php foreach (array('amarelo','rosa','verde','azul','laranja','roxo') as $i => $cor): ?>
                    <label style="cursor:pointer;display:inline-flex;flex-direction:column;align-items:center;gap:2px;">
                        <input type="radio" name="lembreteCor" value="<?= $cor ?>" <?= $i === 0 ? 'checked' : '' ?> style="display:none;" onchange="document.querySelectorAll('.pd-cor-opt-modal').forEach(function(e){e.classList.remove('sel');});this.parentNode.querySelector('.pd-cor-opt-modal').classList.add('sel');">
                        <span class="pd-cor-opt pd-cor-opt-modal pd-cor-<?= $cor ?> <?= $i === 0 ? 'sel' : '' ?>"></span>
                        <span style="font-size:.6rem;color:var(--text-muted);"><?= ucfirst($cor) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalLembrete').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" id="btnSalvarLembrete" style="background:#B87333;">Criar Lembrete</button>
        </div>
    </form>
</div></div>

<!-- Modal Lembretes Arquivados -->
<div id="modalLembretesArq" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;overflow-y:auto;padding:1rem 0;">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:680px;width:95%;box-shadow:0 20px 40px rgba(0,0,0,.2);max-height:92vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
        <h3 style="font-size:1rem;margin:0;color:#052228;">📁 Lembretes arquivados</h3>
        <button onclick="document.getElementById('modalLembretesArq').style.display='none';" style="background:none;border:none;font-size:1.2rem;cursor:pointer;">✕</button>
    </div>
    <div id="listaArquivados" style="font-size:.85rem;">
        <div style="text-align:center;color:var(--text-muted);padding:2rem;">Carregando…</div>
    </div>
</div></div>

<script>
var LEMBRETE_API = '<?= module_url('painel', 'api.php') ?>';
var LEMBRETE_CSRF = '<?= generate_csrf_token() ?>';
function _lemFD(action, extra) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('<?= CSRF_TOKEN_NAME ?>', LEMBRETE_CSRF);
    if (extra) for (var k in extra) fd.append(k, extra[k]);
    return fd;
}
function abrirModalLembrete() {
    document.getElementById('lembreteId').value = '';
    document.getElementById('lembreteTitulo').value = '';
    document.getElementById('lembreteData').value = '<?= date('Y-m-d') ?>';
    document.getElementById('lembreteHora').value = '';
    document.getElementById('lembretePrioridade').value = 'normal';
    document.getElementById('lembreteClienteBusca').value = '';
    document.getElementById('lembreteClienteId').value = '';
    document.getElementById('lembreteCasoBusca').value = '';
    document.getElementById('lembreteCasoId').value = '';
    document.getElementById('lembreteAtribuidoA').value = '';
    document.getElementById('lembreteAtribuirWrap').style.display = 'block';
    document.querySelector('input[name="lembreteCor"][value="amarelo"]').checked = true;
    document.querySelectorAll('.pd-cor-opt-modal').forEach(function(e){e.classList.remove('sel');});
    document.querySelector('.pd-cor-opt-modal.pd-cor-amarelo').classList.add('sel');
    document.getElementById('modalLembreteTitulo').textContent = '💬 Novo Lembrete';
    document.getElementById('btnSalvarLembrete').textContent = 'Criar Lembrete';
    document.getElementById('modalLembrete').style.display = 'flex';
    setTimeout(function(){ document.getElementById('lembreteTitulo').focus(); }, 50);
}
// Clique no card do post-it abre o modal com todas as informacoes.
// So ignora se o clique foi diretamente em um botao (acoes/cor/arquivar/etc).
function clickPostit(e, id) {
    if (e.target.closest('.pd-postit-acoes, button, input, select, a')) return;
    editarLembrete(id);
}
function editarLembrete(id) {
    fetch(LEMBRETE_API, { method: 'POST', body: _lemFD('obter_lembrete', {id: id}) })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.ok) { alert(d.error || 'Erro'); return; }
        var l = d.lembrete;
        document.getElementById('lembreteId').value = l.id;
        document.getElementById('lembreteTitulo').value = l.titulo || '';
        document.getElementById('lembreteData').value = l.data_evento || '';
        document.getElementById('lembreteHora').value = l.hora_inicio ? l.hora_inicio.substring(0,5) : '';
        document.getElementById('lembretePrioridade').value = l.prioridade || 'normal';
        document.getElementById('lembreteClienteId').value = l.client_id || '';
        document.getElementById('lembreteClienteBusca').value = l.client_name || '';
        document.getElementById('lembreteCasoId').value = l.case_id || '';
        // Mostra titulo + numero do processo. Se um faltar, usa o que houver.
        // Se nada existir mas houver case_id, mostra "Processo #N" como fallback.
        var caseDisplay = '';
        if (l.case_title) caseDisplay = l.case_title;
        if (l.case_number) caseDisplay += (caseDisplay ? ' — ' : '') + l.case_number;
        if (!caseDisplay && l.case_id) caseDisplay = 'Processo #' + l.case_id;
        document.getElementById('lembreteCasoBusca').value = caseDisplay;
        document.getElementById('lembreteAtribuirWrap').style.display = 'none'; // não dá pra reatribuir editando
        var cor = l.cor || 'amarelo';
        var radio = document.querySelector('input[name="lembreteCor"][value="' + cor + '"]');
        if (radio) radio.checked = true;
        document.querySelectorAll('.pd-cor-opt-modal').forEach(function(e){e.classList.remove('sel');});
        var sel = document.querySelector('.pd-cor-opt-modal.pd-cor-' + cor);
        if (sel) sel.classList.add('sel');
        document.getElementById('modalLembreteTitulo').textContent = '✏ Editar Lembrete';
        document.getElementById('btnSalvarLembrete').textContent = 'Salvar';
        document.getElementById('modalLembrete').style.display = 'flex';
    });
}
function salvarLembrete() {
    var id = document.getElementById('lembreteId').value;
    var dados = {
        titulo: document.getElementById('lembreteTitulo').value,
        data_evento: document.getElementById('lembreteData').value,
        hora_inicio: document.getElementById('lembreteHora').value,
        prioridade: document.getElementById('lembretePrioridade').value,
        cor: document.querySelector('input[name="lembreteCor"]:checked').value,
        client_id: document.getElementById('lembreteClienteId').value,
        case_id: document.getElementById('lembreteCasoId').value
    };
    if (!id) {
        dados.atribuido_a = document.getElementById('lembreteAtribuidoA').value;
    } else {
        dados.id = id;
    }
    fetch(LEMBRETE_API, { method: 'POST', body: _lemFD(id ? 'editar_lembrete' : 'criar_lembrete', dados) })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.error) { alert(d.error); return; }
        location.reload();
    });
}
var _lemSearchTimer;
function buscarClienteLembrete(q) {
    clearTimeout(_lemSearchTimer);
    var box = document.getElementById('lembreteClienteResultados');
    document.getElementById('lembreteClienteId').value = ''; // limpa ao digitar
    if (q.length < 2) { box.style.display = 'none'; return; }
    box.innerHTML = '<div style="padding:.5rem .7rem;font-size:.78rem;color:#9ca3af;">⏳ Buscando...</div>';
    box.style.display = 'block';
    _lemSearchTimer = setTimeout(function(){
        fetch(LEMBRETE_API + '?action=buscar_clientes_lembrete&q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(arr){
            if (!Array.isArray(arr) || !arr.length) {
                box.innerHTML = '<div style="padding:.5rem .7rem;font-size:.78rem;color:#9ca3af;">Nenhum cliente com esse nome.</div>';
                return;
            }
            box.innerHTML = arr.map(function(c){
                return '<div onclick="selecionarClienteLembrete(' + c.id + ',' + JSON.stringify(c.name) + ')" style="padding:.4rem .6rem;cursor:pointer;border-bottom:1px solid #eee;font-size:.8rem;" onmouseover="this.style.background=\'#f3f4f6\'" onmouseout="this.style.background=\'\'">' + c.name + (c.cpf ? ' <span style="color:#94a3b8;font-size:.7rem;">' + c.cpf + '</span>' : '') + '</div>';
            }).join('');
        })
        .catch(function(err){
            box.innerHTML = '<div style="padding:.5rem .7rem;font-size:.78rem;color:#dc2626;">Erro ao buscar: ' + err.message + '</div>';
        });
    }, 250);
}
function selecionarClienteLembrete(id, nome) {
    document.getElementById('lembreteClienteId').value = id;
    document.getElementById('lembreteClienteBusca').value = nome;
    document.getElementById('lembreteClienteResultados').style.display = 'none';
}
function buscarCasoLembrete(q) {
    clearTimeout(_lemSearchTimer);
    var box = document.getElementById('lembreteCasoResultados');
    document.getElementById('lembreteCasoId').value = '';
    if (q.length < 2) { box.style.display = 'none'; return; }
    box.innerHTML = '<div style="padding:.5rem .7rem;font-size:.78rem;color:#9ca3af;">⏳ Buscando...</div>';
    box.style.display = 'block';
    _lemSearchTimer = setTimeout(function(){
        fetch(LEMBRETE_API + '?action=buscar_casos_lembrete&q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(arr){
            if (!Array.isArray(arr) || !arr.length) {
                box.innerHTML = '<div style="padding:.5rem .7rem;font-size:.78rem;color:#9ca3af;">Nenhum processo encontrado.</div>';
                return;
            }
            box.innerHTML = arr.map(function(c){
                var label = (c.title || '') + (c.case_number ? ' — ' + c.case_number : '') + (c.client_name ? ' (' + c.client_name + ')' : '');
                return '<div onclick="selecionarCasoLembrete(' + c.id + ',' + JSON.stringify(label) + ')" style="padding:.4rem .6rem;cursor:pointer;border-bottom:1px solid #eee;font-size:.8rem;" onmouseover="this.style.background=\'#f3f4f6\'" onmouseout="this.style.background=\'\'">' + label + '</div>';
            }).join('');
        })
        .catch(function(err){
            box.innerHTML = '<div style="padding:.5rem .7rem;font-size:.78rem;color:#dc2626;">Erro ao buscar: ' + err.message + '</div>';
        });
    }, 250);
}
function selecionarCasoLembrete(id, label) {
    document.getElementById('lembreteCasoId').value = id;
    document.getElementById('lembreteCasoBusca').value = label;
    document.getElementById('lembreteCasoResultados').style.display = 'none';
}
function abrirArquivados() {
    document.getElementById('modalLembretesArq').style.display = 'flex';
    document.getElementById('listaArquivados').innerHTML = '<div style="text-align:center;color:#999;padding:2rem;">Carregando…</div>';
    fetch(LEMBRETE_API, { method: 'POST', body: _lemFD('listar_arquivados', {}) })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.ok) { document.getElementById('listaArquivados').innerHTML = '<div style="color:#dc2626;">' + (d.error || 'Erro') + '</div>'; return; }
        if (!d.lembretes.length) {
            document.getElementById('listaArquivados').innerHTML = '<div style="text-align:center;color:#999;padding:2rem;">Nenhum lembrete arquivado.</div>';
            return;
        }
        document.getElementById('listaArquivados').innerHTML = d.lembretes.map(function(l){
            var meta = [];
            if (l.data_evento) meta.push('📅 ' + l.data_evento.split('-').reverse().join('/'));
            if (l.hora_inicio) meta.push('⏰ ' + l.hora_inicio.substring(0,5));
            if (l.client_name) meta.push('👤 ' + l.client_name);
            if (l.case_title) meta.push('⚖ ' + l.case_title.substring(0,30));
            return '<div style="padding:.6rem .8rem;border:1px solid #eee;border-radius:8px;margin-bottom:.5rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem;background:#fafafa;">'
                 + '<div style="flex:1;"><div style="font-weight:600;font-size:.88rem;">' + l.titulo + '</div>'
                 + (meta.length ? '<div style="font-size:.7rem;color:#6b7280;margin-top:.2rem;">' + meta.join(' · ') + '</div>' : '')
                 + '</div>'
                 + '<button onclick="desarquivarLembrete(' + l.id + ')" style="background:#059669;color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:.72rem;">↩ Desarquivar</button>'
                 + '</div>';
        }).join('');
    });
}
function desarquivarLembrete(id) {
    fetch(LEMBRETE_API, { method: 'POST', body: _lemFD('desarquivar_lembrete', {id: id}) })
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.ok) location.reload(); });
}
function toggleLembrete(id, el) {
    var fd = _lemFD('toggle_lembrete', {id: id});
    fetch(LEMBRETE_API, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(r) { if (r.ok) location.reload(); });
}
function excluirLembrete(id) {
    if (!confirm('Excluir este lembrete? (apaga definitivo — pra esconder sem apagar use 📁 arquivar)')) return;
    var fd = new FormData();
    fd.append('action', 'excluir_lembrete');
    fd.append('id', id);
    fd.append('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    fetch('<?= module_url('painel', 'api.php') ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(r) { if (r.ok) location.reload(); });
}
function arquivarLembrete(id) {
    var fd = new FormData();
    fd.append('action', 'arquivar_lembrete');
    fd.append('id', id);
    fd.append('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    fetch('<?= module_url('painel', 'api.php') ?>', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(r) { if (r.ok) {
        var el = document.querySelector('[data-lembrete-id="' + id + '"]');
        if (el) { el.style.transition='opacity .3s,transform .3s'; el.style.opacity='0'; el.style.transform='scale(.7) rotate(8deg)'; setTimeout(function(){ el.remove(); }, 320); }
    }});
}
function abrirCorLembrete(id, btn) {
    var cores = ['amarelo','rosa','verde','azul','laranja','roxo'];
    var pop = document.createElement('div');
    pop.style.cssText = 'position:absolute;background:#fff;padding:.5rem;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.2);display:flex;gap:.4rem;z-index:9999;';
    cores.forEach(function(c) {
        var b = document.createElement('span');
        b.className = 'pd-cor-opt pd-cor-' + c;
        b.title = c;
        b.onclick = function() {
            var fd = new FormData();
            fd.append('action', 'mudar_cor_lembrete');
            fd.append('id', id);
            fd.append('cor', c);
            fd.append('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
            fetch('<?= module_url('painel', 'api.php') ?>', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); }).then(function(r) { if (r.ok) location.reload(); });
        };
        pop.appendChild(b);
    });
    var rect = btn.getBoundingClientRect();
    pop.style.top = (rect.bottom + window.scrollY + 4) + 'px';
    pop.style.left = (rect.left + window.scrollX - 60) + 'px';
    document.body.appendChild(pop);
    setTimeout(function() {
        document.addEventListener('click', function fechar(e) {
            if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', fechar); }
        });
    }, 100);
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
