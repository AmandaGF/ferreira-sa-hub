<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$st = $pdo->query("SELECT id, name, email FROM users WHERE email IN ('r.almeidagustavo@gmail.com','admin.hub@ferreiraesa.com.br','amandaferreira@ferreiraesa.com.br') ORDER BY id");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "id={$r['id']} name={$r['name']} email={$r['email']}\n";
