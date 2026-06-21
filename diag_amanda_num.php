<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php';
$pdo=db();
$uid=1; // Amanda
echo "=== AGENDA: antigo (responsavel) x novo (audit) — uid=$uid, 7 dias ===\n";
for($i=6;$i>=0;$i--){ $d=date('Y-m-d',strtotime("-$i day"));
  $old=(int)$pdo->query("SELECT COUNT(*) FROM agenda_eventos WHERE status='realizado' AND responsavel_id=$uid AND DATE(updated_at)='$d'")->fetchColumn();
  $newAll=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='agenda' AND user_id=$uid AND DATE(created_at)='$d' AND (action='AGENDA_BALCAO_REALIZADO' OR (action='AGENDA_STATUS' AND details LIKE 'Status: realizado%'))")->fetchColumn();
  $newDist=(int)$pdo->query("SELECT COUNT(DISTINCT entity_id) FROM audit_log WHERE entity_type='agenda' AND user_id=$uid AND DATE(created_at)='$d' AND (action='AGENDA_BALCAO_REALIZADO' OR (action='AGENDA_STATUS' AND details LIKE 'Status: realizado%'))")->fetchColumn();
  echo "  $d  antigo=$old  novo(count)=$newAll  novo(distinct)=$newDist\n";
}
echo "\n=== TOTAL geral hoje (uid=$uid) por categoria ===\n";
$h=date('Y-m-d');
echo "  tarefas=".(int)$pdo->query("SELECT COUNT(*) FROM case_tasks WHERE status='concluido' AND assigned_to=$uid AND DATE(completed_at)='$h'")->fetchColumn()."\n";
echo "  prazos=".(int)$pdo->query("SELECT COUNT(*) FROM prazos_processuais WHERE concluido=1 AND usuario_id=$uid AND DATE(concluido_em)='$h'")->fetchColumn()."\n";
echo "  distribuicoes=".(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='processo_distribuido' AND entity_type='case' AND user_id=$uid AND DATE(created_at)='$h'")->fetchColumn()."\n";
