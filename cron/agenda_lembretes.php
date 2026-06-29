<?php
/**
 * Cron Job — Lembretes da Agenda
 * Rodar a cada hora via cron do cPanel
 * Comando: php /home/ferre315/public_html/conecta/cron/agenda_lembretes.php
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_zapi.php';

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

// ════════════════════════════════════════════════════════════════════════
// 3-CLIENTE. Lembrete WhatsApp D-1 AUTOMATICO pro cliente da audiencia
// (29/06/2026 Amanda).
//
// Roda 1x por dia (entre 8h e 20h) — busca eventos de audiência/CEJUSC/reuniao
// presencial entre +22h e +26h do agora, e onde cliente_avisado_em IS NULL.
// Envia via Z-API canal 24 (operacional) e marca cliente_avisado_em=NOW(),
// cliente_avisado_por=-1 (-1 = sistema cron).
//
// Killswitch: configuracoes.lembrete_d1_auto_cliente = '0' desliga tudo.
// ════════════════════════════════════════════════════════════════════════
echo "\n--- D-1 cliente (WhatsApp automático) ---\n";
try {
    $horaAtual = (int)date('H');
    if ($horaAtual < 8 || $horaAtual >= 20) {
        echo "  Fora de horário (8h-20h). Pulando envio.\n";
    } else {
        $killswitch = $pdo->query("SELECT valor FROM configuracoes WHERE chave='lembrete_d1_auto_cliente'")->fetchColumn();
        if ($killswitch === '0') {
            echo "  Desligado em configuracoes.lembrete_d1_auto_cliente=0. Pulando.\n";
        } else {
            $em22h = (new DateTime())->modify('+22 hours')->format('Y-m-d H:i:s');
            $em26h = (new DateTime())->modify('+26 hours')->format('Y-m-d H:i:s');
            $stmtD1 = $pdo->prepare(
                "SELECT e.id, e.titulo, e.tipo, e.subtipo, e.modalidade, e.data_inicio, e.local, e.meet_link,
                        e.cliente_presencial, e.case_id,
                        c.id AS client_id, c.name AS client_name, c.phone AS client_phone,
                        cs.title AS case_title
                 FROM agenda_eventos e
                 JOIN clients c ON c.id = e.client_id
                 LEFT JOIN cases cs ON cs.id = e.case_id
                 WHERE e.status = 'agendado'
                   AND e.cliente_avisado_em IS NULL
                   AND e.data_inicio BETWEEN ? AND ?
                   AND (
                        e.tipo IN ('audiencia','mediacao_cejusc')
                        OR (e.tipo = 'reuniao' AND e.cliente_presencial = 1)
                   )
                   AND c.phone IS NOT NULL AND c.phone <> ''"
            );
            $stmtD1->execute(array($em22h, $em26h));
            $eventosD1 = $stmtD1->fetchAll();

            $totEnv = 0; $totErr = 0;
            foreach ($eventosD1 as $ev) {
                $dt = new DateTime($ev['data_inicio']);
                $dataFmt = $dt->format('d/m');
                $horaFmt = $dt->format('H:i');
                $primeiroNome = explode(' ', trim($ev['client_name']))[0];

                // Label do tipo
                $tipoLabel = '*compromisso*';
                if ($ev['tipo'] === 'audiencia') $tipoLabel = '*audiência*';
                elseif ($ev['tipo'] === 'mediacao_cejusc') $tipoLabel = '*audiência de mediação no CEJUSC*';
                elseif ($ev['tipo'] === 'reuniao') $tipoLabel = '*reunião*';

                $msg  = "Olá, {$primeiroNome}!\n\n";
                $msg .= "Lembramos que sua {$tipoLabel} está marcada para *amanhã, {$dataFmt} às {$horaFmt}*.\n\n";
                if (!empty($ev['meet_link'])) {
                    $msg .= "💻 Será por videoconferência.\n";
                    $msg .= "🔗 Link: {$ev['meet_link']}\n\n";
                } elseif (!empty($ev['local'])) {
                    $msg .= "📍 Local: {$ev['local']}\n\n";
                }
                $msg .= "Em caso de imprevisto, nos avise o quanto antes por aqui.\n\n";
                $msg .= "Equipe Ferreira & Sá Advocacia 🤝";

                $r = zapi_send_text('24', $ev['client_phone'], $msg);
                if (!empty($r['ok'])) {
                    $pdo->prepare("UPDATE agenda_eventos SET cliente_avisado_em = NOW(), cliente_avisado_por = -1 WHERE id = ?")
                        ->execute(array($ev['id']));
                    echo "  ✓ #{$ev['id']} '{$ev['titulo']}' -> {$ev['client_name']} ({$ev['client_phone']})\n";
                    $totEnv++;
                } else {
                    $erro = isset($r['erro']) ? $r['erro'] : 'desconhecido';
                    echo "  ✗ #{$ev['id']} '{$ev['titulo']}' -> {$ev['client_name']}: {$erro}\n";
                    $totErr++;
                }
            }
            echo "Total enviados: {$totEnv} | Erros: {$totErr}\n";
        }
    }
} catch (Exception $e) {
    echo "  [ERRO D-1 cliente] " . $e->getMessage() . "\n";
}

// ════════════════════════════════════════════════════════════════════════
// 3-AUDIENCISTA. Lembrete WhatsApp D-1 AUTOMATICO pro audiencista designado
// (29/06/2026 Amanda).
//
// Roda 1x por dia (entre 8h e 20h) — busca audiencias com status='designada',
// data_hora entre +22h e +26h, audiencista_avisado_em IS NULL. Envia via Z-API
// canal 24 e marca audiencista_avisado_em=NOW().
//
// Killswitch: configuracoes.lembrete_d1_auto_audiencista = '0' desliga.
// ════════════════════════════════════════════════════════════════════════
echo "\n--- D-1 audiencista (WhatsApp automático) ---\n";
try {
    $horaAtual = (int)date('H');
    if ($horaAtual < 8 || $horaAtual >= 20) {
        echo "  Fora de horário (8h-20h). Pulando envio.\n";
    } else {
        $killswitch = $pdo->query("SELECT valor FROM configuracoes WHERE chave='lembrete_d1_auto_audiencista'")->fetchColumn();
        if ($killswitch === '0') {
            echo "  Desligado em configuracoes.lembrete_d1_auto_audiencista=0. Pulando.\n";
        } else {
            // Self-heal das colunas (idempotente)
            try { $pdo->exec("ALTER TABLE audiencias ADD COLUMN audiencista_avisado_em DATETIME NULL"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE audiencias ADD COLUMN audiencista_avisado_por INT NULL"); } catch (Exception $e) {}

            $em22h = (new DateTime())->modify('+22 hours')->format('Y-m-d H:i:s');
            $em26h = (new DateTime())->modify('+26 hours')->format('Y-m-d H:i:s');
            $stmtAu = $pdo->prepare(
                "SELECT a.id, a.tipo, a.data_hora, a.comarca, a.local, a.modalidade, a.tipo_processo,
                        a.processo_numero, a.orientacoes,
                        au.id AS audiencista_id, au.nome AS audiencista_nome, au.telefone AS audiencista_phone,
                        cl.name AS client_name,
                        cs.title AS case_title
                 FROM audiencias a
                 JOIN audiencistas au ON au.id = a.audiencista_id
                 LEFT JOIN clients cl ON cl.id = a.client_id
                 LEFT JOIN cases cs ON cs.id = a.case_id
                 WHERE a.status = 'designada'
                   AND a.audiencista_avisado_em IS NULL
                   AND a.data_hora BETWEEN ? AND ?
                   AND au.telefone IS NOT NULL AND au.telefone <> ''"
            );
            $stmtAu->execute(array($em22h, $em26h));
            $audsD1 = $stmtAu->fetchAll();

            $totEnv = 0; $totErr = 0;
            foreach ($audsD1 as $au) {
                $dt = new DateTime($au['data_hora']);
                $dataFmt = $dt->format('d/m');
                $horaFmt = $dt->format('H:i');
                $primNome = explode(' ', trim($au['audiencista_nome']))[0];
                $localTxt = $au['modalidade'] === 'virtual'
                    ? '💻 Virtual'
                    : ('📍 ' . ($au['local'] ?: $au['comarca'] ?: 'Local a confirmar'));

                $msg  = "Olá, *Dr(a). {$primNome}*!\n\n";
                $msg .= "Lembramos da audiência designada para *amanhã, {$dataFmt} às {$horaFmt}*.\n\n";
                if ($au['tipo_processo']) $msg .= "⚖️ Tipo: {$au['tipo_processo']}\n";
                if ($au['client_name'])    $msg .= "👤 Cliente: {$au['client_name']}\n";
                if ($au['processo_numero']) $msg .= "📋 Processo: {$au['processo_numero']}\n";
                $msg .= "{$localTxt}\n\n";
                if (!empty($au['orientacoes'])) {
                    $orient = mb_substr(trim($au['orientacoes']), 0, 600);
                    $msg .= "📝 *Orientações:*\n{$orient}\n\n";
                }
                $msg .= "Qualquer dúvida ou imprevisto, nos avise.\n\n";
                $msg .= "Equipe Ferreira & Sá Advocacia 🤝";

                $r = zapi_send_text('24', $au['audiencista_phone'], $msg);
                if (!empty($r['ok'])) {
                    $pdo->prepare("UPDATE audiencias SET audiencista_avisado_em = NOW(), audiencista_avisado_por = -1 WHERE id = ?")
                        ->execute(array($au['id']));
                    echo "  ✓ Aud#{$au['id']} -> {$au['audiencista_nome']} ({$au['audiencista_phone']})\n";
                    $totEnv++;
                } else {
                    $erro = isset($r['erro']) ? $r['erro'] : 'desconhecido';
                    echo "  ✗ Aud#{$au['id']} -> {$au['audiencista_nome']}: {$erro}\n";
                    $totErr++;
                }
            }
            echo "Total enviados: {$totEnv} | Erros: {$totErr}\n";
        }
    }
} catch (Exception $e) {
    echo "  [ERRO D-1 audiencista] " . $e->getMessage() . "\n";
}

// ── 3. Alertas escalonados de prazos processuais (7d, 3d, 1d, HOJE) ──
echo "\n--- Alertas Escalonados de Prazos ---\n";
try {
    $hoje = date('Y-m-d');
    $em1d = date('Y-m-d', strtotime('+1 day'));
    $em3d = date('Y-m-d', strtotime('+3 days'));
    $em7d = date('Y-m-d', strtotime('+7 days'));

    // Self-heal: coluna pra registrar quando foi a ultima notificacao de prazo VENCIDO.
    // Antes, prazos com prazo_fatal < hoje sumiam da query (BETWEEN hoje AND +7d) e a
    // notificacao parava de aparecer assim que o prazo passava — o oposto do que faz
    // sentido. Agora vencidos sao re-notificados 1x por dia ate serem concluidos.
    try { $pdo->exec("ALTER TABLE prazos_processuais ADD COLUMN alertado_vencido_em DATETIME NULL"); } catch (Exception $e) {}

    // Buscar TODOS os prazos ativos: VENCIDOS (sem limite inferior) ate +7d
    $stmtPrazos = $pdo->prepare(
        "SELECT p.*, cs.title as case_title, cs.responsible_user_id, cl.name as client_name
         FROM prazos_processuais p
         LEFT JOIN cases cs ON cs.id = p.case_id
         LEFT JOIN clients cl ON cl.id = p.client_id
         WHERE p.concluido = 0 AND p.prazo_fatal <= ?"
    );
    $stmtPrazos->execute(array($em7d));
    $prazosProximos = $stmtPrazos->fetchAll();

    // Usuários que devem receber alertas
    $opUsers = $pdo->query("SELECT id, name, email FROM users WHERE role IN ('admin','gestao','operacional') AND is_active = 1")->fetchAll();
    $opIds = array_column($opUsers, 'id');

    $totalAlertas = 0;
    foreach ($prazosProximos as $pr) {
        $diasRestantes = (int)((strtotime($pr['prazo_fatal']) - strtotime($hoje)) / 86400);
        $dataFmt = date('d/m/Y', strtotime($pr['prazo_fatal']));

        // Definir nível de urgência e ícone
        if ($diasRestantes < 0) {
            $diasVencido = abs($diasRestantes);
            $nivelTag = 'VENCIDO há ' . $diasVencido . 'd'; $icon = '🚨'; $tipo = 'urgencia';
        } elseif ($diasRestantes == 0) {
            $nivelTag = 'HOJE'; $icon = '🚨'; $tipo = 'urgencia';
        } elseif ($diasRestantes <= 1) {
            $nivelTag = 'AMANHÃ'; $icon = '🔴'; $tipo = 'urgencia';
        } elseif ($diasRestantes <= 3) {
            $nivelTag = $diasRestantes . 'd'; $icon = '⚠️'; $tipo = 'alerta';
        } else {
            $nivelTag = $diasRestantes . 'd'; $icon = '📅'; $tipo = 'info';
        }

        // Coluna de controle: alertado_vencido_em, alertado_hoje, alertado_1d, alertado_3d, alertado_7d
        // Para VENCIDOS: re-notifica 1x por dia (compara DATE de alertado_vencido_em com hoje).
        $colAlerta = null;
        if ($diasRestantes < 0) {
            $ultimoAlertaVencido = !empty($pr['alertado_vencido_em']) ? date('Y-m-d', strtotime($pr['alertado_vencido_em'])) : null;
            if ($ultimoAlertaVencido !== $hoje) {
                $colAlerta = 'alertado_vencido_em'; // re-alerta a cada dia novo
            }
        }
        elseif ($diasRestantes == 0 && empty($pr['alertado_hoje'])) $colAlerta = 'alertado_hoje';
        elseif ($diasRestantes <= 1 && empty($pr['alertado_1d'])) $colAlerta = 'alertado_1d';
        elseif ($diasRestantes <= 3 && empty($pr['alertado_3d'])) $colAlerta = 'alertado_3d';
        elseif ($diasRestantes <= 7 && empty($pr['alertado_7d'])) $colAlerta = 'alertado_7d';

        if (!$colAlerta) continue; // Já alertado neste nível (ou ja notificou vencido hoje)

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
            // Push só pra prazos urgentes (hoje/amanhã) — evita barulho pra prazos distantes
            if ($diasRestantes <= 1 && function_exists('push_notify')) {
                try { push_notify($uid, $titulo, $msg, $link, ($diasRestantes <= 0)); } catch (Exception $ex) {}
            }
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
