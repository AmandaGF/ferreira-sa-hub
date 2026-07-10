<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$u = $pdo->prepare("UPDATE portal_links SET category = 'Ferramentas Operacionais' WHERE id = 534");
$u->execute();
echo "Movido " . $u->rowCount() . " link(s) pra Ferramentas Operacionais\n";
$r = $pdo->query("SELECT id, category, title FROM portal_links WHERE id = 534")->fetch(PDO::FETCH_ASSOC);
print_r($r);
