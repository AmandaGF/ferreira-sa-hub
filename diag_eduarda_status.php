<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Conv 648 (original) ===\n";
$q = $pdo->prepare("SELECT id, canal, telefone, chat_lid, client_id, status FROM zapi_conversas WHERE id = 648");
$q->execute();
print_r($q->fetch());

echo "\n=== Todas conversas vinculadas a client=652 (Eduarda) ===\n";
$q = $pdo->prepare("SELECT id, canal, telefone, chat_lid, client_id, status, created_at FROM zapi_conversas WHERE client_id = 652");
$q->execute();
foreach ($q->fetchAll() as $r) print_r($r);

echo "\n=== Conversas canal 24 com telefone 5521973698089 ===\n";
$q = $pdo->prepare("SELECT id, canal, telefone, chat_lid, client_id, status FROM zapi_conversas WHERE telefone = ? AND canal = '24'");
$q->execute(array('5521973698089'));
foreach ($q->fetchAll() as $r) print_r($r);
