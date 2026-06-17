<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "Data servidor: " . date('Y-m-d H:i:s') . "\n\n";

echo "=== Colunas reais de prazos_processuais ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM prazos_processuais") as $col) {
    echo "  {$col['Field']} ({$col['Type']})\n";
}

echo "\n=== Prazos ATIVOS (concluido=0) próximos 14 dias ===\n";
$st = $pdo->query("SELECT id, case_id, prazo_fatal, concluido, descricao_acao
                   FROM prazos_processuais
                   WHERE concluido = 0 AND prazo_fatal BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                   ORDER BY prazo_fatal ASC");
foreach ($st as $r) {
    $diff = (strtotime($r['prazo_fatal']) - strtotime(date('Y-m-d')))/86400;
    $marca = $diff < 0 ? '⚠️ VENCIDO há ' . abs((int)$diff) . 'd' : ($diff == 0 ? '🔥 HOJE' : '✓ em ' . (int)$diff . 'd');
    echo "  #{$r['id']} | prazo_fatal={$r['prazo_fatal']} | $marca | case #{$r['case_id']}\n";
    echo "    \"" . mb_substr($r['descricao_acao'], 0, 80, 'UTF-8') . "\"\n";
}

echo "\n=== Total na query do dashboard ===\n";
$n = (int)$pdo->query("SELECT COUNT(*) FROM prazos_processuais WHERE concluido = 0 AND prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
echo "  $n prazos próximos 7 dias (incluindo vencidos não concluídos)\n";

echo "\n=== Prazos com status 'em andamento' ou similar mas concluido=1? ===\n";
$tot = (int)$pdo->query("SELECT COUNT(*) FROM prazos_processuais")->fetchColumn();
$concl = (int)$pdo->query("SELECT COUNT(*) FROM prazos_processuais WHERE concluido = 1")->fetchColumn();
$naoConcl = (int)$pdo->query("SELECT COUNT(*) FROM prazos_processuais WHERE concluido = 0")->fetchColumn();
echo "  Total: $tot | concluído=1: $concl | concluído=0: $naoConcl\n";

echo "\n=== Agenda de hoje (tipo=prazo) ===\n";
$st = $pdo->query("SELECT id, titulo, tipo, data_inicio, dia_todo, status
                   FROM agenda_eventos
                   WHERE DATE(data_inicio) = CURDATE() AND tipo = 'prazo'
                   ORDER BY data_inicio");
foreach ($st as $r) echo "  #{$r['id']} | {$r['data_inicio']} | dia_todo={$r['dia_todo']} | status={$r['status']} | \"{$r['titulo']}\"\n";

echo "\n=== Agenda 'prazo' próximos 7 dias ===\n";
$st = $pdo->query("SELECT id, titulo, data_inicio, status
                   FROM agenda_eventos
                   WHERE tipo = 'prazo' AND DATE(data_inicio) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                     AND status != 'cancelado'
                   ORDER BY data_inicio");
foreach ($st as $r) echo "  #{$r['id']} | {$r['data_inicio']} | status={$r['status']} | \"{$r['titulo']}\"\n";
