<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Leads do Pipeline Comercial associados a Amanda ou fone 24992234554 ===\n\n";
$leads = $pdo->query("SELECT id, name, phone, stage, case_type, client_id
                      FROM pipeline_leads
                      WHERE name LIKE '%Amanda Guedes%' OR phone LIKE '%4992234554%' OR phone LIKE '%24992234554%'
                      ORDER BY id DESC")->fetchAll();
if (empty($leads)) {
    echo "Nenhum lead.\n";
} else {
    foreach ($leads as $l) {
        echo "  Lead #{$l['id']} | {$l['name']} | phone={$l['phone']} | stage={$l['stage']} | case_type={$l['case_type']} | client_id={$l['client_id']}\n";
    }
}

echo "\n=== Casos (tabela cases) associados direto ao client_id 447 ===\n\n";
$c = $pdo->prepare("SELECT id, client_title, case_type, status, drive_folder_url, created_at FROM cases WHERE client_id = ?");
$c->execute(array(447));
$rows = $c->fetchAll();
if (empty($rows)) echo "  Nenhum.\n";
foreach ($rows as $r) echo "  Caso #{$r['id']} | {$r['client_title']} | {$r['case_type']} | {$r['status']} | drive=" . ($r['drive_folder_url'] ? substr($r['drive_folder_url'],0,50) : 'SEM') . " | {$r['created_at']}\n";

echo "\n=== TOP 10 casos mais recentes (qualquer cliente) pra referência ===\n\n";
$rows = $pdo->query("SELECT c.id, c.client_id, c.client_title, c.case_type, c.status, cl.name
                     FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id
                     ORDER BY c.id DESC LIMIT 10")->fetchAll();
foreach ($rows as $r) echo "  Caso #{$r['id']} | cliente={$r['name']} (id={$r['client_id']}) | {$r['client_title']} | {$r['case_type']} | {$r['status']}\n";
