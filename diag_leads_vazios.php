<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== DIAG: Avaliação de dados possivelmente perdidos ===\n\n";

// Leads que DEVERIAM ter campos preenchidos (estágios pós-contrato)
$stagesPostContrato = array('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado');
$inClause = "'" . implode("','", $stagesPostContrato) . "'";

echo "--- Leads em estágios pós-contrato ({$inClause}) ---\n";
$tot = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ({$inClause}) AND arquivado_em IS NULL")->fetchColumn();
echo "Total: {$tot}\n\n";

echo "--- Quantos estão SEM honorários ---\n";
$q = $pdo->query("SELECT COUNT(*) FROM pipeline_leads
                  WHERE stage IN ({$inClause}) AND arquivado_em IS NULL
                  AND (honorarios_cents IS NULL OR honorarios_cents = 0)
                  AND (valor_acao IS NULL OR valor_acao = '' OR valor_acao = '0')");
$vaziosHon = (int)$q->fetchColumn();
echo "SEM honorários: {$vaziosHon}  (de {$tot} = " . ($tot ? round($vaziosHon/$tot*100) : 0) . "%)\n";

echo "\n--- Quantos estão SEM forma_pagamento ---\n";
$q = $pdo->query("SELECT COUNT(*) FROM pipeline_leads
                  WHERE stage IN ({$inClause}) AND arquivado_em IS NULL
                  AND (forma_pagamento IS NULL OR forma_pagamento = '')");
echo "SEM pagamento: " . (int)$q->fetchColumn() . "\n";

echo "\n--- Quantos estão SEM vencimento_parcela ---\n";
$q = $pdo->query("SELECT COUNT(*) FROM pipeline_leads
                  WHERE stage IN ({$inClause}) AND arquivado_em IS NULL
                  AND (vencimento_parcela IS NULL OR vencimento_parcela = '')");
echo "SEM vencimento: " . (int)$q->fetchColumn() . "\n";

// Quando foi o updated_at de cada um? Se o updated_at é antigo (sem tocar faz tempo), talvez a pessoa só não preencheu.
// Se o updated_at é RECENTE mas os campos estão vazios, é suspeito.
echo "\n--- Distribuição de updated_at dos leads pós-contrato sem honorários ---\n";
$dist = $pdo->query("SELECT
    CASE
        WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 'ultimas 24h'
        WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 'ultimos 7 dias'
        WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'ultimos 30 dias'
        WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 'ultimos 90 dias'
        ELSE '90+ dias'
    END AS periodo,
    COUNT(*) AS qtd
    FROM pipeline_leads
    WHERE stage IN ({$inClause}) AND arquivado_em IS NULL
      AND (honorarios_cents IS NULL OR honorarios_cents = 0)
      AND (valor_acao IS NULL OR valor_acao = '' OR valor_acao = '0')
    GROUP BY periodo")->fetchAll();
foreach ($dist as $d) echo "  {$d['periodo']}: {$d['qtd']} leads\n";

echo "\n--- TOP 20 leads SUSPEITOS (pós-contrato, sem honorários, updated_at recente) ---\n";
$sus = $pdo->query("SELECT id, name, stage, updated_at, case_type, assigned_to,
                           honorarios_cents, valor_acao, forma_pagamento, vencimento_parcela
                    FROM pipeline_leads
                    WHERE stage IN ({$inClause}) AND arquivado_em IS NULL
                      AND (honorarios_cents IS NULL OR honorarios_cents = 0)
                      AND (valor_acao IS NULL OR valor_acao = '' OR valor_acao = '0')
                      AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ORDER BY updated_at DESC LIMIT 20")->fetchAll();
foreach ($sus as $l) {
    echo "  #{$l['id']} {$l['name']} — {$l['stage']} ({$l['case_type']}) — updated: {$l['updated_at']}\n";
}

echo "\n=== FIM ===\n";
