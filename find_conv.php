<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();
$num = preg_replace('/\D/', '', $_GET['num'] ?? '');
if (!$num) { echo "?num=NUMERO\n"; exit; }
$ult10 = substr($num, -10);
echo "Buscando por últimos 10 dígitos: {$ult10}\n\n";

$r = $pdo->query("SELECT id, canal, telefone, chat_lid, nome_contato, atendente_id, status, ultima_msg_em, created_at,
    (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = zapi_conversas.id) AS msgs
    FROM zapi_conversas
    WHERE RIGHT(REPLACE(REPLACE(telefone,'@lid',''),'@g.us',''), 10) = '{$ult10}'
       OR telefone LIKE '%{$ult10}%'
       OR nome_contato LIKE '%{$ult10}%'
    ORDER BY ultima_msg_em DESC")->fetchAll();

echo "Encontradas: " . count($r) . "\n\n";
foreach ($r as $c) {
    echo sprintf("#%d canal=%s tel=%s chat_lid=%s nome='%s' msgs=%d atend=%s [%s] criada=%s ult=%s\n",
        $c['id'], $c['canal'], $c['telefone'], $c['chat_lid'] ?: '-',
        $c['nome_contato'] ?: '?', $c['msgs'], $c['atendente_id'] ?: '-',
        $c['status'], $c['created_at'], $c['ultima_msg_em']);
}
