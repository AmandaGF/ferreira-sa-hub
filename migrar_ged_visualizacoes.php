<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== MIGRAÇÃO: Rastreamento de visualizações GED ===\n\n";

$cols = array(
    'total_visualizacoes' => 'ADD COLUMN total_visualizacoes INT DEFAULT 0',
    'primeira_visualizacao' => 'ADD COLUMN primeira_visualizacao DATETIME DEFAULT NULL',
    'ultima_visualizacao' => 'ADD COLUMN ultima_visualizacao DATETIME DEFAULT NULL',
);

foreach ($cols as $col => $sql) {
    $chk = $pdo->query("SHOW COLUMNS FROM salavip_ged LIKE '$col'");
    if ($chk->fetch()) { echo "[JÁ EXISTE] salavip_ged.$col\n"; continue; }
    try { $pdo->exec("ALTER TABLE salavip_ged $sql"); echo "[OK] salavip_ged.$col\n"; }
    catch (Exception $e) { echo "[ERRO] $col: " . $e->getMessage() . "\n"; }
}

echo "\n=== CONCLUÍDO ===\n";
