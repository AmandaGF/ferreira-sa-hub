<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php';
$pdo=db();
echo "Atendimentos (conversas com msg enviada) ultimos 7 dias, por user/canal:\n";
foreach($pdo->query("SELECT m.enviado_por_id uid, co.canal, COUNT(DISTINCT m.conversa_id) c FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id WHERE m.enviado_por_id IS NOT NULL AND m.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY m.enviado_por_id, co.canal ORDER BY c DESC LIMIT 15")->fetchAll() as $r) echo "  uid={$r['uid']} canal={$r['canal']}: {$r['c']}\n";
