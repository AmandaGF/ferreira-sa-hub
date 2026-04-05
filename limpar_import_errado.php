<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Limpando importações erradas ===\n\n";

// Limpar TODAS as importações (texto PDF e TJRJ)
$stmt = $pdo->query("SELECT COUNT(*) FROM prazos_suspensoes WHERE fonte_pdf IS NOT NULL");
$count = (int)$stmt->fetchColumn();
echo "Registros importados: $count\n";

if ($count > 0) {
    $pdo->exec("DELETE FROM prazos_suspensoes WHERE fonte_pdf IS NOT NULL");
    echo "[OK] $count registros removidos\n";
}

echo "\n--- Registros restantes ---\n";
$rows = $pdo->query("SELECT id, data_inicio, data_fim, motivo, tipo, abrangencia, comarca FROM prazos_suspensoes ORDER BY data_inicio")->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "#{$r['id']} | {$r['data_inicio']} a {$r['data_fim']} | {$r['motivo']} | {$r['tipo']} | {$r['abrangencia']}" . ($r['comarca'] ? " | {$r['comarca']}" : '') . "\n";
}

echo "\n=== FIM ===\n";
