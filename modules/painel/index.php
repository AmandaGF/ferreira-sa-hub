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
                   e.case_id, e.client_id, c.name as client_name, cs.title as case_title, cs.case_number, u.name as resp_name
            FROM agenda_eventos e
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN cases cs ON cs.id = e.case_id
            LEFT JOIN users u ON u.id = e.responsavel_id
            WHERE DATE(e.data_inicio) = ? AND e.status NOT IN ('cancelado','remarcado','nao_compareceu')";
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
            'client_id' => (int)($ev['client_id'] ?? 0),
        );
    }
} catch (Exception $e) {}

// Prazos fatais — INCLUI VENCIDOS NAO CONCLUIDOS (antes so prazo_fatal=hoje, fix 28/05/2026)
try {
    $stmtP = $pdo->prepare(
        "SELECT p.id, p.case_id, p.descricao_acao, p.prazo_fatal, p.numero_processo, p.concluido,
                cs.title as case_title,
                DATEDIFF(p.prazo_fatal, ?) AS dias_vs_hoje
         FROM prazos_processuais p LEFT JOIN cases cs ON cs.id = p.case_id
         WHERE p.prazo_fatal <= ? AND p.concluido = 0 ORDER BY p.prazo_fatal"
    );
    $stmtP->execute(array($hoje, $hoje));
    foreach ($stmtP->fetchAll() as $p) {
        $_diasVH = (int)$p['dias_vs_hoje'];
        $_eVencido = $_diasVH < 0;
        $_tituloPrz = $p['descricao_acao'];
        if ($_eVencido) {
            $_tituloPrz = '🚨 VENCIDO há ' . abs($_diasVH) . 'd — ' . $p['descricao_acao'];
        }
        $agendaHoje[] = array(
            'hora' => $_eVencido ? '🚨' : '⏰',
            'titulo' => $_tituloPrz,
            'tipo' => 'prazo',
            'badge' => $_eVencido ? '⚠️' : '🔴',
            'cor' => $_eVencido ? '#7f1d1d' : '#dc2626',
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

// Tarefas ATRASADAS - top 10 mais antigas + contador real (Amanda 01/06/2026)
// Antes so 'due_date = hoje' aparecia, escondendo 50+ atrasadas. Agora puxa
// as 10 mais velhas com badge vermelho + banner com total se passar de 10.
$_qtdTarefasAtrasadas = 0;
try {
    $filtroTA = !$isGestao ? " AND ct.assigned_to = $viewUserId" : '';
    $stTAc = $pdo->prepare("SELECT COUNT(*) FROM case_tasks ct WHERE ct.due_date < ? AND ct.status != 'concluido' AND ct.tipo IS NOT NULL AND ct.tipo != ''" . $filtroTA);
    $stTAc->execute(array($hoje));
    $_qtdTarefasAtrasadas = (int)$stTAc->fetchColumn();
    if ($_qtdTarefasAtrasadas > 0) {
        $stTA = $pdo->prepare(
            "SELECT ct.id, ct.case_id, ct.title, ct.due_date, cs.title as case_title, cs.case_number,
                    DATEDIFF(?, ct.due_date) AS dias_atraso
             FROM case_tasks ct LEFT JOIN cases cs ON cs.id = ct.case_id
             WHERE ct.due_date < ? AND ct.status != 'concluido' AND ct.tipo IS NOT NULL AND ct.tipo != ''" . $filtroTA . "
             ORDER BY ct.due_date ASC LIMIT 10"
        );
        $stTA->execute(array($hoje, $hoje));
        foreach ($stTA->fetchAll() as $t) {
            $diasAtr = (int)$t['dias_atraso'];
            $agendaHoje[] = array(
                'hora' => '🚨',
                'titulo' => 'ATRASADA há ' . $diasAtr . 'd — ' . $t['title'],
                'tipo' => 'tarefa_atrasada',
                'badge' => '⚠️',
                'cor' => '#7f1d1d',
                'detalhe' => $t['case_title'] ?: '',
                'link' => null,
                'processo' => $t['case_number'] ?: '',
                'concluido' => false,
                'id' => 'tka_' . $t['id'],
                'case_id' => (int)($t['case_id'] ?? 0),
                'dias_atraso' => $diasAtr,
                'atrasado' => true,
            );
        }
    }
} catch (Exception $e) {}

// Eventos ATRASADOS (agenda) - top 10 mais antigos + contador
$_qtdEventosAtrasados = 0;
try {
    $filtroEA = (!$isGestao || $viewUserId !== $userId) ? " AND e.responsavel_id = $viewUserId" : '';
    $stEAc = $pdo->prepare("SELECT COUNT(*) FROM agenda_eventos e WHERE DATE(e.data_inicio) < ? AND e.status NOT IN ('cancelado','remarcado','realizado','nao_compareceu')" . $filtroEA);
    $stEAc->execute(array($hoje));
    $_qtdEventosAtrasados = (int)$stEAc->fetchColumn();
    if ($_qtdEventosAtrasados > 0) {
        $stEA = $pdo->prepare(
            "SELECT e.id, e.titulo, e.tipo, e.data_inicio, e.case_id, e.client_id, c.name as client_name, cs.title as case_title, cs.case_number,
                    DATEDIFF(?, DATE(e.data_inicio)) AS dias_atraso
             FROM agenda_eventos e
             LEFT JOIN clients c ON c.id = e.client_id
             LEFT JOIN cases cs ON cs.id = e.case_id
             WHERE DATE(e.data_inicio) < ? AND e.status NOT IN ('cancelado','remarcado','realizado','nao_compareceu')" . $filtroEA . "
             ORDER BY e.data_inicio ASC LIMIT 10"
        );
        $stEA->execute(array($hoje, $hoje));
        foreach ($stEA->fetchAll() as $ev) {
            $diasAtr = (int)$ev['dias_atraso'];
            $agendaHoje[] = array(
                'hora' => '🚨',
                'titulo' => strtoupper($ev['tipo']) . ' ATRASADO ' . $diasAtr . 'd — ' . $ev['titulo'],
                'tipo' => 'evento_atrasado',
                'badge' => '⚠️',
                'cor' => '#7f1d1d',
                'detalhe' => ($ev['client_name'] ? $ev['client_name'] . ' · ' : '') . ($ev['case_title'] ?: ''),
                'link' => null,
                'processo' => $ev['case_number'] ?: '',
                'concluido' => false,
                'id' => 'eva_' . $ev['id'],
                'case_id' => (int)($ev['case_id'] ?? 0),
                'client_id' => (int)($ev['client_id'] ?? 0),
                'dias_atraso' => $diasAtr,
                'atrasado' => true,
            );
        }
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

// Ordenar: atrasados em cima (mais antigos primeiro), depois itens de hoje por hora
usort($agendaHoje, function($a, $b) {
    $aAtr = !empty($a['atrasado']) ? 0 : 1;
    $bAtr = !empty($b['atrasado']) ? 0 : 1;
    if ($aAtr !== $bAtr) return $aAtr - $bAtr;
    if (!empty($a['atrasado']) && !empty($b['atrasado'])) {
        return ((int)($b['dias_atraso'] ?? 0)) - ((int)($a['dias_atraso'] ?? 0));
    }
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

    // Conta prazos de HOJE + os VENCIDOS nao concluidos (antes so '=hoje' fazia vencidos sumirem)
    $resumo['prazos'] = (int)$pdo->query("SELECT COUNT(*) FROM prazos_processuais WHERE prazo_fatal <= '$hoje' AND concluido = 0")->fetchColumn();
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

// ══════════════════════════════════════════════════════════════════════
// 🎉 DOPAMINA — o que o usuário JÁ CUMPRIU hoje (baixas do dia)
// Conta por $viewUserId no dia de hoje: tarefas concluídas, prazos cumpridos,
// compromissos da agenda dados baixa (realizado) e chamados resolvidos.
// ══════════════════════════════════════════════════════════════════════
$dopa = array('tarefas' => 0, 'prazos' => 0, 'agenda' => 0, 'distribuicoes' => 0, 'helpdesk' => 0);
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM case_tasks WHERE status='concluido' AND assigned_to=? AND DATE(completed_at)=?");
    $q->execute(array($viewUserId, $hoje)); $dopa['tarefas'] = (int)$q->fetchColumn();
} catch (Exception $e) {}
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM prazos_processuais WHERE concluido=1 AND usuario_id=? AND DATE(concluido_em)=?");
    $q->execute(array($viewUserId, $hoje)); $dopa['prazos'] = (int)$q->fetchColumn();
} catch (Exception $e) {}
try {
    // Baixa na agenda (cumprido + balcão virtual realizado) — atribuída a QUEM deu baixa, via audit_log
    $q = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_type='agenda' AND user_id=? AND DATE(created_at)=? AND (action='AGENDA_BALCAO_REALIZADO' OR (action='AGENDA_STATUS' AND details LIKE 'Status: realizado%'))");
    $q->execute(array($viewUserId, $hoje)); $dopa['agenda'] = (int)$q->fetchColumn();
} catch (Exception $e) {}
try {
    // Helpdesk não tem campo "quem resolveu" — atribui via audit_log (quem deu o update no dia)
    $q = $pdo->prepare("SELECT COUNT(DISTINCT a.entity_id) FROM audit_log a
                        JOIN tickets t ON t.id = a.entity_id
                        WHERE a.action='ticket_updated' AND a.entity_type='ticket'
                          AND a.user_id=? AND DATE(a.created_at)=?
                          AND t.status='resolvido' AND DATE(t.resolved_at)=?");
    $q->execute(array($viewUserId, $hoje, $hoje)); $dopa['helpdesk'] = (int)$q->fetchColumn();
} catch (Exception $e) {}
try {
    // Distribuição de petição inicial (ajuizamento) — registrada via audit_log ao mover pra 'distribuido'
    $q = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action='processo_distribuido' AND entity_type='case' AND user_id=? AND DATE(created_at)=?");
    $q->execute(array($viewUserId, $hoje)); $dopa['distribuicoes'] = (int)$q->fetchColumn();
} catch (Exception $e) {}
$dopaTotal = array_sum($dopa);

// ── Progresso do dia: itens DATADOS para hoje (tarefas due hoje + prazos hoje + compromissos hoje) ──
$diaTot = 0; $diaFeito = 0;
try { $q = $pdo->prepare("SELECT COUNT(*) t, SUM(status='concluido') f FROM case_tasks WHERE assigned_to=? AND due_date=?");
      $q->execute(array($viewUserId, $hoje)); $r = $q->fetch(); $diaTot += (int)$r['t']; $diaFeito += (int)$r['f']; } catch (Exception $e) {}
try { $q = $pdo->prepare("SELECT COUNT(*) t, SUM(concluido=1) f FROM prazos_processuais WHERE usuario_id=? AND prazo_fatal=?");
      $q->execute(array($viewUserId, $hoje)); $r = $q->fetch(); $diaTot += (int)$r['t']; $diaFeito += (int)$r['f']; } catch (Exception $e) {}
try { $q = $pdo->prepare("SELECT COUNT(*) t, SUM(status='realizado') f FROM agenda_eventos WHERE responsavel_id=? AND DATE(data_inicio)=? AND status!='cancelado'");
      $q->execute(array($viewUserId, $hoje)); $r = $q->fetch(); $diaTot += (int)$r['t']; $diaFeito += (int)$r['f']; } catch (Exception $e) {}
$diaPct = $diaTot > 0 ? (int)round($diaFeito / $diaTot * 100) : 0;

// ── Histórico 7 dias (total + quebra por categoria, por dia) ──
$dias7 = array(); $dias7det = array();
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $dias7[$d] = 0;
    $dias7det[$d] = array('tarefas' => 0, 'prazos' => 0, 'agenda' => 0, 'distribuicoes' => 0, 'helpdesk' => 0);
}
$desde7 = date('Y-m-d', strtotime('-6 day')) . ' 00:00:00';
$histQ = array(
    'tarefas'       => array("SELECT DATE(completed_at) d, COUNT(*) c FROM case_tasks WHERE status='concluido' AND assigned_to=? AND completed_at>=? GROUP BY DATE(completed_at)", array($viewUserId, $desde7)),
    'prazos'        => array("SELECT DATE(concluido_em) d, COUNT(*) c FROM prazos_processuais WHERE concluido=1 AND usuario_id=? AND concluido_em>=? GROUP BY DATE(concluido_em)", array($viewUserId, $desde7)),
    'agenda'        => array("SELECT DATE(created_at) d, COUNT(*) c FROM audit_log WHERE entity_type='agenda' AND user_id=? AND created_at>=? AND (action='AGENDA_BALCAO_REALIZADO' OR (action='AGENDA_STATUS' AND details LIKE 'Status: realizado%')) GROUP BY DATE(created_at)", array($viewUserId, $desde7)),
    'helpdesk'      => array("SELECT DATE(a.created_at) d, COUNT(DISTINCT a.entity_id) c FROM audit_log a JOIN tickets t ON t.id=a.entity_id WHERE a.action='ticket_updated' AND a.entity_type='ticket' AND a.user_id=? AND a.created_at>=? AND t.status='resolvido' GROUP BY DATE(a.created_at)", array($viewUserId, $desde7)),
    'distribuicoes' => array("SELECT DATE(created_at) d, COUNT(*) c FROM audit_log WHERE action='processo_distribuido' AND entity_type='case' AND user_id=? AND created_at>=? GROUP BY DATE(created_at)", array($viewUserId, $desde7)),
);
foreach ($histQ as $cat => $h) {
    try { $q = $pdo->prepare($h[0]); $q->execute($h[1]); foreach ($q->fetchAll() as $row) {
        $d = substr($row['d'], 0, 10);
        if (isset($dias7[$d])) { $dias7[$d] += (int)$row['c']; $dias7det[$d][$cat] += (int)$row['c']; }
    } } catch (Exception $e) {}
}
$dopaMax = max(1, max($dias7));
// Recorde HISTÓRICO (all-time) — fallback pro recorde da semana se a query falhar
$dopaRecorde = max($dias7);
try {
    $q = $pdo->prepare("SELECT SUM(c) tot FROM (
        SELECT DATE(completed_at) d, COUNT(*) c FROM case_tasks WHERE status='concluido' AND assigned_to=? GROUP BY DATE(completed_at)
        UNION ALL SELECT DATE(concluido_em) d, COUNT(*) c FROM prazos_processuais WHERE concluido=1 AND usuario_id=? GROUP BY DATE(concluido_em)
        UNION ALL SELECT DATE(created_at) d, COUNT(*) c FROM audit_log WHERE entity_type='agenda' AND user_id=? AND (action='AGENDA_BALCAO_REALIZADO' OR (action='AGENDA_STATUS' AND details LIKE 'Status: realizado%')) GROUP BY DATE(created_at)
        UNION ALL SELECT DATE(a.created_at) d, COUNT(DISTINCT a.entity_id) c FROM audit_log a JOIN tickets t ON t.id=a.entity_id WHERE a.action='ticket_updated' AND a.entity_type='ticket' AND a.user_id=? AND t.status='resolvido' GROUP BY DATE(a.created_at)
        UNION ALL SELECT DATE(created_at) d, COUNT(*) c FROM audit_log WHERE action='processo_distribuido' AND entity_type='case' AND user_id=? GROUP BY DATE(created_at)
    ) u GROUP BY d ORDER BY tot DESC LIMIT 1");
    $q->execute(array($viewUserId, $viewUserId, $viewUserId, $viewUserId, $viewUserId));
    $rt = (int)$q->fetchColumn();
    if ($rt > $dopaRecorde) $dopaRecorde = $rt;
} catch (Exception $e) {}
$recordeNovo = ($dopaTotal > 0 && $dopaTotal >= $dopaRecorde); // hoje é (ou empata) o recorde
$pdConfete = (($viewUserId === $userId) && $diaTot > 0 && $diaPct >= 100) ? 1 : 0; // confete ao fechar a agenda do dia

// Streak: dias seguidos com >=1 baixa, terminando no último dia produtivo (ignora zeros do fim, ex: hoje cedo)
$streak = 0; $vals7 = array_values($dias7); $i7 = count($vals7) - 1;
while ($i7 >= 0 && $vals7[$i7] === 0) $i7--;
while ($i7 >= 0 && $vals7[$i7] > 0) { $streak++; $i7--; }

// Nome do alvo (quando gestão olha agenda de outro)
$dopaSelf = ($viewUserId === $userId);
$dopaNome = $userName;
if (!$dopaSelf) { foreach ($users as $u) { if ((int)$u['id'] === $viewUserId) { $dopaNome = explode(' ', $u['name'])[0]; break; } } }

if ($dopaSelf) {
    $dopaTitulo = $dopaTotal > 0
        ? ('🎉 Você já cumpriu ' . $dopaTotal . ($dopaTotal == 1 ? ' coisa' : ' coisas') . ' hoje!')
        : '☕ Bora começar o dia!';
    if ($dopaTotal === 0)      $dopaFrase = 'A primeira baixa do dia é a mais gostosa. Você consegue! 💪';
    elseif ($dopaTotal <= 2)   $dopaFrase = 'Já saiu do zero — continua nesse ritmo! ✨';
    elseif ($dopaTotal <= 5)   $dopaFrase = 'Tá voando hoje! 🚀';
    elseif ($dopaTotal <= 9)   $dopaFrase = 'Que produtividade — tá pegando fogo! 🔥';
    else                        $dopaFrase = 'Você é uma máquina hoje! Orgulho. 🏆';
} else {
    $dopaTitulo = $dopaTotal > 0
        ? ('👏 ' . e($dopaNome) . ' já cumpriu ' . $dopaTotal . ($dopaTotal == 1 ? ' coisa' : ' coisas') . ' hoje')
        : ('🕊️ ' . e($dopaNome) . ' ainda não deu baixas hoje');
    $dopaFrase = $dopaTotal > 0 ? 'Produzindo bem.' : 'O dia ainda tá começando.';
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
.pd-postit-acoes button{background:rgba(255,255,255,.6);border:none;cursor:pointer;font-size:.75rem;padding:2px 6px;border-radius:4px;transition:background .15s;position:relative;}
.pd-postit-acoes button:hover{background:rgba(255,255,255,.95);}
/* Tooltip CSS instantaneo (title HTML demora 1s) -- relatorio Nilce 31/05 */
.pd-postit-acoes button[title]:hover::after{content:attr(title);position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);background:#052228;color:#fff;padding:4px 8px;border-radius:4px;font-size:.65rem;font-weight:600;white-space:nowrap;z-index:100;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:none;font-family:system-ui;}
.pd-postit-acoes button[title]:hover::before{content:'';position:absolute;bottom:100%;left:50%;transform:translateX(-50%);border:4px solid transparent;border-top-color:#052228;z-index:100;pointer-events:none;}
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
/* === 🎉 Dopamina: baixas do dia === */
.pd-dopa{border-radius:var(--radius-lg);padding:1.1rem 1.4rem;margin-bottom:1.25rem;color:#fff;position:relative;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,.08);}
.pd-dopa.tem{background:linear-gradient(120deg,#059669 0%,#0d9488 45%,#B87333 115%);}
.pd-dopa.zero{background:linear-gradient(120deg,#334155,#475569);}
.pd-dopa-top{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;position:relative;z-index:1;}
.pd-dopa-head{display:flex;align-items:center;gap:1rem;}
.pd-dopa-total{font-size:3rem;font-weight:900;line-height:1;min-width:66px;text-align:center;text-shadow:0 2px 8px rgba(0,0,0,.25);}
.pd-dopa-titulo{font-size:1.05rem;font-weight:800;}
.pd-dopa-frase{font-size:.82rem;opacity:.92;margin-top:.15rem;}
.pd-dopa-toggle{background:rgba(255,255,255,.18);border:none;color:#fff;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:.75rem;flex-shrink:0;transition:transform .2s;line-height:1;}
.pd-dopa-toggle:hover{background:rgba(255,255,255,.3);}
.pd-dopa.collapsed .pd-dopa-toggle{transform:rotate(-90deg);}
.pd-dopa-body{margin-top:.9rem;position:relative;z-index:1;overflow:hidden;}
.pd-dopa.collapsed .pd-dopa-body{display:none;}
.pd-dopa-prog{margin-bottom:.9rem;}
.pd-dopa-prog-lbl{display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:.3rem;font-weight:600;}
.pd-dopa-bar{height:12px;background:rgba(255,255,255,.22);border-radius:99px;overflow:hidden;}
.pd-dopa-bar-fill{height:100%;background:linear-gradient(90deg,#fde68a,#fff);border-radius:99px;transition:width .9s cubic-bezier(.2,.8,.2,1);box-shadow:0 0 10px rgba(255,255,255,.45);}
.pd-dopa-prog-done{font-size:.78rem;font-weight:700;margin-top:.35rem;}
.pd-dopa-stats{display:flex;gap:.6rem;flex-wrap:wrap;}
.pd-dopa-stat{background:rgba(255,255,255,.16);border-radius:10px;padding:.5rem .85rem;display:flex;align-items:center;gap:.45rem;}
.pd-dopa-stat .n{font-size:1.25rem;font-weight:800;}
.pd-dopa-stat .l{font-size:.72rem;font-weight:600;opacity:.92;}
.pd-dopa-stat.z{opacity:.5;}
.pd-dopa-chart{margin-top:1rem;background:rgba(255,255,255,.10);border-radius:12px;padding:.7rem .85rem .55rem;}
.pd-dopa-chart-head{display:flex;justify-content:space-between;align-items:center;font-size:.72rem;font-weight:700;opacity:.95;margin-bottom:.6rem;flex-wrap:wrap;gap:.4rem;}
.pd-dopa-badges{display:flex;gap:.4rem;flex-wrap:wrap;}
.pd-badge{background:rgba(0,0,0,.2);padding:2px 9px;border-radius:99px;font-size:.68rem;font-weight:700;}
.pd-badge.novo{background:linear-gradient(90deg,#f59e0b,#fde68a);color:#3b2700;animation:pdGlow 1.2s ease-in-out infinite;}
@keyframes pdGlow{0%,100%{box-shadow:0 0 0 0 rgba(253,230,138,.6)}50%{box-shadow:0 0 0 6px rgba(253,230,138,0)}}
.pd-dopa-bars{display:flex;align-items:flex-end;gap:.5rem;height:78px;}
.pd-dopa-colwrap{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:4px;cursor:pointer;border-radius:6px;transition:background .15s;}
.pd-dopa-colwrap:hover{background:rgba(255,255,255,.12);}
.pd-pop{position:absolute;z-index:9998;background:#0b2d34;color:#fff;border:1px solid rgba(255,255,255,.18);border-radius:10px;padding:.6rem .75rem;min-width:185px;box-shadow:0 12px 32px rgba(0,0,0,.4);font-size:.78rem;animation:pdPopIn .14s ease-out;}
@keyframes pdPopIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
.pd-pop-head{font-weight:700;margin-bottom:.4rem;border-bottom:1px solid rgba(255,255,255,.18);padding-bottom:.32rem;}
.pd-pop-row{display:flex;justify-content:space-between;gap:1.2rem;padding:2px 0;}
.pd-pop-row b{font-weight:800;}
.pd-pop-row.zero{opacity:.45;}
.pd-pop-empty{opacity:.7;font-style:italic;margin-top:.3rem;}
.pd-dopa-col{width:72%;max-width:28px;background:rgba(255,255,255,.4);border-radius:5px 5px 0 0;position:relative;min-height:4px;transition:height .9s cubic-bezier(.2,.8,.2,1);}
.pd-dopa-col.hoje{background:#fff;box-shadow:0 0 12px rgba(255,255,255,.55);}
.pd-dopa-col.rec{background:#fde68a;}
.pd-dopa-coln{position:absolute;top:-15px;left:50%;transform:translateX(-50%);font-size:.62rem;font-weight:800;}
.pd-dopa-coll{font-size:.6rem;opacity:.85;font-weight:600;white-space:nowrap;}
@keyframes pdPop{0%{transform:scale(.6);opacity:0}60%{transform:scale(1.12)}100%{transform:scale(1);opacity:1}}
.pd-dopa.tem .pd-dopa-total{animation:pdPop .5s ease-out both;}
.pd-dopa.tem::after{content:'';position:absolute;top:-60%;right:-8%;width:260px;height:260px;background:radial-gradient(circle,rgba(255,255,255,.20),transparent 70%);pointer-events:none;}
@media print{.pd-dopa{display:none;}}
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

<!-- 🎉 Dopamina: o que já foi cumprido hoje -->
<div class="pd-dopa <?= $dopaTotal > 0 ? 'tem' : 'zero' ?>" id="pdDopa">
    <div class="pd-dopa-top">
        <div class="pd-dopa-head">
            <div class="pd-dopa-total" id="pdDopaTotal" data-n="<?= (int)$dopaTotal ?>"><?= (int)$dopaTotal ?></div>
            <div>
                <div class="pd-dopa-titulo"><?= $dopaTitulo ?></div>
                <div class="pd-dopa-frase"><?= $dopaFrase ?></div>
            </div>
        </div>
        <button type="button" class="pd-dopa-toggle" onclick="pdDopaToggle()" title="Recolher / expandir">▼</button>
    </div>

    <div class="pd-dopa-body">
        <?php if ($diaTot > 0): ?>
        <div class="pd-dopa-prog">
            <div class="pd-dopa-prog-lbl">
                <span>📋 Agenda de hoje</span>
                <span><strong><?= (int)$diaFeito ?></strong> de <?= (int)$diaTot ?> · <?= $diaPct ?>%</span>
            </div>
            <div class="pd-dopa-bar"><div class="pd-dopa-bar-fill" data-w="<?= $diaPct ?>" style="width:0;"></div></div>
            <?php if ($diaPct >= 100): ?><div class="pd-dopa-prog-done">🎉 Tudo do dia concluído — você arrasou!</div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="pd-dopa-stats">
            <div class="pd-dopa-stat <?= $dopa['tarefas'] ? '' : 'z' ?>"><span class="n"><?= (int)$dopa['tarefas'] ?></span><span class="l">✅ Tarefas</span></div>
            <div class="pd-dopa-stat <?= $dopa['prazos'] ? '' : 'z' ?>"><span class="n"><?= (int)$dopa['prazos'] ?></span><span class="l">⚖️ Prazos</span></div>
            <div class="pd-dopa-stat <?= $dopa['agenda'] ? '' : 'z' ?>"><span class="n"><?= (int)$dopa['agenda'] ?></span><span class="l">📅 Compromissos</span></div>
            <div class="pd-dopa-stat <?= $dopa['distribuicoes'] ? '' : 'z' ?>"><span class="n"><?= (int)$dopa['distribuicoes'] ?></span><span class="l">🏛️ Distribuições</span></div>
            <div class="pd-dopa-stat <?= $dopa['helpdesk'] ? '' : 'z' ?>"><span class="n"><?= (int)$dopa['helpdesk'] ?></span><span class="l">🎫 Chamados</span></div>
        </div>

        <div class="pd-dopa-chart">
            <div class="pd-dopa-chart-head">
                <span>📊 Últimos 7 dias</span>
                <span class="pd-dopa-badges">
                    <?php if ($streak >= 2): ?><span class="pd-badge fire">🔥 <?= $streak ?> dias seguidos</span><?php endif; ?>
                    <?php if ($recordeNovo && ($viewUserId === $userId)): ?>
                        <span class="pd-badge novo">🏆 NOVO RECORDE: <?= (int)$dopaTotal ?>!</span>
                    <?php elseif ($dopaRecorde > 0): ?>
                        <span class="pd-badge rec">🏆 recorde <?= (int)$dopaRecorde ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="pd-dopa-bars">
                <?php foreach ($dias7 as $d => $c):
                    $h = round($c / $dopaMax * 100);
                    $isHoje = ($d === $hoje);
                    $isRec = ($c > 0 && $c === $dopaRecorde);
                    $wd = mb_substr($diasSemana[(int)date('w', strtotime($d))], 0, 3);
                ?>
                <div class="pd-dopa-colwrap" onclick="pdDopaDia('<?= $d ?>', this)" title="<?= date('d/m', strtotime($d)) ?>: <?= $c ?> baixa<?= $c == 1 ? '' : 's' ?> — clique pra detalhar">
                    <div class="pd-dopa-col <?= $isHoje ? 'hoje' : '' ?> <?= $isRec ? 'rec' : '' ?>" data-h="<?= max(4, $h) ?>" style="height:0;">
                        <?php if ($c > 0): ?><span class="pd-dopa-coln"><?= $c ?></span><?php endif; ?>
                    </div>
                    <div class="pd-dopa-coll"><?= $isHoje ? 'Hoje' : $wd ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    var el = document.getElementById('pdDopaTotal');
    if (el) {
        var n = parseInt(el.dataset.n || '0', 10);
        if (n > 0) { var c = 0, step = Math.max(1, Math.round(n / 18)); el.textContent = '0';
            var t = setInterval(function(){ c += step; if (c >= n) { c = n; clearInterval(t); } el.textContent = c; }, 45); }
    }
    // Animar barras crescendo (após o paint)
    setTimeout(function(){
        var f = document.querySelector('.pd-dopa-bar-fill'); if (f && f.dataset.w != null) f.style.width = f.dataset.w + '%';
        document.querySelectorAll('.pd-dopa-col').forEach(function(co){ if (co.dataset.h != null) co.style.height = co.dataset.h + '%'; });
    }, 70);
    // Restaurar estado recolhido
    try { if (localStorage.getItem('pdDopaCollapsed') === '1') { var d = document.getElementById('pdDopa'); if (d) d.classList.add('collapsed'); } } catch (e) {}
    // Confete ao fechar 100% da agenda do dia (1x por dia)
    <?php if (!empty($pdConfete)): ?>
    try {
        var ck = 'pdConfete_<?= $hoje ?>';
        if (localStorage.getItem(ck) !== '1') { setTimeout(pdConfete, 450); localStorage.setItem(ck, '1'); }
    } catch (e) { setTimeout(pdConfete, 450); }
    <?php endif; ?>
})();
function pdDopaToggle(){
    var d = document.getElementById('pdDopa'); if (!d) return;
    d.classList.toggle('collapsed');
    try { localStorage.setItem('pdDopaCollapsed', d.classList.contains('collapsed') ? '1' : '0'); } catch (e) {}
}
function pdConfete(){
    var cores = ['#fde68a','#34d399','#60a5fa','#f472b6','#fb923c','#a78bfa','#ffffff'];
    var c = document.createElement('div');
    c.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:9999;overflow:hidden;';
    document.body.appendChild(c);
    for (var i = 0; i < 90; i++) {
        var p = document.createElement('div');
        var size = 6 + Math.random() * 9;
        p.style.cssText = 'position:absolute;top:-12px;left:' + (Math.random() * 100) + '%;width:' + size + 'px;height:' + (size * 0.6) + 'px;background:' + cores[i % cores.length] + ';border-radius:2px;';
        var dx = (Math.random() * 240 - 120);
        if (p.animate) {
            p.animate([
                { transform: 'translate(0,0) rotate(0deg)', opacity: 1 },
                { transform: 'translate(' + dx + 'px,' + (window.innerHeight + 60) + 'px) rotate(' + (360 + Math.random() * 720) + 'deg)', opacity: 1 }
            ], { duration: 2200 + Math.random() * 1600, delay: Math.random() * 500, easing: 'cubic-bezier(.3,.6,.5,1)', fill: 'forwards' });
        }
        c.appendChild(p);
    }
    setTimeout(function(){ c.remove(); }, 4300);
}

// Detalhe do dia ao clicar numa barra
var PD_DIAS = <?= json_encode($dias7det, JSON_UNESCAPED_UNICODE) ?>;
var PD_LBL = { tarefas:'✅ Tarefas', prazos:'⚖️ Prazos', agenda:'📅 Compromissos', distribuicoes:'🏛️ Distribuições', helpdesk:'🎫 Chamados' };
function pdDopaDia(date, el){
    var ex = document.getElementById('pdDopaPop');
    if (ex){ var was = ex.dataset.date; ex.remove(); document.removeEventListener('click', pdPopClose); if (was === date) return; }
    var det = PD_DIAS[date] || {};
    var total = 0, rows = '';
    for (var k in PD_LBL){ var v = det[k] || 0; total += v; rows += '<div class="pd-pop-row '+(v?'':'zero')+'"><span>'+PD_LBL[k]+'</span><b>'+v+'</b></div>'; }
    var p = date.split('-');
    var pop = document.createElement('div');
    pop.id = 'pdDopaPop'; pop.dataset.date = date; pop.className = 'pd-pop';
    pop.innerHTML = '<div class="pd-pop-head">📅 '+p[2]+'/'+p[1]+' · '+total+' no total</div>' + rows + (total === 0 ? '<div class="pd-pop-empty">Nenhuma baixa nesse dia.</div>' : '');
    document.body.appendChild(pop);
    var r = el.getBoundingClientRect();
    var top = r.top + window.scrollY - pop.offsetHeight - 8;
    if (top < window.scrollY + 4) top = r.bottom + window.scrollY + 8;
    var left = r.left + window.scrollX + r.width / 2 - pop.offsetWidth / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - pop.offsetWidth - 8));
    pop.style.top = top + 'px'; pop.style.left = left + 'px';
    setTimeout(function(){ document.addEventListener('click', pdPopClose); }, 10);
}
function pdPopClose(e){
    var pop = document.getElementById('pdDopaPop');
    if (pop && !pop.contains(e.target) && !(e.target.closest && e.target.closest('.pd-dopa-colwrap'))){
        pop.remove(); document.removeEventListener('click', pdPopClose);
    }
}
</script>

<?php
// ══════════════════════════════════════════════════════════════════════
// 🌅 Briefing matinal por IA — visível apenas pra usuários autorizados.
// Mostra cached se já existe pra hoje; senão exibe botão pra gerar.
// ══════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../core/functions_ia.php';
$_briefMostrar = ia_user_autorizado(current_user_id()) && ia_feature_ativa('briefing');
$_briefHoje = null;
if ($_briefMostrar) {
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS ia_briefings (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, data DATE NOT NULL, conteudo MEDIUMTEXT NOT NULL, custo_brl DECIMAL(10,4) DEFAULT 0, gerado_em DATETIME NOT NULL, UNIQUE KEY uk_user_data (user_id, data), INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
    try {
        $stB = $pdo->prepare("SELECT conteudo, gerado_em FROM ia_briefings WHERE user_id = ? AND data = CURDATE() LIMIT 1");
        $stB->execute(array((int)current_user_id()));
        $_briefHoje = $stB->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
// Helper: parseia o briefing em itens clicaveis (Amanda 01/06/2026)
// Itens (linhas com '- ' ou '* ') viram <li> clicaveis que toggle .done
// no localStorage. Linhas comuns viram <div> normais. Hash MD5 truncado
// identifica cada item pra persistir estado entre reloads (ate IA atualizar).
if (!function_exists('_briefing_render_html')) {
// Resolve [ID:tipo_NN] (Amanda 02/06/2026): retorna array com href (caso/tarefa/agenda),
// id_real e tipo amigavel. Tipos: tka_=tarefa, pz_=prazo, ev_=evento agenda, int_=intimacao.
function _briefing_resolve_id($tipo, $id, $pdo) {
    $id = (int)$id;
    if (!$id) return null;
    try {
        if ($tipo === 'tka') {
            $st = $pdo->prepare("SELECT case_id FROM case_tasks WHERE id = ?");
            $st->execute(array($id));
            $cid = (int)$st->fetchColumn();
            return $cid > 0 ? array('href' => url('modules/operacional/caso_ver.php?id=' . $cid . '#tarefas'), 'label' => 'Ver tarefa', 'tipo_baixa' => 'tarefa') : null;
        } elseif ($tipo === 'pz') {
            $st = $pdo->prepare("SELECT case_id FROM prazos_processuais WHERE id = ?");
            $st->execute(array($id));
            $cid = (int)$st->fetchColumn();
            return $cid > 0 ? array('href' => url('modules/operacional/caso_ver.php?id=' . $cid . '#prazos'), 'label' => 'Ver prazo', 'tipo_baixa' => 'prazo') : null;
        } elseif ($tipo === 'ev') {
            $st = $pdo->prepare("SELECT case_id, client_id FROM agenda_eventos WHERE id = ?");
            $st->execute(array($id));
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            if ((int)$r['case_id'] > 0) return array('href' => url('modules/operacional/caso_ver.php?id=' . (int)$r['case_id']), 'label' => 'Ver caso', 'tipo_baixa' => 'evento');
            if ((int)$r['client_id'] > 0) return array('href' => url('modules/clientes/ver.php?id=' . (int)$r['client_id']), 'label' => 'Ver cliente', 'tipo_baixa' => 'evento');
            return array('href' => url('modules/agenda/'), 'label' => 'Ver agenda', 'tipo_baixa' => 'evento');
        } elseif ($tipo === 'int') {
            $st = $pdo->prepare("SELECT case_id FROM case_publicacoes WHERE id = ?");
            $st->execute(array($id));
            $cid = (int)$st->fetchColumn();
            return $cid > 0 ? array('href' => url('modules/operacional/caso_ver.php?id=' . $cid . '#publicacoes'), 'label' => 'Ver intimacao', 'tipo_baixa' => null) : null;
        }
    } catch (Exception $e) {}
    return null;
}
function _briefing_render_html($texto) {
    $pdo = db();
    $texto = htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
    $texto = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $texto);
    $linhas = preg_split('/\r?\n/', $texto);
    $html = '';
    $emLista = false;
    foreach ($linhas as $linha) {
        $linhaTrim = trim($linha);
        if (preg_match('/^[-*]\s+(.+)$/u', $linhaTrim, $m)) {
            if (!$emLista) { $html .= '<ul class="brief-list">'; $emLista = true; }
            $textoItem = trim($m[1]);
            // Extrai [ID:tipo_NN] do INICIO do item (a IA foi instruida a colocar ali)
            $idTipo = null; $idReal = null; $resolve = null;
            if (preg_match('/^\[ID:(tka|pz|ev|int|ua)_(\d+)\]\s*/', $textoItem, $mid)) {
                $idTipo = $mid[1]; $idReal = (int)$mid[2];
                $textoItem = preg_replace('/^\[ID:(tka|pz|ev|int|ua)_(\d+)\]\s*/', '', $textoItem);
                $resolve = _briefing_resolve_id($idTipo, $idReal, $pdo);
            }
            $key = substr(md5($textoItem), 0, 12);
            $btns = '';
            if ($resolve) {
                $btns .= '<a href="' . $resolve['href'] . '" class="brief-item-btn brief-item-ver" onclick="event.stopPropagation();" title="' . htmlspecialchars($resolve['label']) . '">🔍</a>';
                if ($resolve['tipo_baixa']) {
                    $btns .= '<span class="brief-item-btn brief-item-baixar" onclick="event.stopPropagation();briefBaixarItem(this,\'' . $resolve['tipo_baixa'] . '\',' . $idReal . ')" title="Dar baixa (marcar como cumprido)">✓</span>';
                }
            }
            $html .= '<li class="brief-item" data-key="' . $key . '" onclick="brfToggle(this)" title="Clique pra riscar (já feito) ou desmarcar">'
                  . '<span class="brief-item-texto">' . $textoItem . '</span>'
                  . ($btns ? '<span class="brief-item-acoes">' . $btns . '</span>' : '')
                  . '</li>';
        } else {
            if ($emLista) { $html .= '</ul>'; $emLista = false; }
            if ($linhaTrim === '') $html .= '<div class="brief-blank"></div>';
            else $html .= '<div class="brief-text">' . $linha . '</div>';
        }
    }
    if ($emLista) $html .= '</ul>';
    return $html;
}
}
if ($_briefMostrar):
?>
<style>
#briefingConteudo .brief-text { margin:.15rem 0; }
#briefingConteudo .brief-blank { height:.45rem; }
#briefingConteudo .brief-list { list-style:none; padding-left:0; margin:.35rem 0; }
#briefingConteudo .brief-item {
    cursor:pointer; padding:.4rem .55rem; margin:.2rem 0; border-radius:6px;
    transition:background .15s, opacity .15s, text-decoration-color .15s;
    border-left:3px solid transparent;
}
#briefingConteudo .brief-item:hover { background:rgba(217,119,6,.08); border-left-color:#d97706; }
#briefingConteudo .brief-item.done {
    text-decoration:line-through; text-decoration-color:#92400e; text-decoration-thickness:2px;
    opacity:.55; background:rgba(146,64,14,.05);
}
#briefingConteudo .brief-item.done:hover { opacity:.75; }
#briefingConteudo .brief-item::before {
    content:'☐ '; color:#92400e; font-weight:700; margin-right:.15rem;
}
#briefingConteudo .brief-item.done::before { content:'✅ '; }
#briefingConteudo .brief-item { display:flex; align-items:flex-start; gap:.5rem; }
#briefingConteudo .brief-item-texto { flex:1; }
#briefingConteudo .brief-item-acoes { display:flex; gap:.3rem; flex-shrink:0; align-items:center; }
#briefingConteudo .brief-item-btn {
    display:inline-flex; align-items:center; justify-content:center;
    width:24px; height:24px; border-radius:50%; font-size:.72rem; font-weight:700;
    cursor:pointer; text-decoration:none; transition:transform .12s, background .12s;
    user-select:none;
}
#briefingConteudo .brief-item-ver { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }
#briefingConteudo .brief-item-ver:hover { background:#bfdbfe; transform:scale(1.12); }
#briefingConteudo .brief-item-baixar { background:#d1fae5; color:#065f46; border:1px solid #34d399; }
#briefingConteudo .brief-item-baixar:hover { background:#a7f3d0; transform:scale(1.12); }
</style>
<div id="briefingCard" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-left:4px solid #d97706;border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1rem;color:#1f2937;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.55rem;gap:.5rem;flex-wrap:wrap;">
        <strong style="font-size:.95rem;color:#92400e;">🌅 Briefing matinal por IA</strong>
        <div style="display:flex;gap:.4rem;align-items:center;font-size:.7rem;">
            <?php if ($_briefHoje): ?>
                <span style="color:#6b7280;">gerado em <?= date('H:i', strtotime($_briefHoje['gerado_em'])) ?></span>
                <button type="button" onclick="gerarBriefing(true)" id="btnRegenBrief" style="background:#fff;border:1px solid #d97706;color:#92400e;padding:.18rem .55rem;border-radius:5px;cursor:pointer;font-weight:700;font-size:.7rem;">🔄 Atualizar</button>
            <?php else: ?>
                <button type="button" onclick="gerarBriefing(false)" id="btnGerarBrief" style="background:#d97706;border:none;color:#fff;padding:.3rem .8rem;border-radius:6px;cursor:pointer;font-weight:700;font-size:.78rem;">✨ Gerar agora</button>
            <?php endif; ?>
        </div>
    </div>
    <div id="briefingConteudo" style="font-size:.88rem;line-height:1.55;">
        <?php if ($_briefHoje): ?>
            <?= _briefing_render_html($_briefHoje['conteudo']) ?>
        <?php else: ?>
            <span style="color:#92400e;">Toque em <strong>✨ Gerar agora</strong> pra ver as 5 coisas mais importantes do seu dia. <span style="font-size:.7rem;opacity:.7;">(custa cerca de R$ 0,05 · sem IA esse painel mostra dados crus, sem priorização)</span></span>
        <?php endif; ?>
    </div>
</div>
<script>
window.gerarBriefing = function(forcar) {
    var btnG = document.getElementById('btnGerarBrief');
    var btnR = document.getElementById('btnRegenBrief');
    var alvo = document.getElementById('briefingConteudo');
    if (btnG) { btnG.disabled = true; btnG.textContent = '⏳ Gerando…'; }
    if (btnR) { btnR.disabled = true; btnR.textContent = '⏳…'; }
    if (alvo) alvo.style.opacity = '.5';

    var fd = new FormData();
    fd.append('action', 'gerar_briefing_ia');
    fd.append('csrf_token', (window._FSA_CSRF || '<?= e(generate_csrf_token()) ?>'));
    if (forcar) fd.append('forcar', '1');

    fetch('<?= module_url('painel', 'api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) {
                if (btnG) { btnG.disabled = false; btnG.textContent = '✨ Gerar agora'; }
                if (btnR) { btnR.disabled = false; btnR.textContent = '🔄 Atualizar'; }
                if (alvo) alvo.style.opacity = '1';
                alert(d.error); return;
            }
            if (alvo) {
                var html = String(d.conteudo || '')
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\n/g, '<br>');
                alvo.innerHTML = html;
                alvo.style.opacity = '1';
            }
            // Recarrega so pra atualizar o "gerado em XX:XX" e trocar botao de "Gerar" pra "Atualizar"
            // Limpa risca antigos: briefing novo = todos os itens voltam pra "a fazer"
            try { localStorage.removeItem(brfStorageKey()); } catch(e){}
            setTimeout(function(){ location.reload(); }, 600);
        })
        .catch(function(e){
            if (btnG) { btnG.disabled = false; btnG.textContent = '✨ Gerar agora'; }
            if (btnR) { btnR.disabled = false; btnR.textContent = '🔄 Atualizar'; }
            if (alvo) alvo.style.opacity = '1';
            alert('Erro: ' + e.message);
        });
};

// Checklist do briefing: clique = riscar, clique de novo = desmarcar (Amanda 01/06/2026)
// Persiste em localStorage por usuario+dia. Quando IA gera briefing novo, estado zera.
function brfStorageKey() {
    return 'fsa-brief-done-<?= (int)current_user_id() ?>-<?= date('Ymd') ?>';
}
function brfGetDone() {
    try { var v = localStorage.getItem(brfStorageKey()); return v ? JSON.parse(v) : []; }
    catch(e) { return []; }
}
function brfSaveDone(arr) {
    try { localStorage.setItem(brfStorageKey(), JSON.stringify(arr)); } catch(e) {}
}
// Baixa item direto pelo card do briefing (Amanda 02/06/2026) - reusa baixar_atrasada
window.briefBaixarItem = function(btnEl, tipo, id) {
    var nomes = { tarefa: 'tarefa', evento: 'evento', prazo: 'prazo' };
    if (!confirm('Dar baixa nesta ' + nomes[tipo] + '? Marca como cumprida.')) return;
    var item = btnEl.closest('.brief-item');
    btnEl.textContent = '⏳'; btnEl.style.pointerEvents = 'none';
    var fd = new FormData();
    fd.append('action', 'baixar_atrasada');
    fd.append('tipo', tipo);
    fd.append('id', id);
    fd.append('csrf_token', (window._FSA_CSRF || '<?= e(generate_csrf_token()) ?>'));
    fetch('<?= module_url('painel', 'api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) { btnEl.textContent = '✓'; btnEl.style.pointerEvents = ''; alert('Não foi possível: ' + d.error); return; }
            if (item) {
                item.classList.add('done');
                btnEl.textContent = '✅'; btnEl.style.pointerEvents = '';
                // Persiste o "feito" tambem no toggle visual local
                var done = brfGetDone();
                if (item.dataset.key && done.indexOf(item.dataset.key) < 0) {
                    done.push(item.dataset.key);
                    brfSaveDone(done);
                }
            }
        })
        .catch(function(e){ btnEl.textContent = '✓'; btnEl.style.pointerEvents = ''; alert('Erro: ' + e.message); });
};

window.brfToggle = function(el) {
    if (!el || !el.dataset || !el.dataset.key) return;
    var k = el.dataset.key;
    var done = brfGetDone();
    var idx = done.indexOf(k);
    if (idx >= 0) { done.splice(idx, 1); el.classList.remove('done'); }
    else { done.push(k); el.classList.add('done'); }
    brfSaveDone(done);
};
function brfRestore() {
    var done = brfGetDone();
    if (!done.length) return;
    document.querySelectorAll('#briefingConteudo .brief-item').forEach(function(el){
        if (done.indexOf(el.dataset.key) >= 0) el.classList.add('done');
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', brfRestore);
else brfRestore();
</script>
<?php endif; ?>

<div class="pd-grid">
    <!-- COLUNA 1: Agenda -->
    <div class="pd-card">
        <h3>📅 Agenda de Hoje (<?= count($agendaHoje) ?>)</h3>
        <?php
        // Banner com total real de atrasadas (Amanda 01/06/2026)
        $_qTA = isset($_qtdTarefasAtrasadas) ? (int)$_qtdTarefasAtrasadas : 0;
        $_qEA = isset($_qtdEventosAtrasados) ? (int)$_qtdEventosAtrasados : 0;
        if ($_qTA + $_qEA > 0):
        ?>
        <div style="background:#fee2e2;border-left:3px solid #dc2626;border-radius:6px;padding:.45rem .65rem;margin-bottom:.7rem;font-size:.76rem;color:#7f1d1d;">
            <div style="font-weight:700;margin-bottom:.25rem;">⚠ Atrasadas: <?= $_qTA + $_qEA ?> no total</div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;font-size:.7rem;">
                <?php if ($_qTA > 0): ?>
                    <span>📋 <?= $_qTA ?> tarefa<?= $_qTA > 1 ? 's' : '' ?></span>
                    <a href="<?= module_url('tarefas') ?>" style="color:#991b1b;font-weight:700;text-decoration:underline;">Ver Kanban →</a>
                <?php endif; ?>
                <?php if ($_qEA > 0): ?>
                    <span>📅 <?= $_qEA ?> evento<?= $_qEA > 1 ? 's' : '' ?></span>
                    <a href="<?= module_url('agenda') ?>" style="color:#991b1b;font-weight:700;text-decoration:underline;">Ver Agenda →</a>
                <?php endif; ?>
                <?php if (($_qTA + $_qEA) > 20): ?>
                    <span style="opacity:.85;font-style:italic;">(top 20 abaixo)</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
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
                    // Decide tipo + id_real pra acao "baixar" do botao Amanda 01/06/2026
                    $_tipoBaixa = null; $_idReal = null;
                    if (strpos($ev['id'], 'tka_') === 0) { $_tipoBaixa = 'tarefa'; $_idReal = (int)substr($ev['id'], 4); }
                    elseif (strpos($ev['id'], 'tk_') === 0) { $_tipoBaixa = 'tarefa'; $_idReal = (int)substr($ev['id'], 3); }
                    elseif (strpos($ev['id'], 'eva_') === 0) { $_tipoBaixa = 'evento'; $_idReal = (int)substr($ev['id'], 4); }
                    elseif (strpos($ev['id'], 'ev_') === 0) { $_tipoBaixa = 'evento'; $_idReal = (int)substr($ev['id'], 3); }
                    elseif (strpos($ev['id'], 'pz_') === 0) { $_tipoBaixa = 'prazo'; $_idReal = (int)substr($ev['id'], 3); }
                    // Decide DESTINO do clique no card (Amanda 01/06/2026): sempre prefere
                    // o caso quando tem case_id; depois ficha do cliente; fallback por tipo.
                    $_clid = (int)($ev['client_id'] ?? 0);
                    $_href = null; $_hrefTitle = '';
                    if ($_cid > 0) {
                        $_href = url('modules/operacional/caso_ver.php?id=' . $_cid);
                        $_hrefTitle = 'Abrir processo #' . $_cid;
                    } elseif ($_clid > 0) {
                        $_href = url('modules/clientes/ver.php?id=' . $_clid);
                        $_hrefTitle = 'Abrir ficha do cliente';
                    } elseif ($_tipoBaixa === 'tarefa') {
                        $_href = url('modules/tarefas/');
                        $_hrefTitle = 'Abrir Kanban de tarefas';
                    } elseif ($_tipoBaixa === 'evento' || $_tipoBaixa === 'prazo') {
                        $_href = url('modules/agenda/');
                        $_hrefTitle = 'Abrir agenda';
                    }
                ?>
                <?php if ($_href): ?>
                <a href="<?= e($_href) ?>" class="pd-ev pd-ev-clickable <?= $ev['concluido'] ? 'concluido' : '' ?>" style="--dot-color:<?= $ev['cor'] ?>;text-decoration:none;color:inherit;display:block;padding-right:<?= $_tipoBaixa ? '34px' : '14px' ?>;" title="<?= e($_hrefTitle) ?>">
                <?php else: ?>
                <div class="pd-ev <?= $ev['concluido'] ? 'concluido' : '' ?>" style="--dot-color:<?= $ev['cor'] ?>;padding-right:<?= $_tipoBaixa ? '34px' : '14px' ?>;">
                <?php endif; ?>
                    <style>.pd-ev::before{background:var(--dot-color,#888);} .pd-ev-clickable{transition:background .15s;} .pd-ev-clickable:hover{background:rgba(215,171,144,.12);} .pd-ev-baixar{position:absolute;right:4px;top:4px;background:#d1fae5;color:#065f46;border:1px solid #34d399;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;cursor:pointer;line-height:1;user-select:none;transition:transform .12s, background .12s;} .pd-ev-baixar:hover{background:#a7f3d0;transform:scale(1.12);}</style>
                    <div class="pd-ev-hora"><?= $ev['badge'] ?> <?= $ev['hora'] ?> <?php if ($ev['processo']): ?><span style="font-family:monospace;font-size:.6rem;opacity:.7;"><?= e($ev['processo']) ?></span><?php endif; ?></div>
                    <div class="pd-ev-titulo">
                        <?= e($ev['titulo']) ?>
                        <?php if ($ev['link']): ?>
                            <a href="<?= e($ev['link']) ?>" target="_blank" onclick="event.stopPropagation();" style="font-size:.65rem;background:#052228;color:#fff;padding:1px 6px;border-radius:3px;text-decoration:none;margin-left:4px;">Abrir Meet</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($ev['detalhe']): ?><div class="pd-ev-detalhe"><?= e($ev['detalhe']) ?></div><?php endif; ?>
                    <?php if ($_tipoBaixa && empty($ev['concluido'])): ?>
                    <span class="pd-ev-baixar" onclick="event.stopPropagation();event.preventDefault();baixarAtrasada(this, '<?= $_tipoBaixa ?>', <?= $_idReal ?>)" title="Dar baixa (marcar como cumprido)">✓</span>
                    <?php endif; ?>
                <?php if ($_href): ?>
                </a>
                <?php else: ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
                <script>
                window.baixarAtrasada = function(btnEl, tipo, id) {
                    var nomes = { tarefa: 'tarefa', evento: 'evento', prazo: 'prazo' };
                    if (!confirm('Dar baixa nesta ' + nomes[tipo] + '? Marca como cumprida.')) return;
                    var card = btnEl.closest('.pd-ev');
                    btnEl.textContent = '⏳'; btnEl.style.pointerEvents = 'none';
                    var fd = new FormData();
                    fd.append('action', 'baixar_atrasada');
                    fd.append('tipo', tipo);
                    fd.append('id', id);
                    fd.append('csrf_token', (window._FSA_CSRF || '<?= e(generate_csrf_token()) ?>'));
                    fetch('<?= module_url('painel', 'api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if (d.error) {
                                btnEl.textContent = '✓'; btnEl.style.pointerEvents = '';
                                alert('Não foi possível: ' + d.error); return;
                            }
                            // Anima: risca + esmaece + colapsa
                            if (card) {
                                card.style.transition = 'opacity .4s, max-height .4s';
                                card.style.opacity = '.25';
                                card.style.textDecoration = 'line-through';
                                setTimeout(function(){ if (card.parentNode) card.parentNode.removeChild(card); }, 450);
                            }
                        })
                        .catch(function(e){
                            btnEl.textContent = '✓'; btnEl.style.pointerEvents = '';
                            alert('Erro: ' + e.message);
                        });
                };
                </script>
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
        <a href="<?= url('modules/operacional/?filtro=doc_faltante') ?>" class="pd-resumo-item" title="Itens em aberto no checklist de documentos dos casos (tabela documentos_pendentes). Não confundir com 'Doc Faltante' do Pipeline (lead travado por falta de doc) nem com 'Docs pendentes' da Central VIP (solicitações ao cliente).">
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

<?php
// ══════════════════════════════════════════════════════════════════════
// 🌡️ Clientes precisam de atenção (alimentado por cron/cliente_esfriando)
// Aparece só pra admin/gestão — equipe não precisa ver a lista global.
// ══════════════════════════════════════════════════════════════════════
$_painelMostraEsfriando = in_array(current_user_role(), array('admin','gestao'), true);
$_esfriClientes = array();
// Paginação do painel de temperatura — 6 por página, navegável via ?temp_p=N
$_tempPerPage = 6;
$_tempPagina  = max(1, (int)($_GET['temp_p'] ?? 1));
$_tempOffset  = ($_tempPagina - 1) * $_tempPerPage;
if ($_painelMostraEsfriando) {
    // Self-heal das colunas de snooze (caso o migrar_ia não tenha rodado ainda)
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_snooze_ate DATE NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_snooze_por INT NULL"); } catch (Exception $e) {}
    try {
        // Filtra clientes adiados (snooze ativo até CURDATE — só voltam após a data)
        // OFFSET/LIMIT calculados pelo PHP (são int, seguros pra interpolar)
        $stmtPE = $pdo->query(
            "SELECT c.id, c.name, c.phone, c.esfriando_score, c.esfriando_motivos, c.esfriando_em,
                    (SELECT cs.id FROM cases cs
                       WHERE cs.client_id = c.id
                         AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                         AND COALESCE(cs.kanban_oculto, 0) = 0
                         AND COALESCE(cs.acompanhamento_externo, 0) = 0
                       ORDER BY cs.updated_at DESC LIMIT 1) AS principal_case_id,
                    (SELECT cs.case_type FROM cases cs
                       WHERE cs.client_id = c.id
                         AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                         AND COALESCE(cs.kanban_oculto, 0) = 0
                         AND COALESCE(cs.acompanhamento_externo, 0) = 0
                       ORDER BY cs.updated_at DESC LIMIT 1) AS principal_case_type
             FROM clients c
             WHERE COALESCE(c.esfriando_score, 0) >= 40
               AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())
               AND EXISTS (
                   SELECT 1 FROM cases cs2
                    WHERE cs2.client_id = c.id
                      AND cs2.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                      AND COALESCE(cs2.kanban_oculto, 0) = 0
                      AND COALESCE(cs2.acompanhamento_externo, 0) = 0
               )
             ORDER BY c.esfriando_score DESC, c.esfriando_em DESC
             LIMIT $_tempPerPage OFFSET $_tempOffset"
        );
        $_esfriClientes = $stmtPE->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
// Contagem REAL (sem o LIMIT da listagem) — pros badges do header refletirem o total verdadeiro
$_esfriTotal     = 0;
$_esfriTotCrit   = 0;
$_esfriTotAtenc  = 0;
if ($_painelMostraEsfriando) {
    try {
        $baseW = "FROM clients c WHERE c.esfriando_score IS NOT NULL
                  AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())
                  AND EXISTS (SELECT 1 FROM cases cs WHERE cs.client_id = c.id
                      AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                      AND COALESCE(cs.kanban_oculto,0)=0 AND COALESCE(cs.acompanhamento_externo,0)=0)";
        $_esfriTotCrit  = (int)$pdo->query("SELECT COUNT(*) $baseW AND c.esfriando_score >= 80")->fetchColumn();
        $_esfriTotAtenc = (int)$pdo->query("SELECT COUNT(*) $baseW AND c.esfriando_score BETWEEN 40 AND 79")->fetchColumn();
        $_esfriTotal    = $_esfriTotCrit + $_esfriTotAtenc;
    } catch (Exception $e) {}
}
// Conta clientes adiados (snooze ativo) — útil pra mostrar atalho "ver adiados"
$_qtdAdiados = 0;
if ($_painelMostraEsfriando) {
    try {
        $_qtdAdiados = (int)$pdo->query(
            "SELECT COUNT(*) FROM clients c
              WHERE c.esfriando_snooze_ate IS NOT NULL AND c.esfriando_snooze_ate >= CURDATE()
                AND EXISTS (SELECT 1 FROM cases cs WHERE cs.client_id = c.id
                    AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado') AND COALESCE(cs.kanban_oculto,0)=0)"
        )->fetchColumn();
    } catch (Exception $e) {}
}
if ($_painelMostraEsfriando):
?>
<div class="pd-card" style="margin-top:1rem;border-left:4px solid #f59e0b;">
    <h3 style="justify-content:space-between;">
        <span>🌡️ PAINEL DE TEMPERATURA <span style="font-weight:400;color:#92400e;">— Clientes em risco</span></span>
        <span style="display:flex;gap:.4rem;align-items:center;font-size:.7rem;font-weight:500;">
            <?php if ($_esfriTotCrit  > 0): ?><span style="background:#fee2e2;color:#b91c1c;padding:.15rem .45rem;border-radius:8px;font-weight:700;">🔴 <?= $_esfriTotCrit ?> em risco real</span><?php endif; ?>
            <?php if ($_esfriTotAtenc > 0): ?><span style="background:#fef3c7;color:#92400e;padding:.15rem .45rem;border-radius:8px;font-weight:700;">🟡 <?= $_esfriTotAtenc ?> esfriando</span><?php endif; ?>
            <?php if ($_esfriTotal === 0): ?><span style="background:#dcfce7;color:#15803d;padding:.15rem .45rem;border-radius:8px;font-weight:700;">✅ Tudo OK</span><?php endif; ?>
            <button type="button" id="btnRecalcEsfri" onclick="recalcularEsfriando(0)" title="Atualiza os scores AGORA (sem IA, custo zero)" style="background:#fff;border:1px solid #cbd5e1;color:#1e293b;padding:.2rem .55rem;border-radius:6px;font-size:.68rem;font-weight:700;cursor:pointer;">🔄 Recalcular</button>
            <a href="<?= module_url('clientes', 'em_risco.php') ?>" style="background:#6366f1;color:#fff;text-decoration:none;padding:.2rem .55rem;border-radius:6px;font-size:.68rem;font-weight:700;">🔍 Explorar todos</a>
            <a href="<?= module_url('operacional') . '?esfriando=1' ?>" style="color:#6b7280;text-decoration:none;font-size:.68rem;">Kanban →</a>
        </span>
    </h3>
    <!-- Legenda colapsada — clique pra expandir -->
    <details style="margin-bottom:.7rem;">
        <summary style="cursor:pointer;font-size:.7rem;font-weight:700;color:#6b7280;padding:.25rem 0;list-style:none;display:inline-flex;align-items:center;gap:.3rem;">
            <span style="font-size:.85rem;">ℹ️</span> Legenda
        </summary>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;font-size:.7rem;color:#475569;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:6px;padding:.4rem .7rem;margin-top:.35rem;line-height:1.45;">
            <span><strong style="color:#b91c1c;">🔴 Risco real (≥80)</strong> — cliente prestes a sumir. Aja hoje: ligue ou mande mensagem.</span>
            <span style="opacity:.6;">|</span>
            <span><strong style="color:#92400e;">🟡 Esfriando (40–79)</strong> — 45+ dias sem contato ou sem movimento no processo. Faça um follow-up esta semana.</span>
            <span style="opacity:.6;">|</span>
            <span style="color:#64748b;">Critérios: <strong>45+ dias sem msg WhatsApp do Hub</strong> ou <strong>45+ dias sem andamento</strong>. Cobrança vencida e tarefa atrasada aparecem como (info), não decidem se entra. Recalcula automaticamente a cada msg/andamento + 1×/dia o cron.</span>
        </div>
    </details>
    <?php if (empty($_esfriClientes)): ?>
        <!-- Estado vazio: nada em risco no momento, mas oferece acesso à exploração completa -->
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:1rem 1.2rem;text-align:center;color:#15803d;">
            <div style="font-size:1.6rem;margin-bottom:.2rem;">✅</div>
            <div style="font-weight:700;font-size:.92rem;">Sem clientes em risco agora!</div>
            <div style="font-size:.78rem;color:#16a34a;margin-top:.25rem;">
                Ninguém bate o critério (45+ dias sem contato ou andamento).<br>
                <?php if ($_qtdAdiados > 0): ?>
                    <strong><?= $_qtdAdiados ?> cliente(s) está(ão) adiado(s) por snooze</strong> —
                <?php endif; ?>
                use <strong>"🔍 Explorar todos"</strong> acima pra ver lista completa filtrável (em risco, adiados, ou todos os ativos).
            </div>
        </div>
    <?php else: ?>
    <?php require_once __DIR__ . '/../../core/functions_caso_visual.php'; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:.55rem;">
        <?php foreach ($_esfriClientes as $_eClient):
            $_score = (int)$_eClient['esfriando_score'];
            // Nilce r14 31/05/2026: threshold estava em >=60 mas a legenda diz >=80,
            // o badge agregado conta >=80 como 'risco real' e 40-79 como 'esfriando'.
            // Resultado: clientes com score 60 apareciam vermelhos mas eram contados
            // como amarelos no agregado. Agora bate com legenda e card_badge do Kanban.
            $_isCrit = $_score >= 80;
            $_bg     = $_isCrit ? '#fef2f2' : '#fffbeb';
            $_border = $_isCrit ? '#fca5a5' : '#fcd34d';
            $_corNum = $_isCrit ? '#b91c1c' : '#92400e';
            $_motivos = trim((string)$_eClient['esfriando_motivos']);
            // Visual por tipo de ação (helper compartilhado em core/functions_caso_visual.php)
            list($_tEmoji, $_tLabel, $_tCor) = caso_tipo_visual($_eClient['principal_case_type'] ?? '');
            $_href = !empty($_eClient['principal_case_id'])
                ? module_url('operacional', 'caso_ver.php?id=' . (int)$_eClient['principal_case_id'])
                : module_url('clientes', 'ver.php?id=' . (int)$_eClient['id']);
        ?>
        <div data-esfri-card="<?= (int)$_eClient['id'] ?>" style="background:<?= $_bg ?>;border:1px solid <?= $_border ?>;border-left:5px solid <?= $_tCor ?>;border-radius:8px;padding:.55rem .7rem;color:#1f2937;">
            <a href="<?= e($_href) ?>" style="text-decoration:none;color:inherit;display:block;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.4rem;">
                    <div style="font-weight:700;font-size:.85rem;color:#052228;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;"><?= e($_eClient['name']) ?></div>
                    <div style="background:<?= $_corNum ?>;color:#fff;border-radius:6px;padding:.1rem .45rem;font-weight:700;font-size:.7rem;flex-shrink:0;"><?= $_isCrit ? '🔴' : '🟡' ?> <?= $_score ?></div>
                </div>
                <div style="display:inline-block;background:<?= $_tCor ?>;color:#fff;font-size:.62rem;font-weight:700;padding:.05rem .4rem;border-radius:4px;margin-top:.2rem;letter-spacing:.02em;" title="Tipo de ação"><?= $_tEmoji ?> <?= e($_tLabel) ?></div>
                <?php if ($_motivos): ?>
                    <div style="font-size:.7rem;color:#6b7280;margin-top:.25rem;line-height:1.35;"><?= e($_motivos) ?></div>
                <?php endif; ?>
            </a>
            <div style="display:flex;justify-content:flex-end;gap:.35rem;margin-top:.4rem;padding-top:.35rem;border-top:1px dashed rgba(0,0,0,.08);">
                <button type="button" onclick="adiarEsfriando(<?= (int)$_eClient['id'] ?>, 7, this)" title="Tirar este cliente do painel por 7 dias — útil quando você já abriu chamado / vai cuidar depois" style="background:#fff;border:1px solid #cbd5e1;color:#475569;padding:.18rem .55rem;border-radius:5px;font-size:.7rem;font-weight:700;cursor:pointer;">💤 Adiar 7d</button>
                <button type="button" onclick="recalcularEsfriando(<?= (int)$_eClient['id'] ?>, this)" title="Já falei/atendi este cliente — recalcular score e mostrar o que mudou" style="background:#fff;border:1px solid #94a3b8;color:#334155;padding:.18rem .55rem;border-radius:5px;font-size:.7rem;font-weight:700;cursor:pointer;">✓ Já tratei</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    // Paginação numerada — janela de 5 páginas em volta da atual
    $_tempTotPag = max(1, (int)ceil($_esfriTotal / $_tempPerPage));
    if ($_tempPagina > $_tempTotPag) $_tempPagina = $_tempTotPag;
    if ($_tempTotPag > 1):
        $_tempQsBase = $_GET; unset($_tempQsBase['temp_p']);
        $_tempPgUrl = function($p) use ($_tempQsBase) {
            return '?' . http_build_query(array_merge($_tempQsBase, array('temp_p' => $p))) . '#painel-temperatura';
        };
        $_tempPgFrom = max(1, $_tempPagina - 2);
        $_tempPgTo   = min($_tempTotPag, $_tempPgFrom + 4);
        $_tempPgFrom = max(1, $_tempPgTo - 4);
        $_tempActive = 'background:#f59e0b;color:#fff;border-color:#f59e0b;';
        $_tempIdle   = 'background:#fff;color:#92400e;border-color:#fcd34d;';
    ?>
        <div id="painel-temperatura" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-top:.7rem;padding:.55rem .7rem;background:#fef3c7;border-radius:6px;font-size:.75rem;color:#92400e;">
            <span>Página <strong><?= $_tempPagina ?></strong> de <strong><?= $_tempTotPag ?></strong> · <?= $_esfriTotal ?> total</span>
            <div style="display:flex;gap:3px;align-items:center;flex-wrap:wrap;">
                <?php if ($_tempPagina > 1): ?>
                    <a href="<?= $_tempPgUrl($_tempPagina - 1) ?>" title="Anterior" style="text-decoration:none;<?= $_tempIdle ?>border-style:solid;border-width:1px;padding:2px 8px;border-radius:5px;font-weight:700;">‹</a>
                <?php endif; ?>
                <?php if ($_tempPgFrom > 1): ?>
                    <a href="<?= $_tempPgUrl(1) ?>" style="text-decoration:none;<?= $_tempIdle ?>border-style:solid;border-width:1px;padding:2px 8px;border-radius:5px;font-weight:700;">1</a>
                    <?php if ($_tempPgFrom > 2): ?><span style="color:#a16207;">…</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $_tempPgFrom; $p <= $_tempPgTo; $p++): ?>
                    <?php if ($p === $_tempPagina): ?>
                        <span style="<?= $_tempActive ?>border-style:solid;border-width:1px;padding:2px 8px;border-radius:5px;font-weight:700;"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= $_tempPgUrl($p) ?>" style="text-decoration:none;<?= $_tempIdle ?>border-style:solid;border-width:1px;padding:2px 8px;border-radius:5px;font-weight:700;"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($_tempPgTo < $_tempTotPag): ?>
                    <?php if ($_tempPgTo < $_tempTotPag - 1): ?><span style="color:#a16207;">…</span><?php endif; ?>
                    <a href="<?= $_tempPgUrl($_tempTotPag) ?>" style="text-decoration:none;<?= $_tempIdle ?>border-style:solid;border-width:1px;padding:2px 8px;border-radius:5px;font-weight:700;"><?= $_tempTotPag ?></a>
                <?php endif; ?>
                <?php if ($_tempPagina < $_tempTotPag): ?>
                    <a href="<?= $_tempPgUrl($_tempPagina + 1) ?>" title="Próxima" style="text-decoration:none;<?= $_tempIdle ?>border-style:solid;border-width:1px;padding:2px 8px;border-radius:5px;font-weight:700;">›</a>
                <?php endif; ?>
                <a href="<?= module_url('clientes', 'em_risco.php') ?>" style="color:#6366f1;font-weight:700;text-decoration:none;margin-left:.5rem;">🔍 Ver todos →</a>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; /* fim do else: tem clientes em risco */ ?>
</div>
<script>
// Recalcular esfriando — global (botão do header) ou de 1 cliente específico (botão ✓ Tratei).
// Custo: zero (sem IA). Usado quando a Amanda já interagiu com o cliente e quer ver o score atualizado.
window.recalcularEsfriando = function(clientId, btn) {
    var headerBtn = document.getElementById('btnRecalcEsfri');
    var btnOrigText = btn ? btn.textContent : null;
    if (btn) { btn.disabled = true; btn.textContent = '⏳ verificando…'; }
    if (!clientId && headerBtn) { headerBtn.disabled = true; headerBtn.textContent = '⏳ Recalculando...'; }

    var fd = new FormData();
    fd.append('action', 'recalcular_esfriando');
    fd.append('csrf_token', (window._FSA_CSRF || '<?= e(generate_csrf_token()) ?>'));
    if (clientId) fd.append('client_id', String(clientId));

    fetch('<?= module_url('painel', 'api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) {
                if (btn) { btn.disabled = false; btn.textContent = btnOrigText; }
                alert(d.error); return;
            }
            if (clientId && d.diff) {
                _esfriMostrarDiff(d.diff);
            } else {
                location.reload();
            }
        })
        .catch(function(e){
            if (btn) { btn.disabled = false; btn.textContent = btnOrigText; }
            if (!clientId && headerBtn) { headerBtn.disabled = false; headerBtn.textContent = '🔄 Recalcular agora'; }
            alert('Erro de rede: ' + e.message);
        });
};

// Adia cliente do painel (snooze N dias). Custo zero — só UPDATE no banco.
window.adiarEsfriando = function(clientId, dias, btn) {
    if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
    var fd = new FormData();
    fd.append('action', 'adiar_esfriando');
    fd.append('client_id', String(clientId));
    fd.append('dias', String(dias || 7));
    fd.append('csrf_token', (window._FSA_CSRF || '<?= e(generate_csrf_token()) ?>'));
    fetch('<?= module_url('painel', 'api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) { if (btn) { btn.disabled = false; btn.textContent = '💤 Adiar ' + (dias||7) + 'd'; } alert(d.error); return; }
            var card = document.querySelector('[data-esfri-card="' + clientId + '"]');
            if (card) { card.style.transition = 'opacity .35s'; card.style.opacity = '0'; }
            setTimeout(function(){ location.reload(); }, 400);
        })
        .catch(function(e){
            if (btn) { btn.disabled = false; btn.textContent = '💤 Adiar ' + (dias||7) + 'd'; }
            alert('Erro: ' + e.message);
        });
};

// Mostra modal com antes/depois pra cliente recalculado individualmente.
// O ponto é a TRANSPARÊNCIA: explica EXATAMENTE o que o sistema viu, pra
// Amanda confirmar se sua ação foi captada ou se falta registrar algo.
function _esfriMostrarDiff(diff) {
    var antes  = diff.score_antes  || 0;
    var depois = diff.score_depois || 0;
    var queda  = antes - depois;
    var cor    = queda > 0 ? '#15803d' : (queda < 0 ? '#b91c1c' : '#92400e');
    var emoji  = queda > 0 ? '✅' : (queda < 0 ? '📈' : '⚠️');

    // Conclusão amigável conforme o resultado
    var conclusao = '';
    if (queda > 0) {
        conclusao = '<strong>' + emoji + ' Score caiu ' + queda + ' pontos.</strong> A ação que você fez foi captada pelo sistema.';
    } else if (queda === 0) {
        conclusao = '<strong>' + emoji + ' Score não mudou.</strong> O sistema não conseguiu ver mudança nos sinais que mede. Se você falou com o cliente por <em>outro canal</em> (telefone particular, e-mail pessoal), o detector não consegue captar — registre um andamento na pasta OU mande mensagem pelo WhatsApp do Hub.';
    } else {
        conclusao = '<strong>' + emoji + ' Score subiu ' + Math.abs(queda) + ' pontos.</strong> Algum sinal piorou desde o último cálculo.';
    }

    // Última mensagem registrada (pra dar âncora temporal)
    var ulm = '';
    if (diff.ult_msg_em) {
        var dt = new Date(diff.ult_msg_em.replace(' ', 'T'));
        var dtTxt = dt.toLocaleString('pt-BR', { day:'2-digit', month:'2-digit', year:'2-digit', hour:'2-digit', minute:'2-digit' });
        var lado = diff.ult_msg_direcao === 'enviada' ? 'você enviou' : (diff.ult_msg_direcao === 'recebida' ? 'cliente enviou' : '—');
        ulm = '<div style="font-size:.72rem;color:#6b7280;margin-top:.4rem;">💬 Última mensagem no WhatsApp do Hub: <strong>' + dtTxt + '</strong> (' + lado + ')</div>';
    } else {
        ulm = '<div style="font-size:.72rem;color:#9a3412;margin-top:.4rem;">💬 Nenhuma mensagem registrada no WhatsApp do Hub pra este cliente.</div>';
    }

    function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    var html = ''
        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;">'
        + '<h3 style="margin:0;color:#1e1b4b;">🌡️ ' + esc(diff.nome) + '</h3>'
        + '<button onclick="this.closest(\'div[data-esfri-modal]\').remove()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;">×</button>'
        + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.8rem;">'
        +   '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.6rem .7rem;">'
        +     '<div style="font-size:.65rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Antes</div>'
        +     '<div style="font-size:1.6rem;font-weight:800;color:#374151;">' + antes + '</div>'
        +     '<div style="font-size:.7rem;color:#6b7280;line-height:1.4;margin-top:.2rem;">' + esc(diff.motivos_antes || '(sem motivos)') + '</div>'
        +   '</div>'
        +   '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.6rem .7rem;">'
        +     '<div style="font-size:.65rem;color:#15803d;text-transform:uppercase;letter-spacing:.05em;">Agora</div>'
        +     '<div style="font-size:1.6rem;font-weight:800;color:' + cor + ';">' + depois + '</div>'
        +     '<div style="font-size:.7rem;color:#6b7280;line-height:1.4;margin-top:.2rem;">' + esc(diff.motivos_depois || '✓ Sem sinais de alerta') + '</div>'
        +   '</div>'
        + '</div>'
        + '<div style="background:' + (queda > 0 ? '#ecfdf5' : (queda === 0 ? '#fef3c7' : '#fef2f2')) + ';border-radius:8px;padding:.6rem .8rem;font-size:.78rem;color:#1f2937;line-height:1.5;">'
        + conclusao + ulm
        + '</div>'
        + '<div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-top:.9rem;flex-wrap:wrap;">'
        + (queda === 0
            ? '<button onclick="window._esfriAdiarDoModal(' + (diff.client_id || 0) + ', this)" style="background:#fff;border:1px solid #94a3b8;color:#334155;padding:.4rem .8rem;border-radius:6px;cursor:pointer;font-weight:600;">💤 Tirar do painel por 7 dias</button>'
            : '<span></span>')
        + '<button onclick="location.reload()" style="background:#6366f1;color:#fff;border:none;padding:.4rem 1rem;border-radius:6px;cursor:pointer;font-weight:600;">Fechar e atualizar painel</button>'
        + '</div>';

    var modal = document.createElement('div');
    modal.setAttribute('data-esfri-modal', '1');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';
    modal.innerHTML = '<div style="background:#fff;max-width:580px;width:100%;border-radius:12px;padding:1.4rem 1.6rem;box-shadow:0 10px 40px rgba(0,0,0,.3);">' + html + '</div>';
    modal.addEventListener('click', function(e){ if (e.target === modal) modal.remove(); });
    document.body.appendChild(modal);
}

// Atalho usado dentro do modal de antes/depois pra adiar sem fechar modal antes
window._esfriAdiarDoModal = function(clientId, btn) {
    if (!clientId) { alert('client_id ausente.'); return; }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ adiando…'; }
    window.adiarEsfriando(clientId, 7, btn);
};
</script>
<?php endif; ?>

<!-- Modal Lembrete (criar OU editar — depende de modoLembreteEdit/idLembreteEdit) -->
<div id="modalLembrete" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;overflow-y:auto;padding:1rem 0;">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:520px;width:95%;box-shadow:0 20px 40px rgba(0,0,0,.2);max-height:92vh;overflow-y:auto;">
    <h3 id="modalLembreteTitulo" style="font-size:1rem;margin:0 0 1rem;color:#052228;">💬 Novo Lembrete</h3>
    <form id="formLembrete" onsubmit="event.preventDefault();salvarLembrete();">
        <input type="hidden" id="lembreteId" value="">
        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">O que precisa lembrar? *</label>
            <input type="text" id="lembreteTitulo" class="form-input" required placeholder="Ex: Ligar para cliente às 14h" oninvalid="this.setCustomValidity('⚠️ Informe o que precisa lembrar'); document.getElementById('lembreteTituloErro').style.display='block';" oninput="this.setCustomValidity(''); document.getElementById('lembreteTituloErro').style.display='none';">
            <div id="lembreteTituloErro" style="display:none;color:#dc2626;font-size:.7rem;margin-top:.2rem;font-weight:600;">⚠️ Informe o que precisa lembrar.</div>
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
                // Escapa aspas duplas pra nao quebrar o atributo onclick (bug 11/05/2026
                // reportado pela Amanda: cliente nao vinculava ao lembrete porque o
                // JSON.stringify produz "Nome" com aspas duplas que conflitam com
                // onclick="..." e cortam o atributo na primeira aspas interna).
                var nomeEsc = JSON.stringify(c.name).replace(/"/g, '&quot;');
                return '<div onclick="selecionarClienteLembrete(' + c.id + ',' + nomeEsc + ')" style="padding:.4rem .6rem;cursor:pointer;border-bottom:1px solid #eee;font-size:.8rem;" onmouseover="this.style.background=\'#f3f4f6\'" onmouseout="this.style.background=\'\'">' + c.name + (c.cpf ? ' <span style="color:#94a3b8;font-size:.7rem;">' + c.cpf + '</span>' : '') + '</div>';
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
                var labelEsc = JSON.stringify(label).replace(/"/g, '&quot;');
                return '<div onclick="selecionarCasoLembrete(' + c.id + ',' + labelEsc + ')" style="padding:.4rem .6rem;cursor:pointer;border-bottom:1px solid #eee;font-size:.8rem;" onmouseover="this.style.background=\'#f3f4f6\'" onmouseout="this.style.background=\'\'">' + label + '</div>';
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
