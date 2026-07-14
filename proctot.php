<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Distribuição por status (kanban_oculto=0) ===\n";
$labels = array(
    'aguardando_docs'   => 'Contrato — Aguardando Docs',
    'em_elaboracao'     => 'Pasta Apta (redigindo)',
    'para_execucao_ia'  => 'Para Execução — IA',
    'aguardando_prazo'  => '➡ PARA DISTRIBUIR (Aguard. Distribuição)',
    'distribuido'       => 'Distribuído (aguard. despacho)',
    'em_andamento'      => '➡ EM ANDAMENTO (Em Execução)',
    'doc_faltante'      => 'Doc Faltante',
    'suspenso'          => 'Suspenso',
    'kanban_prev'       => 'Kanban PREV',
    'parceria_previdenciario' => 'Parceria',
    'renunciamos'       => 'Renunciamos',
    'arquivado'         => 'Arquivado',
    'cancelado'         => 'Cancelado',
    'finalizado'        => 'Finalizado',
    'concluido'         => 'Concluído',
);

$total = 0; $totAtivos = 0;
foreach ($pdo->query("SELECT status, COUNT(*) c FROM cases WHERE COALESCE(kanban_oculto,0)=0 GROUP BY status ORDER BY c DESC") as $r) {
    $l = $labels[$r['status']] ?? $r['status'];
    printf("  %-45s %4d\n", $l, $r['c']);
    $total += $r['c'];
    if (!in_array($r['status'], array('arquivado','cancelado','finalizado','concluido','renunciamos'))) $totAtivos += $r['c'];
}
echo "\n  TOTAL (kanban_oculto=0): $total\n";
echo "  TOTAL ATIVOS: $totAtivos\n";

echo "\n=== RESPOSTA ===\n";
$emAndamento = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='em_andamento' AND COALESCE(kanban_oculto,0)=0")->fetchColumn();
$paraDistribuir = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='aguardando_prazo' AND COALESCE(kanban_oculto,0)=0")->fetchColumn();
$distribuido = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='distribuido' AND COALESCE(kanban_oculto,0)=0")->fetchColumn();
echo "  EM ANDAMENTO (em_andamento): $emAndamento\n";
echo "  PARA DISTRIBUIR (aguardando_prazo): $paraDistribuir\n";
echo "  DISTRIBUIDO aguardando despacho: $distribuido\n";
