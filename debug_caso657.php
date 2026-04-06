<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Caso 657 ===\n";
$r = $pdo->query("SELECT id, title, case_number, court, comarca, comarca_uf, regional, sistema_tribunal, segredo_justica, distribution_date, status FROM cases WHERE id = 657")->fetch();
foreach ($r as $k => $v) { if (!is_numeric($k)) echo "$k = " . ($v === null ? 'NULL' : ($v === '' ? '(vazio)' : $v)) . "\n"; }

echo "\n=== Caso 658 ===\n";
$r = $pdo->query("SELECT id, title, case_number, court, comarca, comarca_uf, regional, sistema_tribunal, segredo_justica, distribution_date, status FROM cases WHERE id = 658")->fetch();
foreach ($r as $k => $v) { if (!is_numeric($k)) echo "$k = " . ($v === null ? 'NULL' : ($v === '' ? '(vazio)' : $v)) . "\n"; }

echo "\n=== Leads do Jhonatan ===\n";
$leads = $pdo->query("SELECT pl.id, pl.name, pl.stage, pl.linked_case_id, pl.client_id FROM pipeline_leads pl WHERE pl.client_id = (SELECT client_id FROM cases WHERE id = 675)")->fetchAll();
foreach ($leads as $l) { echo "Lead #{$l['id']} stage={$l['stage']} linked_case={$l['linked_case_id']}\n"; }
