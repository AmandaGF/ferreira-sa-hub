<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
require_once dirname(__DIR__) . '/salavip/config.php';
$pdo = sv_db();

foreach (array('tickets','salavip_threads','case_partes','case_andamentos','documentos_pendentes','agenda_eventos') as $t) {
    echo "=== $t ===\n";
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM $t LIKE '%case%'") as $r) echo "  " . $r['Field'] . "\n";
        foreach ($pdo->query("SHOW COLUMNS FROM $t LIKE '%processo%'") as $r) echo "  " . $r['Field'] . "\n";
    } catch (Throwable $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }
    echo "\n";
}
