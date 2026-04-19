<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$aplicar = !empty($_GET['aplicar']);
echo "=== LIMPAR DUPLICATAS V2 (mais agressivo — leads órfãos em combos) ===\n";
echo "Modo: " . ($aplicar ? "APLICAR" : "SIMULAÇÃO") . "\n\n";
echo "Regra: em cada cliente, se houver 2+ leads pós-contrato, manter dados\n";
echo "       financeiros (valor, forma, vencimento, exito) APENAS no lead mais\n";
echo "       recente. Os demais ficam 100% em branco (Andressia pode decidir\n";
echo "       se divide, depois).\n\n";

// Buscar clientes que têm 2+ leads pós-contrato com qualquer dado financeiro
$clientes = $pdo->query("
    SELECT client_id
    FROM pipeline_leads
    WHERE client_id IS NOT NULL
      AND arquivado_em IS NULL
      AND stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')
    GROUP BY client_id
    HAVING COUNT(*) > 1
       AND SUM(CASE WHEN
              (honorarios_cents > 0)
              OR (valor_acao IS NOT NULL AND valor_acao != '' AND valor_acao != '0')
              OR (forma_pagamento IS NOT NULL AND forma_pagamento != '')
              OR (vencimento_parcela IS NOT NULL AND vencimento_parcela != '')
              OR (exito_percentual IS NOT NULL AND exito_percentual != '' AND exito_percentual != '0')
           THEN 1 ELSE 0 END) > 0
")->fetchAll(PDO::FETCH_COLUMN);

echo "Clientes com combos detectados: " . count($clientes) . "\n\n";

$totalLimpos = 0;
foreach ($clientes as $clientId) {
    $leads = $pdo->prepare("
        SELECT id, name, stage, case_type,
               honorarios_cents, valor_acao, forma_pagamento, vencimento_parcela, exito_percentual
        FROM pipeline_leads
        WHERE client_id = ? AND arquivado_em IS NULL
          AND stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')
        ORDER BY updated_at DESC, id DESC
    ");
    $leads->execute(array($clientId));
    $leadsDoCliente = $leads->fetchAll();
    if (count($leadsDoCliente) < 2) continue;

    // Detectar qual lead MANTÉM os dados (o mais recente que tem honorarios OU o mais recente em geral)
    $indiceKeep = 0;
    foreach ($leadsDoCliente as $i => $l) {
        if ($l['honorarios_cents'] > 0 || (!empty($l['valor_acao']) && $l['valor_acao'] !== '0')) {
            $indiceKeep = $i;
            break;
        }
    }
    $keep = $leadsDoCliente[$indiceKeep];

    // Coletar IDs a limpar (todos os outros que têm QUALQUER dado financeiro)
    $toClean = array();
    foreach ($leadsDoCliente as $i => $l) {
        if ($i === $indiceKeep) continue;
        $temDado = $l['honorarios_cents'] > 0
                 || (!empty($l['valor_acao']) && $l['valor_acao'] !== '0')
                 || !empty($l['forma_pagamento'])
                 || !empty($l['vencimento_parcela'])
                 || (!empty($l['exito_percentual']) && $l['exito_percentual'] !== '0');
        if ($temDado) $toClean[] = $l;
    }

    if (empty($toClean)) continue;

    echo "--- Cliente #{$clientId} ({$keep['name']}) ---\n";
    $valKeep = $keep['honorarios_cents'] ? 'R$ ' . number_format($keep['honorarios_cents']/100, 2, ',', '.') : '(sem valor)';
    echo "  👑 MANTÉM Lead #{$keep['id']} — {$keep['case_type']} — {$valKeep}\n";
    foreach ($toClean as $l) {
        $vLimp = $l['honorarios_cents'] ? 'R$ ' . number_format($l['honorarios_cents']/100, 2, ',', '.') : '(sem valor)';
        echo "  🧹 LIMPA  Lead #{$l['id']} — {$l['case_type']} — {$vLimp} forma={$l['forma_pagamento']} venc={$l['vencimento_parcela']}\n";
    }

    if ($aplicar) {
        $ids = array_map(function($l){ return $l['id']; }, $toClean);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE pipeline_leads
                       SET honorarios_cents = NULL, valor_acao = NULL,
                           forma_pagamento = NULL, vencimento_parcela = NULL,
                           exito_percentual = NULL, updated_at = NOW()
                       WHERE id IN ({$ph})")->execute($ids);
    }
    $totalLimpos += count($toClean);
    echo "\n";
}

echo "=== RESUMO ===\n";
echo "Leads " . ($aplicar ? "limpos" : "que SERIAM limpos") . ": {$totalLimpos}\n";
if (!$aplicar && $totalLimpos > 0) {
    echo "\nPra aplicar: ?key=fsa-hub-deploy-2026&aplicar=1\n";
}
