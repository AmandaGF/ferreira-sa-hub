<?php
/**
 * Inspeção de schema das tabelas relevantes pra skill Marina.
 * Banco: ferre3151357_conecta
 */
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$tabelas = array('cases','case_andamentos','case_tasks','agenda_eventos','prazos_processuais','prazos_calculos','case_publicacoes');

foreach ($tabelas as $t) {
    echo "════════════════════════════════════════════════════════════\n";
    echo "  TABELA: $t\n";
    echo "════════════════════════════════════════════════════════════\n";
    try {
        $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $sql = $row['Create Table'] ?? array_values($row)[1];
            echo $sql . "\n\n";
        } else {
            echo "(não existe)\n\n";
        }
    } catch (Exception $e) {
        echo "(erro: " . $e->getMessage() . ")\n\n";
    }
}

// Sample: como pegar o último andamento e quanto tempo passou
echo "════════════════════════════════════════════════════════════\n";
echo "  AMOSTRA: query do filtro 'sem movimentação > 30 dias' (TJRJ + PJe)\n";
echo "════════════════════════════════════════════════════════════\n";
$sqlFiltro = "
SELECT c.id, c.case_number, c.title, c.sistema_tribunal, c.comarca_uf,
       MAX(a.data_andamento) AS ultimo_andamento,
       DATEDIFF(NOW(), MAX(a.data_andamento)) AS dias_desde_ultimo
FROM cases c
LEFT JOIN case_andamentos a ON a.case_id = c.id
WHERE c.sistema_tribunal = 'PJe'
  AND c.comarca_uf = 'RJ'
  AND c.status NOT IN ('arquivado','cancelado','concluido','renunciamos')
  AND IFNULL(c.kanban_oculto, 0) = 0
GROUP BY c.id
HAVING ultimo_andamento IS NULL
    OR DATEDIFF(NOW(), ultimo_andamento) > 30
ORDER BY dias_desde_ultimo DESC
LIMIT 5
";
echo trim($sqlFiltro) . "\n\n";
echo "AMOSTRA DOS RESULTADOS:\n";
try {
    foreach ($pdo->query($sqlFiltro)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  #{$r['id']}  {$r['case_number']}  {$r['comarca_uf']}/{$r['sistema_tribunal']}  ultimo={$r['ultimo_andamento']}  dias={$r['dias_desde_ultimo']}\n";
    }
} catch (Exception $e) {
    echo "  (erro: " . $e->getMessage() . ")\n";
}
