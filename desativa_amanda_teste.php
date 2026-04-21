<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$r = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = 10 AND name = 'Amanda Teste'");
$r->execute();
echo "Linhas afetadas: " . $r->rowCount() . "\n";

$chk = $pdo->query("SELECT id, name, is_active FROM users WHERE id = 10")->fetch();
echo "Estado atual: #" . $chk['id'] . " [" . $chk['name'] . "] is_active=" . $chk['is_active'] . "\n";
