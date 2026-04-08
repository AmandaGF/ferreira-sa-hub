<?php
/**
 * Cron Job — Lembretes da Agenda
 * Rodar a cada hora via cron do cPanel
 * Comando: php /home/ferre315/public_html/conecta/cron/agenda_lembretes.php
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';

$pdo = db();
$agora = new DateTime();
$em24h = (new DateTime())->modify('+24 hours');
$em2h  = (new DateTime())->modify('+2 hours');

echo "=== Agenda Lembretes — " . $agora->format('d/m/Y H:i') . " ===\n";

// ── 1. Lembrete 1 dia antes (email + portal) ──
$stmt = $pdo->prepare(
    "SELECT e.*, c.name as client_name, c.phone as client_phone, u.name as responsavel_name, u.email as responsavel_email
     FROM agenda_eventos e
     LEFT JOIN clients c ON c.id = e.client_id
     LEFT JOIN users u ON u.id = e.responsavel_id
     WHERE e.status = 'agendado'
       AND e.lembrete_1d_enviado = 0
       AND e.data_inicio BETWEEN ? AND ?
       AND e.lembrete_portal = 1"
);
$stmt->execute(array($agora->format('Y-m-d H:i:s'), $em24h->format('Y-m-d H:i:s')));
$eventos1d = $stmt->fetchAll();

foreach ($eventos1d as $ev) {
    $dataFmt = date('d/m/Y H:i', strtotime($ev['data_inicio']));
    $msg = $ev['titulo'] . ' em ' . $dataFmt;

    // Notificação portal para responsável
    if ($ev['responsavel_id']) {
        try {
            notify(
                (int)$ev['responsavel_id'],
                'Lembrete: ' . $ev['titulo'],
                'Amanhã ' . $dataFmt . ($ev['client_name'] ? ' — ' . $ev['client_name'] : ''),
                '/conecta/modules/agenda/?evento=' . $ev['id'],
                'alerta',
                '📅'
            );
        } catch (Exception $ex) {}
    }

    // Marcar como enviado
    $pdo->prepare("UPDATE agenda_eventos SET lembrete_1d_enviado = 1 WHERE id = ?")
        ->execute(array($ev['id']));

    echo "  [1d] #" . $ev['id'] . " — " . $ev['titulo'] . " => " . ($ev['responsavel_name'] ?: '?') . "\n";
}

// ── 2. Lembrete 2h antes (portal + WhatsApp cliente) ──
$stmt = $pdo->prepare(
    "SELECT e.*, c.name as client_name, c.phone as client_phone, u.name as responsavel_name
     FROM agenda_eventos e
     LEFT JOIN clients c ON c.id = e.client_id
     LEFT JOIN users u ON u.id = e.responsavel_id
     WHERE e.status = 'agendado'
       AND e.lembrete_2h_enviado = 0
       AND e.data_inicio BETWEEN ? AND ?"
);
$stmt->execute(array($agora->format('Y-m-d H:i:s'), $em2h->format('Y-m-d H:i:s')));
$eventos2h = $stmt->fetchAll();

foreach ($eventos2h as $ev) {
    $dataFmt = date('d/m/Y H:i', strtotime($ev['data_inicio']));

    // Notificação portal
    if ($ev['responsavel_id']) {
        try {
            notify(
                (int)$ev['responsavel_id'],
                'Em 2h: ' . $ev['titulo'],
                $dataFmt . ($ev['client_name'] ? ' — ' . $ev['client_name'] : ''),
                '/conecta/modules/agenda/?evento=' . $ev['id'],
                'urgencia',
                '⏰'
            );
        } catch (Exception $ex) {}
    }

    // WhatsApp para cliente (gerar link para envio manual)
    if ($ev['lembrete_cliente'] && $ev['client_id'] && $ev['client_phone'] && $ev['msg_cliente']) {
        $dt = new DateTime($ev['data_inicio']);
        $msgFinal = str_replace(
            array('[nome]', '[data]', '[hora]', '[link_meet]'),
            array($ev['client_name'] ?: '', $dt->format('d/m/Y'), $dt->format('H:i'), $ev['meet_link'] ?: ''),
            $ev['msg_cliente']
        );

        // Salvar como notificação de cliente (para envio manual via WhatsApp)
        try {
            $pdo->prepare(
                "INSERT INTO notificacoes_cliente (client_id, case_id, tipo, mensagem, status, criado_por)
                 VALUES (?,?,?,?,?,?)"
            )->execute(array(
                $ev['client_id'], $ev['case_id'] ?: null, 'lembrete_agenda',
                $msgFinal, 'pendente', $ev['responsavel_id']
            ));
        } catch (Exception $ex) {}
    }

    // Marcar como enviado
    $pdo->prepare("UPDATE agenda_eventos SET lembrete_2h_enviado = 1 WHERE id = ?")
        ->execute(array($ev['id']));

    echo "  [2h] #" . $ev['id'] . " — " . $ev['titulo'] . "\n";
}

echo "\nTotal 1d: " . count($eventos1d) . " | Total 2h: " . count($eventos2h) . "\n";

// ── 3. Alertas escalonados de prazos processuais (7d, 3d, 1d, HOJE) ──
echo "\n--- Alertas Escalonados de Prazos ---\n";
try {
    $hoje = date('Y-m-d');
    $em1d = date('Y-m-d', strtotime('+1 day'));
    $em3d = date('Y-m-d', strtotime('+3 days'));
    $em7d = date('Y-m-d', strtotime('+7 days'));

    // Buscar TODOS os prazos ativos nos próximos 7 dias
    $stmtPrazos = $pdo->prepare(
        "SELECT p.*, cs.title as case_title, cs.responsible_user_id, cl.name as client_name
         FROM prazos_processuais p
         LEFT JOIN cases cs ON cs.id = p.case_id
         LEFT JOIN clients cl ON cl.id = p.client_id
         WHERE p.concluido = 0 AND p.prazo_fatal BETWEEN ? AND ?"
    );
    $stmtPrazos->execute(array($hoje, $em7d));
    $prazosProximos = $stmtPrazos->fetchAll();

    // Usuários que devem receber alertas
    $opUsers = $pdo->query("SELECT id, name, email FROM users WHERE role IN ('admin','gestao','operacional') AND is_active = 1")->fetchAll();
    $opIds = array_column($opUsers, 'id');

    $totalAlertas = 0;
    foreach ($prazosProximos as $pr) {
        $diasRestantes = (int)((strtotime($pr['prazo_fatal']) - strtotime($hoje)) / 86400);
        $dataFmt = date('d/m/Y', strtotime($pr['prazo_fatal']));

        // Definir nível de urgência e ícone
        if ($diasRestantes <= 0) {
            $nivelTag = 'HOJE'; $icon = '🚨'; $tipo = 'urgencia';
        } elseif ($diasRestantes <= 1) {
            $nivelTag = 'AMANHÃ'; $icon = '🔴'; $tipo = 'urgencia';
        } elseif ($diasRestantes <= 3) {
            $nivelTag = $diasRestantes . 'd'; $icon = '⚠️'; $tipo = 'alerta';
        } else {
            $nivelTag = $diasRestantes . 'd'; $icon = '📅'; $tipo = 'info';
        }

        // Coluna de controle: alertado_7d, alertado_3d, alertado_1d, alertado_hoje
        $colAlerta = null;
        if ($diasRestantes <= 0 && empty($pr['alertado_hoje'])) $colAlerta = 'alertado_hoje';
        elseif ($diasRestantes <= 1 && empty($pr['alertado_1d'])) $colAlerta = 'alertado_1d';
        elseif ($diasRestantes <= 3 && empty($pr['alertado_3d'])) $colAlerta = 'alertado_3d';
        elseif ($diasRestantes <= 7 && empty($pr['alertado_7d'])) $colAlerta = 'alertado_7d';

        if (!$colAlerta) continue; // Já alertado neste nível

        $titulo = $icon . ' Prazo ' . $nivelTag . ': ' . $pr['descricao_acao'];
        $msg = 'Prazo fatal ' . $dataFmt . ' — ' . ($pr['case_title'] ?: ($pr['numero_processo'] ?: 'Processo')) . ($pr['client_name'] ? ' (' . $pr['client_name'] . ')' : '');
        $link = '/conecta/modules/prazos/';

        // Notificar responsável do caso + todos operacionais/admin
        $notificar = $opIds;
        if ($pr['responsible_user_id'] && !in_array((int)$pr['responsible_user_id'], $notificar)) {
            $notificar[] = (int)$pr['responsible_user_id'];
        }
        if ($pr['usuario_id'] && !in_array((int)$pr['usuario_id'], $notificar)) {
            $notificar[] = (int)$pr['usuario_id'];
        }

        foreach ($notificar as $uid) {
            try { notify($uid, $titulo, $msg, $tipo, $link, $icon); } catch (Exception $ex) {}
        }

        // Marcar nível de alerta
        try {
            $pdo->prepare("UPDATE prazos_processuais SET $colAlerta = NOW() WHERE id = ?")->execute(array($pr['id']));
        } catch (Exception $e) {
            // Coluna pode não existir ainda — silenciar
        }

        $totalAlertas++;
        echo "  [$nivelTag] #" . $pr['id'] . " — " . $pr['descricao_acao'] . " (fatal: $dataFmt) => " . count($notificar) . " notificados\n";
    }

    echo "Total prazos alertados: $totalAlertas\n";

    // Alertas de tarefas tipo=prazo (manter compatibilidade)
    $amanha = (new DateTime())->modify('+1 day')->format('Y-m-d');
    $stmtTarefas = $pdo->prepare(
        "SELECT t.id, t.title, t.due_date, t.case_id, t.assigned_to, cs.title as case_title
         FROM case_tasks t LEFT JOIN cases cs ON cs.id = t.case_id
         WHERE t.tipo = 'prazo' AND t.status NOT IN ('concluido') AND t.alerta_enviado = 0
           AND t.prazo_alerta IS NOT NULL AND t.prazo_alerta <= ?"
    );
    $stmtTarefas->execute(array($amanha));
    foreach ($stmtTarefas->fetchAll() as $pr) {
        $dataFmt = date('d/m/Y', strtotime($pr['due_date']));
        foreach ($opIds as $uid) {
            try { notify($uid, '⏰ Prazo tarefa: ' . $pr['title'], 'Fatal ' . $dataFmt . ' — ' . ($pr['case_title'] ?: ''), 'urgencia', '/conecta/modules/tarefas/', '⏰'); } catch (Exception $ex) {}
        }
        $pdo->prepare("UPDATE case_tasks SET alerta_enviado = 1 WHERE id = ?")->execute(array($pr['id']));
        echo "  [TAREFA] #" . $pr['id'] . " — " . $pr['title'] . "\n";
    }
} catch (Exception $e) {
    echo "  [ERRO PRAZOS] " . $e->getMessage() . "\n";
}

echo "=== FIM ===\n";
