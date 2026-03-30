<?php
/**
 * Executa o migracao_ferreiraesa.sql no banco de dados
 * As tabelas criadas (clientes, processos, kanban_cards, etc) são SEPARADAS das tabelas do Hub
 * Depois um segundo script mapeia os dados para o sistema atual
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$sqlFile = __DIR__ . '/migracao_ferreiraesa.sql';
if (!file_exists($sqlFile)) { die("Arquivo migracao_ferreiraesa.sql não encontrado!\n"); }

echo "=== Executando migração SQL ===\n\n";
echo "Arquivo: " . filesize($sqlFile) . " bytes\n\n";

$sql = file_get_contents($sqlFile);

// Remover comentários de linha e linhas vazias para evitar problemas
$sql = preg_replace('/--.*$/m', '', $sql);

// Dividir por statements (;)
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
        // Ignorar erros de tabela/coluna já existente
        if (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate column') !== false) {
            $skipped++;
        } else {
            $errors++;
            if ($errors <= 20) {
                $preview = substr($stmt, 0, 80);
                echo "ERRO #$errors: $msg\n  SQL: $preview...\n\n";
            }
        }
    }

    // Progresso a cada 100
    if (($i + 1) % 100 === 0) {
        echo "  Processado " . ($i + 1) . " / $total...\n";
    }
}

echo "\n=== RESULTADO ===\n";
echo "Total statements: $total\n";
echo "Executados OK: $ok\n";
echo "Ignorados (já existe): $skipped\n";
echo "Erros: $errors\n";

// Verificar contagens
echo "\n=== VERIFICAÇÃO ===\n";
$checks = array('clientes', 'processos', 'kanban_cards', 'parceiros', 'log_auditoria');
foreach ($checks as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: $count registros\n";
    } catch (Exception $e) {
        echo "$t: TABELA NÃO ENCONTRADA\n";
    }
}

echo "\nPronto!\n";
