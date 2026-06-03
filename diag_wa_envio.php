<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== ULTIMAS 30 MENSAGENS ENVIADAS pelo Hub (hoje 03/06) ==\n";
$st = $pdo->query("SELECT m.id, m.conversa_id, m.zapi_message_id, m.status, m.tipo,
                          LENGTH(m.zapi_message_id) AS zlen,
                          c.telefone, c.canal, c.nome_contato,
                          m.criada_em, m.entregue, m.lida, m.enviado_por_id,
                          LEFT(m.conteudo, 60) AS preview
                   FROM zapi_mensagens m
                   LEFT JOIN zapi_conversas c ON c.id = m.conversa_id
                   WHERE m.direcao = 'enviada'
                     AND m.criada_em > '2026-06-03 00:00:00'
                   ORDER BY m.id DESC LIMIT 30");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tipoId = ($r['zlen'] == 20) ? 'SINT(20)' : (($r['zlen'] == 32) ? 'REAL(32)' : "len={$r['zlen']}");
    echo "  msg#{$r['id']} conv#{$r['conversa_id']} canal={$r['canal']} status={$r['status']} entregue={$r['entregue']} lida={$r['lida']} {$tipoId}\n";
    echo "    para: {$r['nome_contato']} ({$r['telefone']}) | tipo={$r['tipo']} | por user#{$r['enviado_por_id']} em {$r['criada_em']}\n";
    echo "    zapi_id: " . substr($r['zapi_message_id'] ?? '', 0, 40) . "\n";
    echo "    preview: " . $r['preview'] . "\n";
}

echo "\n== ESTATISTICA ULTIMAS 24h ==\n";
$st = $pdo->query("SELECT
    COUNT(*) total,
    SUM(CASE WHEN LENGTH(zapi_message_id) = 20 THEN 1 ELSE 0 END) sinteticas,
    SUM(CASE WHEN LENGTH(zapi_message_id) = 32 THEN 1 ELSE 0 END) reais,
    SUM(CASE WHEN zapi_message_id IS NULL OR zapi_message_id = '' THEN 1 ELSE 0 END) sem_id,
    SUM(CASE WHEN status = 'enviada' THEN 1 ELSE 0 END) status_enviada,
    SUM(CASE WHEN status = '' OR status IS NULL THEN 1 ELSE 0 END) status_vazio,
    SUM(CASE WHEN entregue = 1 THEN 1 ELSE 0 END) entregue,
    SUM(CASE WHEN lida = 1 THEN 1 ELSE 0 END) lida
    FROM zapi_mensagens WHERE direcao='enviada' AND criada_em > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    foreach ($r as $k=>$v) echo "  $k = $v\n";
}

echo "\n== INSTANCIAS Z-API (verificar se conectadas) ==\n";
$st = $pdo->query("SELECT * FROM zapi_instancias");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    foreach ($r as $k => $v) {
        if (in_array($k, array('token','instance_token','phone_token'), true)) { echo "  $k = ***" . substr((string)$v, -4) . "\n"; continue; }
        echo "  $k = " . var_export($v, true) . "\n";
    }
    echo "  ---\n";
}
