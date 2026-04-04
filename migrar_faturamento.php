<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Sincronizar valor_acao → estimated_value_cents ===\n\n";

$rows = $pdo->query("SELECT id, valor_acao, estimated_value_cents FROM pipeline_leads WHERE valor_acao IS NOT NULL AND valor_acao != ''")->fetchAll();

$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($rows as $r) {
    $cents = parse_valor_reais($r['valor_acao']);
    if ($cents === null) {
        echo "[SKIP] Lead #{$r['id']} — valor_acao='{$r['valor_acao']}' (não numérico)\n";
        $skipped++;
        continue;
    }

    $reais = number_format($cents / 100, 2, ',', '.');
    $antigo = $r['estimated_value_cents'] ? number_format($r['estimated_value_cents'] / 100, 2, ',', '.') : 'NULL';

    $pdo->prepare("UPDATE pipeline_leads SET estimated_value_cents = ? WHERE id = ?")
        ->execute(array($cents, $r['id']));

    echo "[OK] Lead #{$r['id']} — '{$r['valor_acao']}' → R$ {$reais} (antes: R$ {$antigo})\n";
    $updated++;
}

echo "\n=== RESULTADO ===\n";
echo "Atualizados: $updated\n";
echo "Ignorados (texto não numérico): $skipped\n";
echo "Total processados: " . count($rows) . "\n";
echo "\n=== FIM ===\n";
