<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "=== Backfill salavip_mensagens.cliente_id (respostas da equipe) ===\n\n";
$stCount = $pdo->query("SELECT COUNT(*) FROM salavip_mensagens WHERE cliente_id = 0 AND origem = 'conecta'");
$antes = (int)$stCount->fetchColumn();
echo "Mensagens da equipe com cliente_id=0: $antes\n\n";
$upd = $pdo->prepare(
    "UPDATE salavip_mensagens m
     JOIN salavip_threads t ON t.id = m.thread_id
     SET m.cliente_id = t.cliente_id
     WHERE m.cliente_id = 0 AND m.origem = 'conecta' AND t.cliente_id > 0"
);
$upd->execute();
echo "Atualizadas: " . $upd->rowCount() . "\n";
$stCount2 = $pdo->query("SELECT COUNT(*) FROM salavip_mensagens WHERE cliente_id = 0 AND origem = 'conecta'");
echo "Restantes com cliente_id=0: " . (int)$stCount2->fetchColumn() . "\n";
