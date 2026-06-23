<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "=== cases columns ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM cases")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo $c['Field'] . " | " . $c['Type'] . "\n";
}
