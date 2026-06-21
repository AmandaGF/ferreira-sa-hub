<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php';
$pdo=db();
foreach(array('2026-06-18','2026-06-19') as $D){
echo "=== Eventos responsavel=1, realizado, updated_at=$D ===\n";
$rows=$pdo->query("SELECT id, tipo, LEFT(titulo,28) titulo, DATE(data_inicio) di, created_at, updated_at FROM agenda_eventos WHERE status='realizado' AND responsavel_id=1 AND DATE(updated_at)='$D' ORDER BY id")->fetchAll();
foreach($rows as $r){
  // ultima baixa auditada desse evento (qualquer user/data)
  $a=$pdo->query("SELECT user_id, DATE(created_at) d FROM audit_log WHERE entity_type='agenda' AND entity_id={$r['id']} AND (action='AGENDA_BALCAO_REALIZADO' OR (action='AGENDA_STATUS' AND details LIKE 'Status: realizado%')) ORDER BY id DESC LIMIT 1")->fetch();
  $baixa = $a ? ("baixa por uid={$a['user_id']} em {$a['d']}") : "SEM audit de baixa";
  echo "  #{$r['id']} {$r['tipo']} dt_ev={$r['di']} criado=".substr($r['created_at'],0,10)." upd=".substr($r['updated_at'],0,16)." | $baixa\n";
}
echo "\n";
}
