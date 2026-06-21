<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php';
$pdo=db();
echo "=== audit_log agenda baixas (ultimos 7 dias) por user ===\n";
foreach($pdo->query("SELECT user_id, DATE(created_at) d, COUNT(*) c FROM audit_log WHERE entity_type='agenda' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND (action='AGENDA_BALCAO_REALIZADO' OR (action='AGENDA_STATUS' AND details LIKE 'Status: realizado%')) GROUP BY user_id, DATE(created_at) ORDER BY d DESC, c DESC")->fetchAll() as $r) echo "  uid={$r['user_id']} {$r['d']}: {$r['c']}\n";
echo "\n=== comparativo HOJE: metodo antigo (responsavel_id) vs novo (audit) ===\n";
$h=date('Y-m-d');
foreach($pdo->query("SELECT responsavel_id uid, COUNT(*) c FROM agenda_eventos WHERE status='realizado' AND DATE(updated_at)='$h' GROUP BY responsavel_id")->fetchAll() as $r) echo "  ANTIGO uid={$r['uid']}: {$r['c']}\n";
foreach($pdo->query("SELECT user_id uid, COUNT(*) c FROM audit_log WHERE entity_type='agenda' AND DATE(created_at)='$h' AND (action='AGENDA_BALCAO_REALIZADO' OR (action='AGENDA_STATUS' AND details LIKE 'Status: realizado%')) GROUP BY user_id")->fetchAll() as $r) echo "  NOVO  uid={$r['uid']}: {$r['c']}\n";
echo "\n=== amostra details AGENDA_STATUS ===\n";
foreach($pdo->query("SELECT DISTINCT LEFT(details,40) x FROM audit_log WHERE action='AGENDA_STATUS' ORDER BY id DESC LIMIT 6")->fetchAll() as $r) echo "  {$r['x']}\n";
