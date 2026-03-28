<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

$pdo = db();
$leads = $pdo->query("SELECT id, name, phone, stage, client_id, created_at FROM pipeline_leads ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

echo "=== Leads no Pipeline ===\n\n";
echo "Total: " . count($leads) . "\n\n";
foreach ($leads as $l) {
    echo "#" . $l['id'] . " | " . $l['name'] . " | stage: [" . $l['stage'] . "] | phone: " . $l['phone'] . " | client: " . $l['client_id'] . " | " . $l['created_at'] . "\n";
}
