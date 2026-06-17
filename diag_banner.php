<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "Data servidor: " . date('Y-m-d H:i:s') . "\n\n";

echo "=== Contadores do banner ===\n";
$f = function($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };

$vTab = $f("SELECT COUNT(*) FROM prazos_processuais WHERE concluido = 0 AND prazo_fatal < CURDATE()");
$hTab = $f("SELECT COUNT(*) FROM prazos_processuais WHERE concluido = 0 AND prazo_fatal = CURDATE()");
$vAg  = $f("SELECT COUNT(*) FROM agenda_eventos WHERE tipo='prazo' AND status NOT IN ('cancelado','realizado','concluido') AND DATE(data_inicio) < CURDATE()");
$hAg  = $f("SELECT COUNT(*) FROM agenda_eventos WHERE tipo='prazo' AND status NOT IN ('cancelado','realizado','concluido') AND DATE(data_inicio) = CURDATE()");

echo "prazos_processuais VENCIDOS: $vTab\n";
echo "prazos_processuais HOJE:     $hTab\n";
echo "agenda_eventos    VENCIDOS:  $vAg\n";
echo "agenda_eventos    HOJE:      $hAg\n";
echo "\nTOTAL vencidos: " . ($vTab+$vAg) . "\n";
echo "TOTAL hoje:     " . ($hTab+$hAg) . "\n";
echo "\nBanner deveria " . ((($vTab+$vAg) > 0 || ($hTab+$hAg) > 0) ? "APARECER ✓" : "NAO aparecer (zero)") . "\n";

echo "\n=== Quais sao os prazos da agenda 'HOJE' agendados? ===\n";
$st = $pdo->query("SELECT id, titulo, data_inicio, status, tipo
                   FROM agenda_eventos
                   WHERE tipo='prazo' AND DATE(data_inicio) = CURDATE()
                   ORDER BY status, data_inicio");
foreach ($st as $r) {
    echo "  #{$r['id']} | status={$r['status']} | {$r['data_inicio']} | \"{$r['titulo']}\"\n";
}

echo "\n=== Quantos prazos VENCIDOS distintos por dia (últimos 30d) ===\n";
$st = $pdo->query("SELECT DATE(data_inicio) AS dia, status, COUNT(*) AS n
                   FROM agenda_eventos
                   WHERE tipo='prazo' AND DATE(data_inicio) BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()
                   GROUP BY dia, status
                   ORDER BY dia DESC, status");
foreach ($st as $r) echo "  {$r['dia']} | status={$r['status']} | $r[n]\n";
