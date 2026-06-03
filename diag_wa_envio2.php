<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();

echo "ETAPA 1: Estatistica ultimas 24h\n";
$st = $pdo->prepare("SELECT
    COUNT(*) total,
    SUM(CASE WHEN LENGTH(zapi_message_id) = 20 THEN 1 ELSE 0 END) sint,
    SUM(CASE WHEN LENGTH(zapi_message_id) = 32 THEN 1 ELSE 0 END) reais,
    SUM(CASE WHEN zapi_message_id IS NULL OR zapi_message_id = '' THEN 1 ELSE 0 END) sem_id,
    SUM(CASE WHEN status IS NULL OR status = '' THEN 1 ELSE 0 END) status_vazio,
    SUM(CASE WHEN entregue = 1 THEN 1 ELSE 0 END) entregue_1,
    SUM(CASE WHEN lida = 1 THEN 1 ELSE 0 END) lida_1
FROM zapi_mensagens WHERE direcao='enviada' AND criada_em > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$st->execute();
$rs = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($rs[0] as $k=>$v) echo "  $k = $v\n";

echo "\nETAPA 2: 10 mais recentes enviadas\n";
$st = $pdo->prepare("SELECT m.id, m.conversa_id, LENGTH(m.zapi_message_id) zlen, m.zapi_message_id, m.status, m.entregue, m.lida, c.canal, c.telefone, c.nome_contato FROM zapi_mensagens m LEFT JOIN zapi_conversas c ON c.id = m.conversa_id WHERE m.direcao='enviada' AND m.criada_em > DATE_SUB(NOW(), INTERVAL 6 HOUR) ORDER BY m.id DESC LIMIT 10");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tipo = $r['zlen'] == 20 ? 'SINT' : ($r['zlen'] == 32 ? 'REAL' : "len{$r['zlen']}");
    echo "  msg#{$r['id']} canal={$r['canal']} {$tipo} status='{$r['status']}' ent={$r['entregue']} lida={$r['lida']} | {$r['nome_contato']}/{$r['telefone']}\n";
    echo "    zid: " . substr($r['zapi_message_id'] ?? '', 0, 36) . "\n";
}

echo "\nETAPA 3: Instancias\n";
$cols = $pdo->query("SHOW COLUMNS FROM zapi_instancias")->fetchAll(PDO::FETCH_COLUMN);
echo "  Colunas: " . implode(', ', $cols) . "\n";
foreach ($pdo->query("SELECT * FROM zapi_instancias")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ---\n";
    foreach ($r as $k => $v) {
        if (stripos($k, 'token') !== false) { $v = '***' . substr((string)$v, -4); }
        echo "    $k = " . var_export($v, true) . "\n";
    }
}
