<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$mantida = 416; // mais antiga
$dupli = 417;   // apaga

echo "== 1) Apagar audiencia duplicada #$dupli ==\n";
$st = $pdo->prepare("SELECT id, titulo FROM agenda_eventos WHERE id = ?");
$st->execute(array($dupli));
$r = $st->fetch(PDO::FETCH_ASSOC);
if ($r) {
    $pdo->prepare("DELETE FROM agenda_eventos WHERE id = ?")->execute(array($dupli));
    echo "  Apagada #{$r['id']} ({$r['titulo']})\n";
} else {
    echo "  Audiencia #$dupli ja nao existe\n";
}

echo "\n== 2) Criar andamento que faltou pra audiencia #$mantida ==\n";
$st = $pdo->prepare("SELECT * FROM agenda_eventos WHERE id = ?");
$st->execute(array($mantida));
$aud = $st->fetch(PDO::FETCH_ASSOC);
if (!$aud) { echo "  ERRO: audiencia $mantida nao existe\n"; exit; }
$caseId = (int)$aud['case_id'];
if (!$caseId) {
    echo "  Audiencia sem case_id, sem andamento\n";
} else {
    $stCheck = $pdo->prepare("SELECT COUNT(*) FROM case_andamentos WHERE case_id = ? AND created_at >= '2026-06-02 11:25:00' AND descricao LIKE '%Audi%agendada%'");
    $stCheck->execute(array($caseId));
    if ((int)$stCheck->fetchColumn() > 0) {
        echo "  Andamento ja existe pra esse caso. Nao duplicando.\n";
    } else {
        $dtEv = strtotime($aud['data_inicio']);
        $dataHumana = date('d/m/Y \à\s H:i', $dtEv);
        $descAnd  = "📅 Audiência agendada: {$aud['titulo']}\n";
        $descAnd .= "🗓 Data: {$dataHumana}\n";
        $descAnd .= "🏛 Modalidade: Presencial" . ($aud['local'] ? " — {$aud['local']}" : '') . "\n";
        $descAnd .= "\nℹ️ Orientações sobre a audiência: https://www.ferreiraesa.com.br/audiencias/";
        $pdo->prepare("INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, visivel_cliente, created_by, created_at) VALUES (?, ?, 'audiencia', ?, 1, ?, NOW())")
            ->execute(array($caseId, date('Y-m-d', $dtEv), $descAnd, (int)$aud['created_by']));
        echo "  Andamento criado pra caso #$caseId (data " . date('d/m/Y', $dtEv) . ")\n";
    }
}

echo "\n== 3) Criar lembrete 15d antes (auto que falhou) ==\n";
$stExist = $pdo->prepare("SELECT id FROM agenda_eventos WHERE referencia_evento_id = ? AND tipo='reuniao_interna'");
$stExist->execute(array($mantida));
if ($stExist->fetch()) {
    echo "  Lembrete ja existe. Pulo.\n";
} else {
    $dtAviso = strtotime($aud['data_inicio'] . ' -15 days');
    if ($dtAviso <= time()) {
        echo "  Aviso seria <" . date('d/m/Y', $dtAviso) . " (passado), pulo\n";
    } else {
        $stU = $pdo->prepare("SELECT id FROM users WHERE (email = ? OR LOWER(name) LIKE ?) AND is_active = 1 ORDER BY id LIMIT 1");
        $stU->execute(array('amandaguedesferreira@gmail.com', '%amanda guedes%'));
        $amandaId = (int)$stU->fetchColumn();
        $stU->execute(array('luizeduardo.sa.adv@gmail.com', '%luiz eduardo%'));
        $luizId = (int)$stU->fetchColumn();
        $partAviso = array();
        if ($amandaId) $partAviso[] = $amandaId;
        if ($luizId && $luizId !== $amandaId) $partAviso[] = $luizId;
        $partAvisoJson = !empty($partAviso) ? json_encode($partAviso) : null;
        $respAviso = $amandaId ?: (int)$aud['created_by'];

        $tituloAviso = '🏛 Confirmar audiência presencial + audiencista — ' . $aud['titulo'];
        $descAviso = "Audiência presencial em " . date('d/m/Y \à\s H:i', strtotime($aud['data_inicio'])) . "\n\n"
                   . "PENDÊNCIAS (15 dias antes):\n"
                   . "1. Confirmar se Amanda OU Luiz Eduardo comparecerá presencialmente\n"
                   . "2. Se ninguém do escritório for, contratar audiencista local\n"
                   . "3. Confirmar presença com o cliente";
        $dtAvisoIni = date('Y-m-d H:i:s', $dtAviso);
        $dtAvisoFim = date('Y-m-d H:i:s', $dtAviso + 1800);
        $pdo->prepare("INSERT INTO agenda_eventos (titulo, tipo, modalidade, data_inicio, data_fim, dia_todo, descricao, client_id, case_id, responsavel_id, participantes_ids, visivel_cliente, status, referencia_evento_id, created_by) VALUES (?, 'reuniao_interna', 'nao_aplicavel', ?, ?, 0, ?, ?, ?, ?, ?, 0, 'agendado', ?, ?)")
            ->execute(array($tituloAviso, $dtAvisoIni, $dtAvisoFim, $descAviso, (int)$aud['client_id'], $caseId, $respAviso, $partAvisoJson, $mantida, (int)$aud['created_by']));
        echo "  Lembrete criado pra " . date('d/m/Y H:i', $dtAviso) . "\n";
    }
}

echo "\n== Resumo final ==\n";
$st = $pdo->query("SELECT id, titulo, tipo, modalidade, data_inicio, status, referencia_evento_id FROM agenda_eventos WHERE (id = $mantida OR referencia_evento_id = $mantida OR id = $dupli) ORDER BY id");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  #{$r['id']} tipo={$r['tipo']} status={$r['status']} data={$r['data_inicio']} ref={$r['referencia_evento_id']} {$r['titulo']}\n";
}
echo "FIM\n";
