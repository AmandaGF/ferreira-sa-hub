<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
foreach (array('cases','agenda_eventos','prazos_processuais','clients','users') as $t) {
    $r = $pdo->query("SHOW TABLE STATUS LIKE '$t'")->fetch(PDO::FETCH_ASSOC);
    echo "$t: collation=" . ($r['Collation'] ?? '?') . "\n";
}
