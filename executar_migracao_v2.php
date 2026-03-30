<?php
/**
 * Limpa tabelas parciais e executa migracao_ferreiraesa_v2.sql
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração v2 (corrigida) ===\n\n";

// 1. Limpar tabelas parciais
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

// 2. Executar SQL v2
$sqlFile = __DIR__ . '/migracao_ferreiraesa_v2.sql';
if (!file_exists($sqlFile)) { die("Arquivo migracao_ferreiraesa_v2.sql não encontrado!\n"); }

echo "\n2. Executando SQL v2 (" . number_format(filesize($sqlFile)) . " bytes)...\n";

$sql = file_get_contents($sqlFile);
$sql = preg_replace('/--.*$/m', '', $sql);

$statements = array_filter(array_map('trim', explode(';', $sql)));
$total = count($statements);
$ok = 0;
$errors = 0;
$skipped = 0;

foreach ($statements as $i => $stmt) {
    if (empty($stmt) || strlen($stmt) < 5) { $skipped++; continue; }

    try {
        $pdo->exec($stmt);
        $ok++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate column') !== false || strpos($msg, 'Duplicate entry') !== false) {
            $skipped++;
        } else {
            $errors++;
            if ($errors <= 10) {
                $preview = substr($stmt, 0, 100);
                echo "   ERRO #$errors: $msg\n   SQL: $preview...\n\n";
            }
        }
    }

    if (($i + 1) % 50 === 0) {
        echo "   Processado " . ($i + 1) . " / $total...\n";
    }
}

echo "\n=== RESULTADO ===\n";
echo "Statements: $total | OK: $ok | Ignorados: $skipped | Erros: $errors\n";

// 3. Verificação
echo "\n=== VERIFICAÇÃO ===\n";
$checks = array('clientes', 'processos', 'kanban_cards', 'log_auditoria');
foreach ($checks as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: $count registros\n";
    } catch (Exception $e) {
        echo "$t: ERRO\n";
    }
}

// Kanban por coluna
echo "\nKanban cards por coluna:\n";
try {
    $rows = $pdo->query("SELECT coluna_atual, COUNT(*) as qtd FROM kanban_cards GROUP BY coluna_atual ORDER BY qtd DESC")->fetchAll();
    foreach ($rows as $r) { echo "  {$r['coluna_atual']}: {$r['qtd']}\n"; }
} catch (Exception $e) {}

echo "\nPronto!\n";
