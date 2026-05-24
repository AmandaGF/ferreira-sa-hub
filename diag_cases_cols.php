<?php
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') exit('Negado');
header('Content-Type: text/plain');
$pdo = db();
$cols = $pdo->query("SHOW COLUMNS FROM cases")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    if (preg_match('/administr|natureza|esfera|tipo_dem|judicial/i', $c['Field'])) {
        echo "  ⭐ " . $c['Field'] . " (" . $c['Type'] . ")\n";
    }
}
echo "---\nTotal cols cases: " . count($cols) . "\n";
