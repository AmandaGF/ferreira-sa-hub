<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$r = $pdo->query("SHOW COLUMNS FROM agenda_eventos")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r as $c) {
    if (in_array($c['Field'], array('data_inicio','data_fim','hora_inicio','hora_fim','dia_todo'), true)) {
        echo $c['Field'] . " => " . $c['Type'] . "  (" . $c['Null'] . ", default=" . ($c['Default'] ?? 'NULL') . ")\n";
    }
}

echo "\n--- Audiencias do ALLAN ---\n";
$st = $pdo->query("SELECT id, titulo, tipo, data_inicio, data_fim, hora_inicio, hora_fim, dia_todo, status FROM agenda_eventos WHERE titulo LIKE '%ALLAN%' ORDER BY id DESC LIMIT 5");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    echo "  #{$ev['id']}  {$ev['titulo']}\n";
    foreach ($ev as $k => $v) {
        if ($k === 'id' || $k === 'titulo') continue;
        echo "      $k = " . ($v === null ? 'NULL' : $v) . "\n";
    }
}
