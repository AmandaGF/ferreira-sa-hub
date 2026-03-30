<?php
/**
 * Limpa tabelas parciais e executa migracao_ferreiraesa_v3.sql via mysqli_multi_query
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

echo "=== Migração v3 (mysqli_multi_query) ===\n\n";

// 1. Limpar tabelas parciais via PDO
$pdo = db();
echo "1. Limpando tabelas parciais...\n";
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE kanban_cards");
    echo "   kanban_cards limpa\n";
    $pdo->exec("TRUNCATE TABLE processos");
    echo "   processos limpa\n";
    $pdo->exec("TRUNCATE TABLE clientes");
    echo "   clientes limpa\n";
    $pdo->exec("TRUNCATE TABLE log_auditoria");
    echo "   log_auditoria limpa\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
} catch (Exception $e) {
    echo "   ERRO limpeza: " . $e->getMessage() . "\n";
}

// 2. Executar SQL v3 via mysqli_multi_query
$sqlFile = __DIR__ . '/migracao_ferreiraesa_v3.sql';
if (!file_exists($sqlFile)) { die("Arquivo migracao_ferreiraesa_v3.sql não encontrado!\n"); }

echo "\n2. Executando SQL v3 via mysqli (" . number_format(filesize($sqlFile)) . " bytes)...\n";

$sql = file_get_contents($sqlFile);

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$mysqli->set_charset('utf8mb4');
if ($mysqli->connect_error) { die("Erro conexão: " . $mysqli->connect_error . "\n"); }

$ok = 0;
$errors = 0;

if ($mysqli->multi_query($sql)) {
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
        $ok++;
        if ($mysqli->errno) {
            $errors++;
            if ($errors <= 15) {
                echo "   ERRO #$errors: " . $mysqli->error . "\n";
            }
        }
    } while ($mysqli->next_result());
}

if ($mysqli->errno) {
    $errors++;
    echo "   ERRO FINAL: " . $mysqli->error . "\n";
}
$mysqli->close();

echo "\n=== RESULTADO ===\n";
echo "Queries: $ok | Erros: $errors\n";

// 3. Verificação via PDO
$pdo = db();
echo "\n=== VERIFICAÇÃO ===\n";
$checks = array('clientes', 'processos', 'kanban_cards', 'log_auditoria');
foreach ($checks as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: $count registros\n";
    } catch (Exception $e) { echo "$t: ERRO\n"; }
}

echo "\nKanban por coluna:\n";
try {
    $rows = $pdo->query("SELECT coluna_atual, COUNT(*) as qtd FROM kanban_cards GROUP BY coluna_atual ORDER BY qtd DESC")->fetchAll();
    foreach ($rows as $r) { echo "  {$r['coluna_atual']}: {$r['qtd']}\n"; }
} catch (Exception $e) {}

echo "\nPronto!\n";
