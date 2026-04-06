<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAGNOSTICO COMENTARIOS SEM CASE_ID ===\n\n";

$stmt = $pdo->query("SELECT cc.id, cc.client_id, cc.case_id, cc.lead_id, cc.message, cc.created_at, u.name as user_name, c.name as client_name
FROM card_comments cc
LEFT JOIN users u ON u.id = cc.user_id
LEFT JOIN clients c ON c.id = cc.client_id
WHERE cc.case_id IS NULL OR cc.case_id = 0
ORDER BY cc.created_at DESC");

$rows = $stmt->fetchAll();
echo "Total sem case_id: " . count($rows) . "\n\n";

foreach ($rows as $r) {
    echo "#{$r['id']} | Cliente: {$r['client_name']} (#{$r['client_id']}) | Lead: " . ($r['lead_id'] ?: 'NULL') . " | {$r['user_name']} | " . date('d/m/Y H:i', strtotime($r['created_at'])) . "\n";
    echo "  Msg: " . mb_substr($r['message'], 0, 100) . "\n\n";
}

echo "\n=== TOTAL DE COMENTARIOS ===\n";
$total = (int)$pdo->query("SELECT COUNT(*) FROM card_comments")->fetchColumn();
$comCase = (int)$pdo->query("SELECT COUNT(*) FROM card_comments WHERE case_id IS NOT NULL AND case_id > 0")->fetchColumn();
$semCase = (int)$pdo->query("SELECT COUNT(*) FROM card_comments WHERE case_id IS NULL OR case_id = 0")->fetchColumn();
echo "Total: $total | Com case_id: $comCase | Sem case_id: $semCase\n";
