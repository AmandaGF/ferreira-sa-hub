<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== PASSO 1: SELECT de conferencia ===\n\n";

// Primeiro: verificar quais status existem na tabela cases
echo "--- Status existentes na tabela cases ---\n";
$stmt = $pdo->query("SELECT status, COUNT(*) as qtd FROM cases GROUP BY status ORDER BY qtd DESC");
foreach ($stmt->fetchAll() as $r) {
    echo "  {$r['status']}: {$r['qtd']}\n";
}

echo "\n--- Buscando status = 'pasta_apta' ---\n";
$count1 = $pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'pasta_apta'")->fetchColumn();
echo "Casos com status 'pasta_apta': $count1\n";

echo "\n--- Buscando status = 'em_elaboracao' (que eh Pasta Apta no Operacional) ---\n";
$count2 = $pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'em_elaboracao'")->fetchColumn();
echo "Casos com status 'em_elaboracao': $count2\n";

// Executar o SELECT exato do SQL (com status = 'pasta_apta')
echo "\n--- SELECT exato do SQL (status = 'pasta_apta' + lista de nomes) ---\n";
$sql = "SELECT id, title, status, created_at, case_number FROM cases WHERE status = 'pasta_apta' AND (" . implode(' OR ', array(
"title LIKE 'Giseli Rodrigues Correa x Divó%'",
"title LIKE 'Cinthia Mara S. P. Gama x Cons%'",
"title LIKE 'Anderson Souza Candido x Consu%'",
"title LIKE 'Thiago%'",
"title LIKE 'Joyce%'"
)) . ") ORDER BY title";
$rows = $pdo->query($sql)->fetchAll();
echo "Resultado: " . count($rows) . " linhas\n";

// Agora testar COM em_elaboracao
echo "\n\n=== TESTE ALTERNATIVO: mesma lista mas com status = 'em_elaboracao' ===\n";

$sqlFile = file_get_contents(__DIR__ . '/correcao_final_portal.sql');
// Extrair apenas o SELECT do PASSO 1
$selectStart = strpos($sqlFile, "SELECT id, title, status, created_at, case_number");
$selectEnd = strpos($sqlFile, "ORDER BY title;");
if ($selectStart !== false && $selectEnd !== false) {
    $selectSql = substr($sqlFile, $selectStart, $selectEnd - $selectStart + strlen("ORDER BY title"));

    // Trocar pasta_apta por em_elaboracao para testar
    $selectSqlFixed = str_replace("status = 'pasta_apta'", "status = 'em_elaboracao'", $selectSql);

    echo "Executando SELECT com em_elaboracao...\n";
    try {
        $stmt = $pdo->query($selectSqlFixed);
        $results = $stmt->fetchAll();
        echo "RESULTADO: " . count($results) . " linhas encontradas\n\n";

        if (count($results) > 0) {
            echo "Primeiros 20 resultados:\n";
            $i = 0;
            foreach ($results as $r) {
                if ($i >= 20) { echo "... e mais " . (count($results) - 20) . " linhas\n"; break; }
                echo "  #{$r['id']} | {$r['title']} | {$r['status']} | {$r['case_number']}\n";
                $i++;
            }
        }
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }

    // Tambem testar o SELECT original (pasta_apta)
    echo "\n--- SELECT original (pasta_apta) ---\n";
    try {
        $stmt = $pdo->query($selectSql);
        $results2 = $stmt->fetchAll();
        echo "RESULTADO: " . count($results2) . " linhas encontradas\n";
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
} else {
    echo "Nao consegui extrair o SELECT do arquivo SQL.\n";
    echo "selectStart=$selectStart, selectEnd=$selectEnd\n";
}
