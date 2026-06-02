<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();

function p($s){ echo $s . "\n"; ob_flush(); flush(); }

p("PASSO 1: ler audiencia 416");
$st = $pdo->prepare("SELECT * FROM agenda_eventos WHERE id = 416");
$st->execute();
$aud = $st->fetch(PDO::FETCH_ASSOC);
if (!$aud) { p("ERRO: audiencia 416 nao existe"); exit; }
p("  data_inicio: " . $aud['data_inicio']);

p("PASSO 2: checa lembrete ja existe");
$stExist = $pdo->prepare("SELECT id FROM agenda_eventos WHERE referencia_evento_id = 416 AND tipo='reuniao_interna'");
$stExist->execute();
$ex = $stExist->fetch();
if ($ex) { p("  Lembrete ja existe id=" . $ex['id']); exit; }
p("  nao existe, prosseguindo");

p("PASSO 3: calcula data aviso");
$dtAviso = strtotime($aud['data_inicio'] . ' -15 days');
p("  dtAviso = " . date('Y-m-d H:i:s', $dtAviso));

p("PASSO 4: busca users Amanda e Luiz");
$stU = $pdo->prepare("SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
$stU->execute(array('amandaguedesferreira@gmail.com'));
$amandaId = (int)$stU->fetchColumn();
p("  amandaId=$amandaId");
$stU->execute(array('luizeduardo.sa.adv@gmail.com'));
$luizId = (int)$stU->fetchColumn();
p("  luizId=$luizId");

p("PASSO 5: monta participantes");
$partAviso = array();
if ($amandaId) $partAviso[] = $amandaId;
if ($luizId && $luizId !== $amandaId) $partAviso[] = $luizId;
$partAvisoJson = !empty($partAviso) ? json_encode($partAviso) : null;
p("  json=" . $partAvisoJson);

p("PASSO 6: verifica colunas que vou usar");
foreach (array('referencia_evento_id','dia_todo','visivel_cliente') as $c) {
    $r = $pdo->query("SHOW COLUMNS FROM agenda_eventos LIKE '$c'")->fetch();
    p("  $c " . ($r ? 'OK' : 'FALTANDO'));
}

p("PASSO 7: INSERT do lembrete");
try {
    $tituloAviso = '🏛 Confirmar audiência presencial + audiencista — ' . $aud['titulo'];
    $descAviso = "Audiência presencial em " . date('d/m/Y \à\s H:i', strtotime($aud['data_inicio'])) . "\n\n"
               . "PENDÊNCIAS (15 dias antes):\n"
               . "1. Confirmar se Amanda OU Luiz Eduardo comparecerá presencialmente\n"
               . "2. Se ninguém do escritório for, contratar audiencista local\n"
               . "3. Confirmar presença com o cliente";
    $dtAvisoIni = date('Y-m-d H:i:s', $dtAviso);
    $dtAvisoFim = date('Y-m-d H:i:s', $dtAviso + 1800);
    $stIns = $pdo->prepare("INSERT INTO agenda_eventos (titulo, tipo, modalidade, data_inicio, data_fim, dia_todo, descricao, client_id, case_id, responsavel_id, participantes_ids, visivel_cliente, status, referencia_evento_id, created_by) VALUES (?, 'reuniao_interna', 'nao_aplicavel', ?, ?, 0, ?, ?, ?, ?, ?, 0, 'agendado', ?, ?)");
    $stIns->execute(array($tituloAviso, $dtAvisoIni, $dtAvisoFim, $descAviso, (int)$aud['client_id'], (int)$aud['case_id'], $amandaId ?: 1, $partAvisoJson, 416, (int)$aud['created_by']));
    p("  SUCESSO id=" . $pdo->lastInsertId());
} catch (Throwable $e) {
    p("  ERRO: " . $e->getMessage());
}

p("FIM");
