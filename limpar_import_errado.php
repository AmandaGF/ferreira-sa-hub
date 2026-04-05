<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Limpando importações erradas ===\n\n";

// Limpar TUDO e manter só os feriados base
$total = (int)$pdo->query("SELECT COUNT(*) FROM prazos_suspensoes")->fetchColumn();
echo "Total de registros: $total\n\n";

// Listar todos para diagnóstico
$all = $pdo->query("SELECT id, data_inicio, data_fim, motivo, tipo, abrangencia, comarca, fonte_pdf FROM prazos_suspensoes ORDER BY data_inicio")->fetchAll();
foreach ($all as $r) {
    echo "#{$r['id']} | {$r['data_inicio']} a {$r['data_fim']} | {$r['tipo']} | " . mb_substr($r['motivo'],0,50) . " | fonte: " . ($r['fonte_pdf'] ?: 'manual') . ($r['comarca'] ? " | {$r['comarca']}" : '') . "\n";
}

// Apagar TODOS exceto os 15 feriados base (criado_por IS NULL = migração inicial)
echo "\n--- Limpando tudo exceto feriados base ---\n";
$pdo->exec("DELETE FROM prazos_suspensoes WHERE criado_por IS NOT NULL");
$restante = (int)$pdo->query("SELECT COUNT(*) FROM prazos_suspensoes")->fetchColumn();
echo "Restantes após limpeza: $restante\n";

if ($restante === 0) {
    echo "Nenhum restante - limpando tudo\n";
    $pdo->exec("DELETE FROM prazos_suspensoes");
}

echo "\n--- Registros restantes ---\n";
$rows = $pdo->query("SELECT id, data_inicio, data_fim, motivo, tipo, abrangencia, comarca FROM prazos_suspensoes ORDER BY data_inicio")->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "#{$r['id']} | {$r['data_inicio']} a {$r['data_fim']} | {$r['motivo']} | {$r['tipo']} | {$r['abrangencia']}" . ($r['comarca'] ? " | {$r['comarca']}" : '') . "\n";
}

echo "\n=== FIM ===\n";
