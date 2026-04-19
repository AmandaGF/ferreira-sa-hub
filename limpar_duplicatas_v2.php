<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$aplicar = !empty($_GET['aplicar']);
echo "=== LIMPAR DUPLICATAS V2 (versão clara) ===\n";
echo "Modo: " . ($aplicar ? "APLICAR" : "SIMULAÇÃO") . "\n\n";
echo "Regra: para cada cliente com 2+ leads pós-contrato, MANTÉM o lead que tem\n";
echo "       o MAIOR valor de honorários (ou o mais recente se nenhum tiver valor).\n";
echo "       Todos os outros leads do mesmo cliente ficam 100% sem dados financeiros.\n\n";

// Achar clientes com 2+ leads pós-contrato
$clientes = $pdo->query("
    SELECT client_id
    FROM pipeline_leads
    WHERE client_id IS NOT NULL AND arquivado_em IS NULL
      AND stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')
    GROUP BY client_id
    HAVING COUNT(*) > 1
")->fetchAll(PDO::FETCH_COLUMN);

$totalLimpos = 0;
foreach ($clientes as $clientId) {
    // Buscar todos os leads do cliente, ordenados por honorarios_cents DESC (maior valor primeiro)
    $leads = $pdo->prepare("
        SELECT id, name, stage, case_type,
               COALESCE(honorarios_cents, 0) AS hc,
               honorarios_cents, valor_acao, forma_pagamento, vencimento_parcela, exito_percentual,
               updated_at
        FROM pipeline_leads
        WHERE client_id = ? AND arquivado_em IS NULL
          AND stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')
        ORDER BY hc DESC, updated_at DESC, id DESC
    ");
    $leads->execute(array($clientId));
    $lista = $leads->fetchAll();
    if (count($lista) < 2) continue;

    // O PRIMEIRO da lista é quem tem o MAIOR valor (ou o mais recente se todos zerados)
    $keep = $lista[0];
    $toClean = array();
    foreach ($lista as $i => $l) {
        if ($i === 0) continue;
        // Só adiciona se tiver ALGUM dado financeiro pra limpar
        $temDado = (int)$l['honorarios_cents'] > 0
                || (!empty($l['valor_acao']) && $l['valor_acao'] !== '0')
                || !empty($l['forma_pagamento'])
                || !empty($l['vencimento_parcela'])
                || (!empty($l['exito_percentual']) && $l['exito_percentual'] !== '0');
        if ($temDado) $toClean[] = $l;
    }

    if (empty($toClean)) continue;

    $nomeCliente = $keep['name'];
    $valKeep = $keep['honorarios_cents'] ? 'R$ ' . number_format($keep['honorarios_cents']/100, 2, ',', '.') : '(sem valor)';
    echo "--- Cliente #{$clientId} ({$nomeCliente}) ---\n";
    echo "  👑 MANTÉM Lead #{$keep['id']} — {$keep['case_type']} — {$valKeep}\n";
    foreach ($toClean as $l) {
        $v = $l['honorarios_cents'] ? 'R$ ' . number_format($l['honorarios_cents']/100, 2, ',', '.') : '(sem valor)';
        echo "  🧹 LIMPA  Lead #{$l['id']} — {$l['case_type']} — {$v} | forma={$l['forma_pagamento']} | venc={$l['vencimento_parcela']}\n";
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
