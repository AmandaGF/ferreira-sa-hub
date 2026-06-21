<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php';
$pdo=db();
echo "ANDAMENTO_CRIADO ultimos 7 dias por user:\n";
foreach($pdo->query("SELECT user_id, COUNT(*) c FROM audit_log WHERE action='ANDAMENTO_CRIADO' AND entity_type='case' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY user_id ORDER BY c DESC")->fetchAll() as $r) echo "  uid={$r['user_id']}: {$r['c']}\n";
