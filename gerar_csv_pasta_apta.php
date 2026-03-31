<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$acao = isset($_GET['acao']) ? $_GET['acao'] : 'select';

header('Content-Type: text/plain; charset=utf-8');

// Ler SQL e extrair bloco de LIKEs
$sqlFile = file_get_contents(__DIR__ . '/correcao_final_portal.sql');
$selectStart = strpos($sqlFile, "SELECT id, title, status, created_at, case_number");
$selectEnd = strpos($sqlFile, "ORDER BY title;");

if ($selectStart === false || $selectEnd === false) {
    die("ERRO: nao encontrou o SELECT no arquivo SQL. Verifique correcao_final_portal.sql\n");
}

$selectSql = substr($sqlFile, $selectStart, $selectEnd - $selectStart + strlen("ORDER BY title"));
$selectSql = str_replace("status = 'pasta_apta'", "status = 'em_elaboracao'", $selectSql);

$whereStart = strpos($selectSql, "AND (");
$whereEnd = strrpos($selectSql, ")");
$whereClause = substr($selectSql, $whereStart, $whereEnd - $whereStart + 1);

if ($acao === 'select') {
    echo "=== SELECT DE CONFERENCIA ===\n\n";
    $stmt = $pdo->query($selectSql);
    $rows = $stmt->fetchAll();
    echo "TOTAL: " . count($rows) . " linhas\n\n";
    foreach ($rows as $r) {
        echo "#{$r['id']} | {$r['title']} | proc: {$r['case_number']}\n";
    }
    echo "\nPara UPDATE: &acao=update\n";

} elseif ($acao === 'update') {
    echo "=== EXECUTANDO CORRECAO ===\n\n";

    echo "1. SELECT conferencia...\n";
    $stmt = $pdo->query($selectSql);
    $rows = $stmt->fetchAll();
    echo "   Encontradas: " . count($rows) . " linhas\n\n";

    if (count($rows) === 0) {
        die("ABORTADO: 0 linhas encontradas. Ja foi executado?\n");
    }

    echo "2. UPDATE cases SET status='distribuido'...\n";
    $updateSql = "UPDATE cases SET status = 'distribuido', updated_at = NOW() WHERE status = 'em_elaboracao' $whereClause";
    $affected = $pdo->exec($updateSql);
    echo "   Linhas atualizadas: $affected\n\n";

    echo "3. Verificacao final:\n";
    $stmt = $pdo->query("SELECT status, COUNT(*) as qtd FROM cases GROUP BY status ORDER BY qtd DESC");
    foreach ($stmt->fetchAll() as $r) {
        echo "   {$r['status']}: {$r['qtd']}\n";
    }
    echo "\nCONCLUIDO!\n";
}
