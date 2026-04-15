<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== FIX: Marcar eventos como visíveis ao cliente ===\n\n";

// Tipos que devem ser visíveis na Sala VIP
$tipos = array('audiencia', 'reuniao_cliente', 'onboard', 'balcao_virtual');
$placeholders = implode(',', array_fill(0, count($tipos), '?'));

$stmt = $pdo->prepare("UPDATE agenda_eventos SET visivel_cliente = 1 WHERE tipo IN ($placeholders) AND visivel_cliente = 0");
$stmt->execute($tipos);
$affected = $stmt->rowCount();

echo "Eventos atualizados: $affected\n";

// Verificar resultado
$total = $pdo->query("SELECT COUNT(*) FROM agenda_eventos WHERE visivel_cliente = 1")->fetchColumn();
echo "Total de eventos visíveis agora: $total\n";

echo "\n=== CONCLUÍDO ===\n";
