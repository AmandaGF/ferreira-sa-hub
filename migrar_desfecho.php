<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migrar: cases.desfecho_processo ===\n\n";
$cols = $pdo->query("SHOW COLUMNS FROM cases")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('desfecho_processo', $cols, true)) {
    $pdo->exec("ALTER TABLE cases ADD COLUMN desfecho_processo VARCHAR(40) DEFAULT NULL AFTER status");
    echo "✓ Coluna desfecho_processo criada\n";
} else {
    echo "= Coluna já existe\n";
}
if (!in_array('desfecho_processo_em', $cols, true)) {
    $pdo->exec("ALTER TABLE cases ADD COLUMN desfecho_processo_em DATE DEFAULT NULL AFTER desfecho_processo");
    echo "✓ Coluna desfecho_processo_em criada\n";
}
echo "\n=== FIM ===\n";
