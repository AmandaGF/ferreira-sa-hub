<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== ORCAMENTOS + brindes ===\n\n";
$q = $pdo->query("SELECT o.id, o.brinde_id, b.nome brinde, o.fornecedor_id, f.nome forn, f.ativo forn_ativo,
                         o.valor_unitario, o.escolhido, o.score
                  FROM presenca_orcamento o
                  JOIN presenca_brinde b ON b.id=o.brinde_id
                  JOIN presenca_fornecedor f ON f.id=o.fornecedor_id
                  ORDER BY o.brinde_id, o.escolhido DESC");
foreach ($q as $r) {
    printf("Orc#%d | brinde=%s(#%d) | forn=%s(ativo=%d) | R$%.2f | escolhido=%d | score=%.1f\n",
        $r['id'], $r['brinde'], $r['brinde_id'], $r['forn'], $r['forn_ativo'],
        $r['valor_unitario'], $r['escolhido'], $r['score']);
}

echo "\n=== FORNECEDORES ===\n";
foreach ($pdo->query("SELECT id, nome, ativo FROM presenca_fornecedor") as $r) {
    printf("  #%d %s (ativo=%d)\n", $r['id'], $r['nome'], $r['ativo']);
}

echo "\n=== Query da MATRIZ (melhorForn) ===\n";
$rows = $pdo->query("SELECT o.brinde_id, f.nome AS forn_nome, o.valor_unitario, o.frete, o.prazo_producao_dias, o.prazo_entrega_dias, o.score, b.qtd_compra_referencia
                     FROM presenca_orcamento o
                     JOIN presenca_fornecedor f ON f.id = o.fornecedor_id
                     JOIN presenca_brinde b ON b.id = o.brinde_id
                     WHERE f.ativo = 1 AND b.ativo = 1 AND o.escolhido = 1")->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) echo "  (nenhum orcamento com escolhido=1 + forn ativo + brinde ativo)\n";
foreach ($rows as $r) printf("  brinde#%d forn=%s R$%.2f score=%.1f\n", $r['brinde_id'], $r['forn_nome'], $r['valor_unitario'], $r['score']);
