<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pendentes = array('aguardando_docs','em_elaboracao','para_execucao_ia','em_andamento','doc_faltante','aguardando_prazo');
$in = implode(',', array_fill(0, count($pendentes), '?'));
$sql = "SELECT status, COUNT(*) c FROM cases WHERE status IN ($in) AND (case_number IS NULL OR case_number='') AND COALESCE(kanban_oculto,0)=0 GROUP BY status";
$st = $pdo->prepare($sql); $st->execute($pendentes);
$tot = 0; echo "Contagem por status:\n";
foreach ($st as $r) { echo "  {$r['status']}: {$r['c']}\n"; $tot += (int)$r['c']; }
echo "TOTAL: $tot\n";
$st = $pdo->prepare("SELECT COUNT(DISTINCT client_id) FROM cases WHERE status IN ($in) AND (case_number IS NULL OR case_number='') AND COALESCE(kanban_oculto,0)=0");
$st->execute($pendentes);
echo "Clientes distintos: " . $st->fetchColumn() . "\n";
