<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

echo "=== Correção Kanban v2 ===\n\n";

$pdo = db();
echo "ANTES:\n";
$rows = $pdo->query("SELECT coluna_atual, COUNT(*) as qtd FROM kanban_cards WHERE kanban='comercial_cx' GROUP BY coluna_atual ORDER BY qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  {$r['coluna_atual']}: {$r['qtd']}\n"; }

$sqlFile = __DIR__ . '/correcao_kanban_v2.sql';
$sql = file_get_contents($sqlFile);
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$mysqli->set_charset('utf8mb4');

$ok = 0; $errors = 0;
if ($mysqli->multi_query($sql)) {
    do {
        if ($result = $mysqli->store_result()) { $result->free(); }
        $ok++;
        if ($mysqli->errno) { $errors++; if ($errors <= 5) echo "  ERRO: " . $mysqli->error . "\n"; }
    } while ($mysqli->next_result());
}
if ($mysqli->errno) { echo "  ERRO FINAL: " . $mysqli->error . "\n"; $errors++; }
$mysqli->close();

echo "\nQueries: $ok | Erros: $errors\n";

$pdo = db();
echo "\nDEPOIS:\n";
$rows = $pdo->query("SELECT coluna_atual, COUNT(*) as qtd FROM kanban_cards WHERE kanban='comercial_cx' GROUP BY coluna_atual ORDER BY qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  {$r['coluna_atual']}: {$r['qtd']}\n"; }

echo "\nPronto!\n";
