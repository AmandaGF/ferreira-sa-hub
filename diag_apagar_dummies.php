<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$ids = array(540, 541, 542, 543, 544);
$inIds = implode(',', $ids);

echo "=== Antes ===\n";
$st = $pdo->query("SELECT id, form_type, client_name FROM form_submissions WHERE id IN ($inIds)");
foreach ($st->fetchAll() as $r) {
    echo "  #{$r['id']} {$r['form_type']} — {$r['client_name']}\n";
}

$pdo->exec("DELETE FROM form_submissions WHERE id IN ($inIds)");

// Apaga o cliente TESTE também (id=269 foi reaproveitado pelos 5 — pode ter sido criado AGORA)
$stCli = $pdo->prepare("SELECT id, name FROM clients WHERE id = 269 AND name LIKE 'TESTE_DIAG%'");
$stCli->execute();
$cli = $stCli->fetch();
if ($cli) {
    $pdo->prepare("DELETE FROM clients WHERE id = 269 AND name LIKE 'TESTE_DIAG%'")->execute();
    echo "\nCliente #269 ({$cli['name']}) apagado.\n";
} else {
    echo "\nCliente #269 NAO era TESTE — preservado.\n";
}

echo "\n=== Depois ===\n";
$rest = $pdo->query("SELECT COUNT(*) FROM form_submissions WHERE id IN ($inIds)")->fetchColumn();
echo "  registros restantes: $rest\n";
