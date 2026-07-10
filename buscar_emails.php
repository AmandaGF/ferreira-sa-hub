<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$stmt = $pdo->query("SELECT id, name, email, role FROM users WHERE (name LIKE '%Luiz%' OR name LIKE '%Carina%' OR name LIKE '%Karina%') AND is_active=1 ORDER BY name");
foreach ($stmt as $r) echo "$r[id] | $r[name] | $r[email] | $r[role]\n";
