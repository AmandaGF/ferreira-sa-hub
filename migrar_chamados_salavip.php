<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$sqls = array(
    "ALTER TABLE tickets ADD COLUMN origem VARCHAR(20) DEFAULT 'conecta'",
    "ALTER TABLE tickets ADD INDEX idx_origem (origem)",
);
foreach ($sqls as $sql) {
    try { $pdo->exec($sql); echo "OK: " . substr($sql, 0, 60) . "\n"; }
    catch (Exception $e) { echo "SKIP: " . $e->getMessage() . "\n"; }
}
echo "\n=== MIGRAÇÃO CONCLUÍDA ===\n";
