<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "Valores de status em uso na tabela cases:\n\n";
$r = $pdo->query("SELECT status, COUNT(*) AS n FROM cases GROUP BY status ORDER BY n DESC")->fetchAll();
foreach ($r as $x) {
    echo str_pad($x['status'] ?: '(vazio)', 35) . ' ' . $x['n'] . "\n";
}
