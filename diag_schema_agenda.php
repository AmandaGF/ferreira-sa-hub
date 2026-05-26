<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Schema agenda_eventos ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM agenda_eventos")->fetchAll();
foreach ($cols as $c) {
    echo str_pad($c['Field'], 28, ' ') . $c['Type'] . ($c['Null'] === 'NO' ? ' NOT NULL' : '') . "\n";
}
