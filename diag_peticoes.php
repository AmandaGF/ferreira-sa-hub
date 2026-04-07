<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== PETIÇÕES GERADAS ===\n\n";

$rows = $pdo->query("SELECT cd.id, cd.titulo, cd.tipo_peca, cd.tipo_acao, cd.created_at, cl.name as client_name, cd.tokens_input, cd.tokens_output, cd.custo_usd
    FROM case_documents cd
    LEFT JOIN clients cl ON cl.id = cd.client_id
    ORDER BY cd.id DESC
    LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

echo "Total recentes: " . count($rows) . "\n\n";

foreach ($rows as $r) {
    echo "#{$r['id']} | {$r['created_at']} | {$r['client_name']}\n";
    echo "   {$r['titulo']}\n";
    echo "   Tokens: {$r['tokens_input']}in/{$r['tokens_output']}out | USD {$r['custo_usd']}\n\n";
}
