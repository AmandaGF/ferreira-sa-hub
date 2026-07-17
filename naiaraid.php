<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
foreach ($pdo->query("SELECT id, name, role, is_active FROM users WHERE name LIKE '%aiara%' OR name LIKE '%Naiara%'") as $r) {
    printf("  #%d %-30s role=%-12s ativo=%d\n", $r['id'], $r['name'], $r['role'], $r['is_active']);
}
