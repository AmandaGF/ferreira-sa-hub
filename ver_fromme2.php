<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$logFile = APP_ROOT . '/files/zapi_webhook.log';
$lines = file($logFile);
$tail = array_slice($lines, -500);

echo "=== Payloads COMPLETOS com fromMe:true (últimos 5) ===\n\n";
$count = 0;
foreach (array_reverse($tail) as $l) {
    if (strpos($l, '"fromMe":true') !== false) {
        echo "━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo $l . "\n";
        $count++;
        if ($count >= 5) break;
    }
}
if ($count === 0) echo "⚠️ Ainda nenhum fromMe no log (provavelmente Z-API ainda não propagou).\n";

echo "\n\n=== Conversas criadas hoje ===\n";
$pdo = db();
$hoje = $pdo->query("SELECT id, telefone, nome_contato, canal, created_at FROM zapi_conversas WHERE DATE(created_at) = CURDATE() ORDER BY id DESC")->fetchAll();
foreach ($hoje as $c) {
    echo sprintf("  #%s  canal=%s  tel=%-18s  nome='%s'  %s\n",
        $c['id'], $c['canal'], $c['telefone'], $c['nome_contato'], $c['created_at']);
}

echo "\n\n=== Mensagens 'fromMe' salvas (enviadas, com zapi_message_id preenchido, últimas 10) ===\n";
$rows = $pdo->query("
    SELECT m.id, m.conversa_id, m.direcao, m.conteudo, m.created_at,
           co.telefone, co.nome_contato
    FROM zapi_mensagens m
    JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE m.direcao='enviada' AND m.zapi_message_id IS NOT NULL AND m.zapi_message_id != ''
      AND m.enviado_por_id IS NULL AND (m.enviado_por_bot IS NULL OR m.enviado_por_bot = 0)
    ORDER BY m.id DESC LIMIT 10
")->fetchAll();
foreach ($rows as $r) {
    echo sprintf("  #%s  conv=%s  tel=%-18s  '%s'  em %s\n",
        $r['id'], $r['conversa_id'], $r['telefone'], mb_substr($r['conteudo'], 0, 50), $r['created_at']);
}
