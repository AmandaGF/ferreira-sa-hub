<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$tables = array('clients', 'pipeline_leads', 'cases');
foreach ($tables as $t) {
    echo "=== $t ===\n";
    $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
    echo $row['Create Table'] . "\n\n";
}

// Contagens atuais
echo "=== CONTAGENS ATUAIS ===\n";
echo "clients: " . $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn() . "\n";
echo "pipeline_leads: " . $pdo->query("SELECT COUNT(*) FROM pipeline_leads")->fetchColumn() . "\n";
echo "cases: " . $pdo->query("SELECT COUNT(*) FROM cases")->fetchColumn() . "\n";
echo "\nclientes (importados): " . $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn() . "\n";
echo "processos (importados): " . $pdo->query("SELECT COUNT(*) FROM processos")->fetchColumn() . "\n";
echo "kanban_cards (importados): " . $pdo->query("SELECT COUNT(*) FROM kanban_cards")->fetchColumn() . "\n";

// Kanban cards por coluna e kanban
echo "\n=== KANBAN CARDS POR COLUNA ===\n";
$rows = $pdo->query("SELECT kanban, coluna_atual, COUNT(*) as qtd FROM kanban_cards GROUP BY kanban, coluna_atual ORDER BY kanban, qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  [{$r['kanban']}] {$r['coluna_atual']}: {$r['qtd']}\n"; }

// Pipeline_leads stages
echo "\n=== PIPELINE_LEADS STAGES ===\n";
$rows = $pdo->query("SELECT stage, COUNT(*) as qtd FROM pipeline_leads GROUP BY stage ORDER BY qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  {$r['stage']}: {$r['qtd']}\n"; }

// Cases status
echo "\n=== CASES STATUS ===\n";
$rows = $pdo->query("SELECT status, COUNT(*) as qtd FROM cases GROUP BY status ORDER BY qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  {$r['status']}: {$r['qtd']}\n"; }
