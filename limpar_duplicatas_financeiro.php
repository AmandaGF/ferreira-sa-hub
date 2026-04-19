<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$aplicar = !empty($_GET['aplicar']);
echo "=== LIMPAR DUPLICATAS FINANCEIRAS (combos Alimentos+Convivência, etc) ===\n";
echo "Modo: " . ($aplicar ? "APLICAR" : "SIMULAÇÃO") . "\n\n";
echo "Regra: quando 2+ leads do MESMO cliente em estágio pós-contrato têm os\n";
echo "       MESMOS valores financeiros (mesmo contrato combo), manter só no\n";
echo "       lead MAIS RECENTE e limpar os outros (ficam em branco pra Andressia\n";
echo "       decidir se divide ou deixa vazio).\n\n";

// Buscar leads pós-contrato agrupados por (client_id, honorarios_cents, forma_pagamento)
$rows = $pdo->query("
    SELECT client_id, honorarios_cents, forma_pagamento, vencimento_parcela, exito_percentual,
           GROUP_CONCAT(id ORDER BY updated_at DESC, id DESC) AS lead_ids,
           GROUP_CONCAT(name ORDER BY updated_at DESC, id DESC) AS lead_names,
           GROUP_CONCAT(case_type ORDER BY updated_at DESC, id DESC) AS lead_types,
           COUNT(*) AS qtd
    FROM pipeline_leads
    WHERE client_id IS NOT NULL
      AND arquivado_em IS NULL
      AND stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')
      AND honorarios_cents > 0
      AND forma_pagamento IS NOT NULL AND forma_pagamento != ''
    GROUP BY client_id, honorarios_cents, forma_pagamento
    HAVING COUNT(*) > 1
    ORDER BY client_id
")->fetchAll();

echo "Grupos duplicados encontrados: " . count($rows) . "\n\n";

$limpos = 0;
foreach ($rows as $r) {
    $ids = explode(',', $r['lead_ids']);
    $nomes = explode(',', $r['lead_names']);
    $tipos = explode(',', $r['lead_types']);
    $valorFmt = number_format($r['honorarios_cents']/100, 2, ',', '.');

    echo "Cliente #{$r['client_id']} ({$nomes[0]}) — R$ {$valorFmt} — {$r['qtd']} leads:\n";
    foreach ($ids as $i => $id) {
        $marcador = ($i === 0) ? '👑 MANTÉM' : '🧹 LIMPA';
        echo "  {$marcador} Lead #{$id} — {$tipos[$i]}\n";
    }

    // Limpa todos exceto o primeiro (mais recente)
    $toClean = array_slice($ids, 1);
    if ($aplicar && !empty($toClean)) {
        $ph = implode(',', array_fill(0, count($toClean), '?'));
        $pdo->prepare("UPDATE pipeline_leads
                       SET honorarios_cents = NULL,
                           valor_acao = NULL,
                           forma_pagamento = NULL,
                           vencimento_parcela = NULL,
                           exito_percentual = NULL,
                           updated_at = NOW()
                       WHERE id IN ({$ph})")->execute($toClean);
    }
    $limpos += count($toClean);
    echo "\n";
}

echo "=== RESUMO ===\n";
echo "Leads que " . ($aplicar ? "foram" : "SERIAM") . " limpos: {$limpos}\n";
echo "Leads mantidos (1 por cliente/valor): " . count($rows) . "\n";

if (!$aplicar && $limpos > 0) {
    echo "\nPara aplicar, chame:\n";
    echo "  /conecta/limpar_duplicatas_financeiro.php?key=fsa-hub-deploy-2026&aplicar=1\n";
}
