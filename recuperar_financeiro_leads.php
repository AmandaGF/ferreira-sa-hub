<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$aplicar = !empty($_GET['aplicar']);
echo "=== RECUPERAR DADOS FINANCEIROS DOS LEADS ===\n";
echo "Modo: " . ($aplicar ? "APLICAR (vai escrever no banco)" : "SIMULAÇÃO (só mostra o que seria feito)") . "\n\n";

// 1. Ver quantos registros temos em document_history
$tot = (int)$pdo->query("SELECT COUNT(*) FROM document_history WHERE params_json IS NOT NULL AND params_json != ''")->fetchColumn();
echo "Total de documentos gerados no histórico: {$tot}\n\n";

// 2. Buscar contratos gerados (doc_type típico: contrato_*, honorarios_*)
$docs = $pdo->query("
    SELECT dh.id, dh.client_id, dh.doc_type, dh.tipo_acao, dh.generated_by, dh.created_at, dh.params_json,
           c.name AS client_name
    FROM document_history dh
    JOIN clients c ON c.id = dh.client_id
    WHERE dh.params_json IS NOT NULL AND dh.params_json != ''
      AND dh.doc_type LIKE '%contrato%' OR dh.doc_type LIKE '%honorari%'
    ORDER BY dh.created_at DESC
")->fetchAll();
echo "Contratos encontrados no histórico: " . count($docs) . "\n\n";

// DEBUG: mostrar params_json dos 3 primeiros contratos pra ver a estrutura real
if (!empty($docs) && !$aplicar) {
    echo "--- DEBUG: Estrutura JSON dos 3 últimos contratos (pra identificar campos corretos) ---\n";
    foreach (array_slice($docs, 0, 3) as $d) {
        echo "#{$d['id']} | doc_type={$d['doc_type']} | cliente={$d['client_name']} | data={$d['created_at']}\n";
        $j = json_decode($d['params_json'], true);
        if (is_array($j)) {
            foreach ($j as $k => $v) {
                $vs = is_array($v) ? json_encode($v) : (string)$v;
                if (mb_strlen($vs) > 80) $vs = mb_substr($vs, 0, 80) . '...';
                echo "    {$k}: {$vs}\n";
            }
        }
        echo "\n";
    }
    echo "--- FIM DEBUG ---\n\n";
}

if (empty($docs)) {
    // Talvez o doc_type não contenha 'contrato'. Vamos listar os tipos existentes:
    echo "--- Tipos de documento no histórico (pra debug) ---\n";
    foreach ($pdo->query("SELECT doc_type, COUNT(*) as n FROM document_history GROUP BY doc_type ORDER BY n DESC LIMIT 20")->fetchAll() as $t) {
        echo "  {$t['doc_type']}: {$t['n']}\n";
    }
    echo "\nAjuste a query em recuperar_financeiro_leads.php com o tipo correto e rode de novo.\n";
    exit;
}

// 3. Para cada contrato, extrair dados e tentar achar o lead
$recuperados = 0;
$pulados = 0;
foreach ($docs as $d) {
    $p = json_decode($d['params_json'], true);
    if (!is_array($p)) continue;

    // Campos que nos interessam
    $valor        = $p['valor_honorarios']  ?? ($p['valor_acao'] ?? '');
    $forma        = $p['forma_pagamento']   ?? '';
    $diaVenc      = $p['dia_vencimento']    ?? ($p['vencimento_parcela'] ?? '');
    $mesInicio    = $p['mes_inicio']        ?? '';
    $exito        = $p['percentual_risco']  ?? ($p['exito_percentual'] ?? ($p['exito'] ?? ''));
    $numParcelas  = $p['num_parcelas']      ?? '';
    $valorParcela = $p['valor_parcela']     ?? '';

    // Monta string de vencimento combinando dia + mês de início
    if ($diaVenc && $mesInicio) $venc = "Todo dia {$diaVenc} — início em {$mesInicio}";
    elseif ($diaVenc) $venc = "Todo dia {$diaVenc}";
    else $venc = '';

    // Enriquece forma_pagamento com parcelamento se disponível
    if ($forma && $numParcelas && $valorParcela) {
        $forma = "{$forma} — {$numParcelas}x de {$valorParcela}";
    }

    if (!$valor && !$forma && !$venc) { $pulados++; continue; }

    // Achar o lead correspondente (mesmo client_id, estágio pós-contrato, campos atualmente vazios)
    // IMPORTANTE: pegar apenas UM lead (o mais recente) pra não duplicar valor em combos
    // (ex: contrato cobre Alimentos + Convivência = 2 leads com mesmo client_id; o valor deve ir em só um)
    $leads = $pdo->prepare("
        SELECT id, name, stage, honorarios_cents, valor_acao, forma_pagamento, vencimento_parcela, exito_percentual
        FROM pipeline_leads
        WHERE client_id = ? AND arquivado_em IS NULL
          AND stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')
        ORDER BY updated_at DESC LIMIT 1
    ");
    $leads->execute(array($d['client_id']));
    $leadsDoCliente = $leads->fetchAll();

    if (empty($leadsDoCliente)) continue;

    // Atualizar leads do cliente que tenham campos vazios
    $atualizouAlgum = false;
    foreach ($leadsDoCliente as $l) {
        $updates = array();
        $vals = array();

        // Valor (só preenche se vazio)
        if ($valor && empty($l['honorarios_cents']) && empty($l['valor_acao'])) {
            $updates[] = 'valor_acao = ?';
            $vals[] = $valor;
            // Converter para cents também
            $numericValor = (float)str_replace(array('.', ','), array('', '.'), preg_replace('/[^\d,.]/', '', $valor));
            if ($numericValor > 0) {
                $updates[] = 'honorarios_cents = ?';
                $vals[] = (int)round($numericValor * 100);
            }
        }
        if ($forma && empty($l['forma_pagamento'])) {
            $updates[] = 'forma_pagamento = ?';
            $vals[] = $forma;
        }
        if ($venc && empty($l['vencimento_parcela'])) {
            $updates[] = 'vencimento_parcela = ?';
            $vals[] = $venc;
        }
        if ($exito && empty($l['exito_percentual'])) {
            $updates[] = 'exito_percentual = ?';
            $vals[] = $exito;
        }

        if (empty($updates)) continue;

        echo "  → Lead #{$l['id']} ({$l['name']}, stage={$l['stage']}) — contrato de " . date('d/m/Y', strtotime($d['created_at'])) . "\n";
        // Mostrar os valores reais que serão escritos
        $valIdx = 0;
        foreach ($updates as $u) {
            $fieldName = explode(' =', $u)[0];
            $displayVal = $vals[$valIdx] ?? '(null)';
            echo "      {$fieldName} = {$displayVal}\n";
            $valIdx++;
        }

        if ($aplicar) {
            $vals[] = $l['id'];
            $pdo->prepare("UPDATE pipeline_leads SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")->execute($vals);
        }
        $atualizouAlgum = true; // conta em simulação também
    }
    if ($atualizouAlgum) $recuperados++;
    echo "\n";
}

echo "\n=== RESUMO ===\n";
echo "Contratos analisados: " . count($docs) . "\n";
echo "Contratos sem dados financeiros: {$pulados}\n";
echo "Leads " . ($aplicar ? "atualizados" : "que SERIAM atualizados") . ": {$recuperados}\n";

if (!$aplicar && $recuperados > 0) {
    echo "\nPara aplicar de verdade, chame:\n";
    echo "  /conecta/recuperar_financeiro_leads.php?key=fsa-hub-deploy-2026&aplicar=1\n";
}
