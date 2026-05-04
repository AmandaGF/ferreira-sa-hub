<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$pdo->prepare("DELETE FROM form_submissions WHERE protocol = 'DSP-AC1540D361'")->execute();
echo "DSP-AC1540D361 apagado.\n";
echo "TESTE_DIAG remanescente: " . (int)$pdo->query("SELECT COUNT(*) FROM form_submissions WHERE client_name LIKE 'TESTE_DIAG%'")->fetchColumn() . "\n";
