<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== CASES POR STATUS — INCLUINDO kanban_oculto=1 ===\n";
$labels = array(
    'aguardando_docs'   => 'Aguardando Docs',
    'em_elaboracao'     => 'Pasta Apta (redigindo)',
    'para_execucao_ia'  => 'Para Execução — IA',
    'aguardando_prazo'  => 'PARA DISTRIBUIR',
    'distribuido'       => 'Distribuído (aguard. despacho)',
    'em_andamento'      => 'EM ANDAMENTO',
    'doc_faltante'      => 'Doc Faltante',
    'suspenso'          => 'Suspenso',
    'kanban_prev'       => 'PREV',
    'parceria_previdenciario' => 'Parceria',
    'renunciamos'       => 'Renunciamos',
    'arquivado'         => 'Arquivado',
    'cancelado'         => 'Cancelado',
    'finalizado'        => 'Finalizado',
    'concluido'         => 'Concluído',
    'ativo'             => 'ATIVO (legado)',
);

$total = 0;
foreach ($pdo->query("SELECT status, COALESCE(kanban_oculto,0) AS oculto, COUNT(*) c
                      FROM cases
                      GROUP BY status, oculto
                      ORDER BY c DESC") as $r) {
    $l = $labels[$r['status']] ?? $r['status'];
    printf("  %-45s oculto=%d  %5d\n", $l, $r['oculto'], $r['c']);
    $total += $r['c'];
}
echo "\n  TOTAL GERAL DE CASES: $total\n";

echo "\n=== CASES COM CNJ PREENCHIDO (case_number) por status ===\n";
foreach ($pdo->query("SELECT status, COUNT(*) c
                      FROM cases
                      WHERE case_number IS NOT NULL AND case_number <> ''
                      GROUP BY status ORDER BY c DESC") as $r) {
    $l = $labels[$r['status']] ?? $r['status'];
    printf("  %-45s %5d\n", $l, $r['c']);
}

echo "\n=== CASES SEM CNJ por status ===\n";
foreach ($pdo->query("SELECT status, COUNT(*) c
                      FROM cases
                      WHERE case_number IS NULL OR case_number = ''
                      GROUP BY status ORDER BY c DESC") as $r) {
    $l = $labels[$r['status']] ?? $r['status'];
    printf("  %-45s %5d\n", $l, $r['c']);
}

echo "\n=== Total ARQUIVADOS COM CNJ (processos reais escondidos?) ===\n";
$arqCNJ = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='arquivado' AND case_number IS NOT NULL AND case_number <> ''")->fetchColumn();
echo "  Arquivados COM CNJ preenchido: $arqCNJ\n";
$arqSemCNJ = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='arquivado' AND (case_number IS NULL OR case_number = '')")->fetchColumn();
echo "  Arquivados SEM CNJ: $arqSemCNJ\n";

echo "\n=== TABELA processos_judiciais (se existir) ===\n";
try {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM processos_judiciais")->fetchColumn();
    echo "  Total: $c\n";
    foreach ($pdo->query("SELECT COALESCE(situacao,'(vazio)') s, COUNT(*) c FROM processos_judiciais GROUP BY s ORDER BY c DESC LIMIT 20") as $r) {
        printf("    %-30s %d\n", $r['s'], $r['c']);
    }
} catch (Exception $e) { echo "  (tabela nao existe: " . $e->getMessage() . ")\n"; }

echo "\n=== Datajud (se existir) — total processos monitorados ===\n";
try {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM datajud_cases")->fetchColumn();
    echo "  Total: $c\n";
} catch (Exception $e) { echo "  (tabela datajud_cases nao existe)\n"; }
try {
    $c = (int)$pdo->query("SELECT COUNT(DISTINCT case_number) FROM cases WHERE case_number IS NOT NULL AND case_number <> ''")->fetchColumn();
    echo "  Cases com CNJ distintos: $c\n";
} catch (Exception $e) {}

echo "\n=== TABELAS relacionadas a processos (grep no schema) ===\n";
foreach ($pdo->query("SHOW TABLES LIKE '%process%'") as $r) echo "  " . array_values($r)[0] . "\n";
foreach ($pdo->query("SHOW TABLES LIKE '%case%'") as $r) echo "  " . array_values($r)[0] . "\n";
foreach ($pdo->query("SHOW TABLES LIKE '%datajud%'") as $r) echo "  " . array_values($r)[0] . "\n";
