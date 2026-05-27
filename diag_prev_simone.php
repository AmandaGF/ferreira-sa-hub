<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$simoneId = 5;

echo "=== Cards PREV que a Simone vera (responsable_user_id = $simoneId) ===\n\n";

$st = $pdo->prepare(
    "SELECT cs.id, cs.title, cs.prev_status, cs.case_number, cs.prev_tipo_beneficio,
            cl.name AS client_name
     FROM cases cs
     LEFT JOIN clients cl ON cl.id = cs.client_id
     WHERE cs.kanban_prev = 1
       AND cs.responsible_user_id = ?
       AND cs.status NOT IN ('concluido','arquivado')
       AND IFNULL(cs.kanban_oculto, 0) = 0
     ORDER BY cs.prev_status, cs.title"
);
$st->execute(array($simoneId));
$cards = $st->fetchAll(PDO::FETCH_ASSOC);

echo "Total: " . count($cards) . " cards\n\n";

$porStatus = array();
foreach ($cards as $c) {
    $s = $c['prev_status'] ?: 'aguardando_docs';
    if (!isset($porStatus[$s])) $porStatus[$s] = array();
    $porStatus[$s][] = $c;
}

foreach ($porStatus as $st => $list) {
    echo "[" . $st . "] " . count($list) . " card(s):\n";
    foreach ($list as $c) {
        echo "  #{$c['id']}  {$c['title']}";
        if ($c['client_name']) echo "  (cli: {$c['client_name']})";
        if ($c['prev_tipo_beneficio']) echo "  [" . $c['prev_tipo_beneficio'] . "]";
        echo "\n";
    }
    echo "\n";
}
