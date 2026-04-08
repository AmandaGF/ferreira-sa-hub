<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Corrigir andamentos do Helpdesk para visivel_cliente=0 ===\n\n";

$stmt = $pdo->prepare("UPDATE case_andamentos SET visivel_cliente = 0 WHERE tipo = 'chamado'");
$stmt->execute();
$affected = $stmt->rowCount();

echo "Andamentos atualizados: $affected\n";
echo "\nFIM\n";
