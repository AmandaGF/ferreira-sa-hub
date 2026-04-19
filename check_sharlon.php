<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Verificação: leads da Sharlon Wilson + combos em geral ===\n\n";

$rows = $pdo->query("
    SELECT id, name, stage, case_type, client_id, honorarios_cents, valor_acao,
           forma_pagamento, vencimento_parcela, exito_percentual
    FROM pipeline_leads
    WHERE name LIKE '%Sharlon%'
       OR client_id IN (
           SELECT client_id FROM pipeline_leads
           WHERE arquivado_em IS NULL
             AND client_id IS NOT NULL
             AND stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')
           GROUP BY client_id
           HAVING COUNT(*) > 1
       )
    ORDER BY client_id, id
")->fetchAll();

$lastClient = null;
foreach ($rows as $r) {
    if ($r['client_id'] !== $lastClient) {
        echo "\n--- Cliente #{$r['client_id']} ---\n";
        $lastClient = $r['client_id'];
    }
    $val = $r['honorarios_cents'] ? 'R$ ' . number_format($r['honorarios_cents']/100, 2, ',', '.') : '(vazio)';
    echo "  Lead #{$r['id']} — {$r['name']} — stage={$r['stage']} — type={$r['case_type']}\n";
    echo "    valor={$val} | forma={$r['forma_pagamento']} | venc={$r['vencimento_parcela']} | exito={$r['exito_percentual']}\n";
}
echo "\nTotal: " . count($rows) . " leads\n";
