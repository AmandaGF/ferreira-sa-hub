<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
echo "== Audiencia 416 + lembrete vinculado ==\n";
$st = $pdo->query("SELECT id, titulo, tipo, modalidade, data_inicio, status, referencia_evento_id FROM agenda_eventos WHERE id = 416 OR referencia_evento_id = 416 ORDER BY id");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  #{$r['id']} tipo={$r['tipo']} mod={$r['modalidade']} status={$r['status']} data={$r['data_inicio']} ref={$r['referencia_evento_id']}\n    {$r['titulo']}\n";
}
echo "\n== Audiencia 417 (deve estar apagada) ==\n";
$st = $pdo->query("SELECT id FROM agenda_eventos WHERE id = 417");
$r = $st->fetchAll();
echo "  " . (count($r) ? 'AINDA EXISTE - bug' : 'apagada OK') . "\n";
