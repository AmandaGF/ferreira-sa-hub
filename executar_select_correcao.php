<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$etapa = isset($_GET['etapa']) ? (int)$_GET['etapa'] : 1;

// Ler SQL e extrair bloco de LIKEs
$sqlFile = file_get_contents(__DIR__ . '/correcao_final_portal.sql');
$selectStart = strpos($sqlFile, "SELECT id, title, status, created_at, case_number");
$selectEnd = strpos($sqlFile, "ORDER BY title;");
$selectSql = substr($sqlFile, $selectStart, $selectEnd - $selectStart + strlen("ORDER BY title"));
$selectSql = str_replace("status = 'pasta_apta'", "status = 'em_elaboracao'", $selectSql);

// Extrair WHERE com LIKEs
$whereStart = strpos($selectSql, "AND (");
$whereEnd = strrpos($selectSql, ")");
$whereClause = substr($selectSql, $whereStart, $whereEnd - $whereStart + 1);

if ($etapa === 1) {
    // ═══ ETAPA 1: SELECT de conferencia ═══
    echo "=== ETAPA 1: SELECT de conferencia ===\n\n";
    $stmt = $pdo->query($selectSql);
    $rows = $stmt->fetchAll();
    echo "Total de linhas: " . count($rows) . "\n\n";
    foreach ($rows as $r) {
        echo "#{$r['id']} | {$r['title']} | {$r['case_number']}\n";
    }
    echo "\n--- Para executar o UPDATE, acesse com &etapa=2 ---\n";

} elseif ($etapa === 2) {
    // ═══ ETAPA 2: SELECT + UPDATE + VERIFICACAO ═══
    echo "=== ETAPA 2: SELECT + UPDATE + VERIFICACAO ===\n\n";

    // 2a. Confirmar SELECT
    echo "--- 2a. SELECT de conferencia ---\n";
    $stmt = $pdo->query($selectSql);
    $rows = $stmt->fetchAll();
    echo "Linhas encontradas: " . count($rows) . "\n\n";

    if (count($rows) === 0) {
        die("ABORTADO: nenhuma linha encontrada.\n");
    }

    // 2b. UPDATE
    echo "--- 2b. Executando UPDATE ---\n";
    $updateSql = "UPDATE cases SET status = 'distribuido', updated_at = NOW() WHERE status = 'em_elaboracao' $whereClause";
    $affected = $pdo->exec($updateSql);
    echo "Linhas atualizadas: $affected\n\n";

    // 2c. Verificacao final
    echo "--- 2c. Verificacao final ---\n";
    $stmt = $pdo->query("SELECT status, COUNT(*) as qtd FROM cases GROUP BY status ORDER BY qtd DESC");
    foreach ($stmt->fetchAll() as $r) {
        echo "  {$r['status']}: {$r['qtd']}\n";
    }
    echo "\nConcluido!\n";
}
