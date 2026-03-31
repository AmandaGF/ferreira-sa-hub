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
echo "=== FIM ===\n";
