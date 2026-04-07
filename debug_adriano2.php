<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$r = $pdo->query("SELECT id, title, status, case_number, court, comarca, kanban_oculto, distribution_date, case_type FROM cases WHERE id = 669")->fetch();
foreach ($r as $k => $v) {
    if (!is_numeric($k)) echo "$k = " . ($v === null ? 'NULL' : ($v === '' ? '(vazio)' : $v)) . "\n";
}
