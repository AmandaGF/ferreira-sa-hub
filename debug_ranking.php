<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Debug Ranking ===\n\n";

echo "--- gamificacao_totais ---\n";
$rows = $pdo->query("SELECT gt.*, u.name FROM gamificacao_totais gt JOIN users u ON u.id = gt.user_id ORDER BY (gt.pontos_total_comercial + gt.pontos_total_operacional) DESC")->fetchAll();
foreach ($rows as $r) {
    $total = $r['pontos_total_comercial'] + $r['pontos_total_operacional'];
    echo "{$r['name']}: Com={$r['pontos_total_comercial']} Op={$r['pontos_total_operacional']} Total={$total} | Mês: {$r['pontos_mes_comercial']}+{$r['pontos_mes_operacional']} | Nível: {$r['nivel']} (Lv{$r['nivel_num']}) | Contratos: {$r['contratos_mes']}/{$r['contratos_total']} | Ref: {$r['mes_referencia']}/{$r['ano_referencia']}\n";
}

echo "\n--- gamificacao_pontos (últimos 10) ---\n";
$pts = $pdo->query("SELECT gp.*, u.name FROM gamificacao_pontos gp JOIN users u ON u.id = gp.user_id ORDER BY gp.created_at DESC LIMIT 10")->fetchAll();
foreach ($pts as $p) {
    echo "#{$p['id']} | {$p['name']} | {$p['evento']} | +{$p['pontos']} | {$p['area']} | {$p['mes']}/{$p['ano']} | {$p['created_at']}\n";
}

echo "\n--- gamificacao_niveis ---\n";
$niveis = $pdo->query("SELECT * FROM gamificacao_niveis ORDER BY nivel_num")->fetchAll();
foreach ($niveis as $n) {
    echo "Lv{$n['nivel_num']}: {$n['nome']} ({$n['pontos_minimos']} pts) {$n['badge_emoji']}\n";
}

echo "\n--- Contagem de pontos por evento ---\n";
$contagem = $pdo->query("SELECT evento, COUNT(*) as qtd, SUM(pontos) as total_pts FROM gamificacao_pontos GROUP BY evento ORDER BY total_pts DESC")->fetchAll();
foreach ($contagem as $c) {
    echo "{$c['evento']}: {$c['qtd']} eventos = {$c['total_pts']} pts\n";
}

echo "\n=== FIM ===\n";
