<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== PIPELINE DE INICIAIS — RAIO-X (" . date('d/m/Y H:i') . ") ===\n\n";

echo "--- 1. Casos por status (nao arquivados) ---\n";
$rows = $pdo->query("SELECT status, COUNT(*) AS n
                     FROM cases
                     WHERE status NOT IN ('arquivado','cancelado')
                     GROUP BY status ORDER BY n DESC")->fetchAll();
foreach ($rows as $r) { printf("  %-28s %3d\n", $r['status'], $r['n']); }

echo "\n--- 2. Fila das colunas do pipeline de iniciais ---\n";
$stages = ['aguardando_docs','em_elaboracao','para_execucao_ia','doc_faltante','aguardando_prazo','distribuido'];
foreach ($stages as $st) {
    $q = $pdo->prepare("SELECT id, title, case_type, updated_at,
                        DATEDIFF(NOW(), updated_at) AS dias_parado,
                        (drive_folder_url IS NOT NULL AND drive_folder_url <> '') AS tem_drive,
                        elaborado_por_ia
                        FROM cases WHERE status = ? ORDER BY updated_at ASC");
    $q->execute([$st]);
    $list = $q->fetchAll();
    echo "\n  [" . strtoupper($st) . "] — " . count($list) . " caso(s)\n";
    foreach (array_slice($list, 0, 15) as $r) {
        printf("    #%-5s %-45s | %-22s | parado %3s d | drive:%s | ia:%s\n",
            $r['id'], mb_substr((string)$r['title'], 0, 45), mb_substr((string)$r['case_type'], 0, 22),
            $r['dias_parado'], $r['tem_drive'] ? 'S' : 'N', $r['elaborado_por_ia'] ? 'S' : 'N');
    }
    if (count($list) > 15) { echo "    ... e mais " . (count($list) - 15) . "\n"; }
}

echo "\n--- 3. Flag elaborado_por_ia (quem realmente rodou) ---\n";
$r = $pdo->query("SELECT COUNT(*) n, MIN(elaborado_por_ia_em) primeiro, MAX(elaborado_por_ia_em) ultimo
                  FROM cases WHERE elaborado_por_ia = 1")->fetch();
echo "  Casos marcados: {$r['n']} | primeiro: {$r['primeiro']} | ultimo: {$r['ultimo']}\n";
$rows = $pdo->query("SELECT id, title, elaborado_por_ia_em, elaborado_por_ia_doc_id, status
                     FROM cases WHERE elaborado_por_ia = 1
                     ORDER BY elaborado_por_ia_em DESC LIMIT 15")->fetchAll();
foreach ($rows as $r) {
    printf("    #%-5s %-40s | %s | doc_id:%-5s | %s\n", $r['id'], mb_substr((string)$r['title'],0,40),
        $r['elaborado_por_ia_em'], $r['elaborado_por_ia_doc_id'] ?: '-', $r['status']);
}

echo "\n--- 4. Fabrica de Peticoes: case_documents ---\n";
try {
    $r = $pdo->query("SELECT COUNT(*) n, MAX(created_at) ultimo, SUM(custo_usd) custo
                      FROM case_documents")->fetch();
    echo "  Total de pecas geradas: {$r['n']} | ultima: {$r['ultimo']} | custo acumulado USD: " . round((float)$r['custo'], 2) . "\n";
    $rows = $pdo->query("SELECT id, case_id, tipo_peca, tipo_acao, created_at, tokens_output
                         FROM case_documents ORDER BY created_at DESC LIMIT 10")->fetchAll();
    foreach ($rows as $r) {
        printf("    doc#%-4s case#%-5s %-22s | %-28s | %s | out:%s\n", $r['id'], $r['case_id'],
            mb_substr((string)$r['tipo_peca'],0,22), mb_substr((string)$r['tipo_acao'],0,28),
            $r['created_at'], $r['tokens_output']);
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- 5. Andamentos da Minerva (rastro da skill) ---\n";
try {
    $rows = $pdo->query("SELECT case_id, data_andamento, LEFT(descricao, 80) d
                         FROM case_andamentos
                         WHERE descricao LIKE '%Minerva%' OR descricao LIKE '%elaborada por IA%'
                         ORDER BY id DESC LIMIT 15")->fetchAll();
    echo "  Encontrados: " . count($rows) . "\n";
    foreach ($rows as $r) { printf("    case#%-5s %s | %s\n", $r['case_id'], $r['data_andamento'], $r['d']); }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- 6. Tempo de travessia: em_elaboracao -> distribuido (ultimos 90 dias) ---\n";
try {
    $rows = $pdo->query("SELECT c.id, c.title, c.created_at, c.updated_at,
                         DATEDIFF(c.updated_at, c.created_at) AS dias_total
                         FROM cases c
                         WHERE c.status = 'distribuido' AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                         ORDER BY dias_total DESC LIMIT 20")->fetchAll();
    echo "  Distribuidos nos ultimos 90d: " . count($rows) . "\n";
    $soma = 0;
    foreach ($rows as $r) { $soma += (int)$r['dias_total']; }
    if (count($rows)) { echo "  Media (criacao->distribuicao): " . round($soma / count($rows), 1) . " dias\n"; }
    foreach (array_slice($rows, 0, 10) as $r) {
        printf("    #%-5s %-45s | %3s dias\n", $r['id'], mb_substr((string)$r['title'],0,45), $r['dias_total']);
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- 7. Tipos de acao na fila (define escopo da automacao) ---\n";
$rows = $pdo->query("SELECT case_type, COUNT(*) n FROM cases
                     WHERE status IN ('aguardando_docs','em_elaboracao','para_execucao_ia','doc_faltante','aguardando_prazo')
                     GROUP BY case_type ORDER BY n DESC")->fetchAll();
foreach ($rows as $r) { printf("  %-45s %3d\n", mb_substr((string)$r['case_type'],0,45) ?: '(vazio)', $r['n']); }

echo "\n=== FIM ===\n";
