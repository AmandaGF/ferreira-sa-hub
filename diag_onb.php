<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== Tipos de eventos no banco - ultimas 60 dias ==\n";
$st = $pdo->query("SELECT tipo, COUNT(*) qtd FROM agenda_eventos WHERE data_inicio > DATE_SUB(NOW(), INTERVAL 60 DAY) GROUP BY tipo ORDER BY qtd DESC");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  {$r['tipo']}: {$r['qtd']}\n";

echo "\n== ONBOARDING events ultimas 30 dias + futuras ==\n";
$st = $pdo->query("SELECT id, titulo, data_inicio, status, responsavel_id, case_id, client_id FROM agenda_eventos WHERE tipo='onboarding' AND data_inicio > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY data_inicio DESC LIMIT 20");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ev#{$r['id']} data={$r['data_inicio']} status={$r['status']} resp={$r['responsavel_id']} case={$r['case_id']} cli={$r['client_id']}\n";
    echo "    titulo: {$r['titulo']}\n";
}

echo "\n== Onboardings no mes atual junho/2026 ==\n";
$st = $pdo->query("SELECT id, titulo, tipo, data_inicio, status, responsavel_id FROM agenda_eventos WHERE data_inicio BETWEEN '2026-06-01' AND '2026-06-30 23:59:59' AND tipo='onboarding'");
$rs = $st->fetchAll(PDO::FETCH_ASSOC);
echo "  TOTAL: " . count($rs) . "\n";
foreach ($rs as $r) echo "  ev#{$r['id']} {$r['data_inicio']} status={$r['status']} resp={$r['responsavel_id']} | {$r['titulo']}\n";
