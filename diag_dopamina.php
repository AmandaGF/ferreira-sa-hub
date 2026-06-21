<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
foreach (array('prazos_processuais','tickets') as $t) {
    echo "=== $t ===\n";
    try { foreach ($pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_ASSOC) as $c) echo "  {$c['Field']} ({$c['Type']})\n"; } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
    echo "\n";
}
foreach (array(
    'agenda_eventos'=>'status',
    'case_tasks'=>'status',
    'tickets'=>'status',
    'prazos_processuais'=>'concluido',
) as $tab=>$col) {
    echo "--- $tab.$col distinct ---\n";
    try { foreach ($pdo->query("SELECT $col, COUNT(*) q FROM $tab GROUP BY $col ORDER BY q DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  ".($r[$col]===null?'NULL':$r[$col])." ({$r['q']})\n"; } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
    echo "\n";
}
echo "=== FIM ===\n";
