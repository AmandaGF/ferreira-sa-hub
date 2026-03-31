<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

echo "=== Correção Kanban Pasta Apta ===\n\n";

// ANTES
$pdo = db();
echo "ANTES da correção:\n";
$rows = $pdo->query("SELECT coluna_atual, COUNT(*) as qtd FROM kanban_cards WHERE kanban='comercial_cx' GROUP BY coluna_atual ORDER BY qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  {$r['coluna_atual']}: {$r['qtd']}\n"; }

// Executar SQL
$sqlFile = __DIR__ . '/correcao_kanban_pasta_apta.sql';
if (!file_exists($sqlFile)) { die("\nArquivo não encontrado!\n"); }

$sql = file_get_contents($sqlFile);
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$mysqli->set_charset('utf8mb4');
if ($mysqli->connect_error) { die("Erro: " . $mysqli->connect_error . "\n"); }

$ok = 0; $errors = 0;
if ($mysqli->multi_query($sql)) {
    do {
        if ($result = $mysqli->store_result()) { $result->free(); }
        $ok++;
        if ($mysqli->errno) { $errors++; echo "  ERRO: " . $mysqli->error . "\n"; }
    } while ($mysqli->next_result());
}
if ($mysqli->errno) { echo "  ERRO FINAL: " . $mysqli->error . "\n"; $errors++; }
$mysqli->close();

echo "\nQueries: $ok | Erros: $errors\n";

// DEPOIS
$pdo = db();
echo "\nDEPOIS da correção:\n";
$rows = $pdo->query("SELECT coluna_atual, COUNT(*) as qtd FROM kanban_cards WHERE kanban='comercial_cx' GROUP BY coluna_atual ORDER BY qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  {$r['coluna_atual']}: {$r['qtd']}\n"; }

echo "\nPronto!\n";
