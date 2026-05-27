<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "=== Diag bug Zilma ===\n\n";

// Conta leads totais
$total = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads")->fetchColumn();
echo "Total de leads no pipeline_leads: $total\n";

$distintos = (int)$pdo->query("SELECT COUNT(DISTINCT name) FROM pipeline_leads")->fetchColumn();
echo "Nomes distintos: $distintos\n\n";

// Quantos sao 'Zilma' explicitamente
$zilmas = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE name LIKE '%Zilma%'")->fetchColumn();
echo "Leads com nome contendo 'Zilma': $zilmas\n\n";

// Lista TOP 20 leads do kanban ativo (ordem por updated_at DESC)
echo "Top 20 cards do Kanban Comercial (mesma query do index):\n";
$cards = $pdo->query(
    "SELECT pl.id, pl.name, pl.phone, pl.stage, pl.updated_at, pl.client_id
     FROM pipeline_leads pl
     WHERE pl.stage NOT IN ('finalizado','perdido','arquivado')
     ORDER BY pl.updated_at DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($cards as $c) {
    echo sprintf("  #%-5d  '%s'  phone=%s  stage=%s  cli=%s\n",
        $c['id'], $c['name'], $c['phone'], $c['stage'], $c['client_id']);
}

echo "\n--- Detalhe da Zilma especificamente ---\n";
$z = $pdo->query("SELECT * FROM pipeline_leads WHERE name LIKE '%Zilma%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($z as $row) {
    echo "Lead #" . $row['id'] . ":\n";
    foreach ($row as $k => $v) echo "  $k = $v\n";
    echo "\n";
}

echo "--- Lead #1 (primeiro do banco) ---\n";
$first = $pdo->query("SELECT id, name FROM pipeline_leads ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "  ID=" . $first['id'] . "  name='" . $first['name'] . "'\n";

echo "\n--- Zilma em clients ---\n";
$cliZ = $pdo->query("SELECT id, name, phone, cpf FROM clients WHERE name LIKE '%Zilma%' OR name LIKE '%ZILMA%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cliZ as $c) echo "  client#{$c['id']}  '{$c['name']}'  phone={$c['phone']}  cpf={$c['cpf']}\n";
if (!$cliZ) echo "  (nenhum)\n";

echo "\n--- Zilma em cases ---\n";
$cZ = $pdo->query("SELECT id, title, client_id, status FROM cases WHERE title LIKE '%Zilma%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cZ as $c) echo "  case#{$c['id']}  '{$c['title']}'  cli={$c['client_id']}  status={$c['status']}\n";
if (!$cZ) echo "  (nenhum)\n";

echo "\n--- Lead com client_id que aponta pra Zilma cliente ---\n";
if ($cliZ) {
    foreach ($cliZ as $cz) {
        $ls = $pdo->prepare("SELECT id, name, stage FROM pipeline_leads WHERE client_id = ?");
        $ls->execute(array($cz['id']));
        foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
            echo "  lead#{$l['id']}  '{$l['name']}'  stage={$l['stage']}  (client_id={$cz['id']} = {$cz['name']})\n";
        }
    }
}
