<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
function p($s){ echo $s . "\n"; }

p("== Estatistica enviadas ultimas 24h ==");
$st = $pdo->prepare("SELECT
    COUNT(*) total,
    SUM(CASE WHEN LENGTH(zapi_message_id) = 20 THEN 1 ELSE 0 END) sint20,
    SUM(CASE WHEN LENGTH(zapi_message_id) = 32 THEN 1 ELSE 0 END) reais32,
    SUM(CASE WHEN zapi_message_id IS NULL OR zapi_message_id = '' THEN 1 ELSE 0 END) sem_id,
    SUM(CASE WHEN status IS NULL OR status = '' THEN 1 ELSE 0 END) status_vazio,
    SUM(CASE WHEN entregue = 1 THEN 1 ELSE 0 END) entregue,
    SUM(CASE WHEN lida = 1 THEN 1 ELSE 0 END) lida
FROM zapi_mensagens WHERE direcao='enviada' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$st->execute();
$rs = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($rs[0] as $k=>$v) p("  $k = $v");

p("\n== 15 mensagens mais recentes enviadas (ultimas 6h) ==");
$st = $pdo->prepare("SELECT m.id, m.conversa_id, m.zapi_message_id, LENGTH(m.zapi_message_id) zlen, m.status, m.entregue, m.lida, c.canal, c.telefone, c.nome_contato, LEFT(m.conteudo, 50) prev FROM zapi_mensagens m LEFT JOIN zapi_conversas c ON c.id = m.conversa_id WHERE m.direcao='enviada' AND m.created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR) ORDER BY m.id DESC LIMIT 15");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tipo = $r['zlen'] == 20 ? 'SINT' : ($r['zlen'] == 32 ? 'REAL' : "L{$r['zlen']}");
    p("  msg#{$r['id']} c#{$r['conversa_id']} ch{$r['canal']} [$tipo] st='{$r['status']}' e={$r['entregue']} l={$r['lida']} | {$r['nome_contato']} {$r['telefone']}");
    p("    z: " . substr((string)$r['zapi_message_id'], 0, 36));
    p("    p: " . $r['prev']);
}

p("\n== Instancias Z-API ==");
foreach ($pdo->query("SELECT * FROM zapi_instancias")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    p("  ---");
    foreach ($r as $k => $v) {
        if (stripos($k, 'token') !== false) $v = '***' . substr((string)$v, -4);
        p("    $k = " . var_export($v, true));
    }
}
