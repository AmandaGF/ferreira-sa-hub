<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
function p($s){ echo $s . "\n"; }

p("== MSGS DEFINITIVAMENTE NAO ENTREGUES (status vazio + entregue=0) ultimas 48h ==");
$st = $pdo->prepare("SELECT m.id, m.conversa_id, m.zapi_message_id, m.created_at, m.tipo, m.enviado_por_id, u.name as quem, c.canal, c.telefone, c.nome_contato, LEFT(m.conteudo, 80) prev
                     FROM zapi_mensagens m
                     LEFT JOIN zapi_conversas c ON c.id = m.conversa_id
                     LEFT JOIN users u ON u.id = m.enviado_por_id
                     WHERE m.direcao='enviada'
                       AND m.created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)
                       AND (m.status IS NULL OR m.status = '')
                       AND m.entregue = 0
                     ORDER BY m.id DESC");
$st->execute();
$rs = $st->fetchAll(PDO::FETCH_ASSOC);
p("  TOTAL: " . count($rs));
foreach ($rs as $r) {
    p("  msg#{$r['id']} c#{$r['conversa_id']} ch{$r['canal']} por={$r['quem']} em {$r['created_at']} {$r['tipo']}");
    p("    pra: {$r['nome_contato']} ({$r['telefone']})");
    p("    z: " . substr((string)$r['zapi_message_id'], 0, 36));
    p("    p: " . $r['prev']);
}

p("\n== POR ATENDENTE - ultimas 24h ==");
$st = $pdo->prepare("SELECT u.name, COUNT(*) total,
    SUM(CASE WHEN m.entregue = 1 THEN 1 ELSE 0 END) entregue,
    SUM(CASE WHEN m.entregue = 0 AND (m.status IS NULL OR m.status='') THEN 1 ELSE 0 END) falhou,
    SUM(CASE WHEN m.status = 'SENT' AND m.entregue = 0 THEN 1 ELSE 0 END) so_enviada
    FROM zapi_mensagens m
    LEFT JOIN users u ON u.id = m.enviado_por_id
    WHERE m.direcao='enviada'
      AND m.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND m.enviado_por_bot = 0
    GROUP BY u.name
    ORDER BY total DESC");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    p("  {$r['name']}: total={$r['total']} entregue={$r['entregue']} falhou={$r['falhou']} so_SENT={$r['so_enviada']}");
}

p("\n== POR HORA - ultimas 24h (identificar burst de falhas) ==");
$st = $pdo->prepare("SELECT DATE_FORMAT(m.created_at,'%d/%m %H') hora,
    COUNT(*) total,
    SUM(CASE WHEN m.entregue = 0 AND (m.status IS NULL OR m.status='') THEN 1 ELSE 0 END) falhou
    FROM zapi_mensagens m
    WHERE m.direcao='enviada' AND m.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY hora ORDER BY hora");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $bar = $r['falhou'] > 0 ? ' <-- ' . $r['falhou'] . ' falharam' : '';
    p("  {$r['hora']}: {$r['total']} envios{$bar}");
}
