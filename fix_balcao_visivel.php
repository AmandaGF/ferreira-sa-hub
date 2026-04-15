<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== FIX: Ocultar balcão virtual da Sala VIP ===\n\n";

$stmt = $pdo->prepare("UPDATE agenda_eventos SET visivel_cliente = 0 WHERE tipo = 'balcao_virtual' AND visivel_cliente = 1");
$stmt->execute();
$affected = $stmt->rowCount();

echo "Eventos balcão virtual ocultados: $affected\n";

$total = $pdo->query("SELECT COUNT(*) FROM agenda_eventos WHERE visivel_cliente = 1")->fetchColumn();
echo "Total de eventos visíveis agora: $total\n";

echo "\n=== CONCLUÍDO ===\n";
