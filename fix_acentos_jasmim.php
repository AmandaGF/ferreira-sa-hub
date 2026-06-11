<?php
/**
 * Amanda 11/06/2026: corrigir acentuacao dos andamentos do caso
 * 'John Jasmim x Querela' (caso #1050). Sem-confirma = dry-run.
 * Com &confirma=1 = aplica de verdade.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';

$pdo = db();
$dryRun = !isset($_GET['confirma']);
$caseId = (int)($_GET['case_id'] ?? 1050);

$ands = $pdo->prepare("SELECT id, data_andamento, tipo, descricao FROM case_andamentos
                       WHERE case_id = ? ORDER BY data_andamento ASC, id ASC");
$ands->execute(array($caseId));
$rows = $ands->fetchAll();

echo "=== Caso #$caseId · " . count($rows) . " andamentos ===\n";
echo "MODO: " . ($dryRun ? 'DRY-RUN (nada sera salvo)' : 'CONFIRMA (sera salvo)') . "\n\n";

$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
if (!$apiKey) { echo "ERRO: ANTHROPIC_API_KEY nao configurada.\n"; exit; }

$system = "Você corrige a acentuação de textos jurídicos em português brasileiro. "
        . "REGRAS ESTRITAS:\n"
        . "- Adicione APENAS acentuação e cedilhas faltantes (à, á, â, ã, é, ê, í, ó, ô, õ, ú, ç).\n"
        . "- NÃO altere palavras, ordem, pontuação, números, datas, nomes próprios, abreviações jurídicas.\n"
        . "- NÃO traduza, NÃO reformule, NÃO acrescente texto.\n"
        . "- Mantenha emojis, símbolos e formatação exatamente como estão.\n"
        . "- Termos jurídicos: réu/ré, decisão, citação, audiência, petição, contestação, réplica, despacho, juíza, juiz, intimação, distribuição, certidão, juntada, advogada, advogado, ministério, público, união, gratuidade, justiça, tutela, urgência, ação, união estável, mérito, válido, válida, ofício, mandado.\n"
        . "- Retorne APENAS o texto corrigido, sem aspas, sem markdown, sem comentários.";

$totalCusto = 0; $totalTokens = 0; $alterados = 0;

foreach ($rows as $i => $a) {
    $original = $a['descricao'];
    if (trim($original) === '') continue;

    $payload = json_encode(array(
        'model' => 'claude-haiku-4-5',
        'max_tokens' => 2000,
        'temperature' => 0,
        'system' => $system,
        'messages' => array(
            array('role' => 'user', 'content' => $original)
        ),
    ), JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 400) {
        echo "  [#{$a['id']}] ERRO HTTP $code: " . substr($resp, 0, 200) . "\n";
        continue;
    }
    $data = json_decode($resp, true);
    $corrigido = '';
    if (isset($data['content'])) {
        foreach ($data['content'] as $block) {
            if ($block['type'] === 'text') { $corrigido .= $block['text']; break; }
        }
    }
    $corrigido = trim($corrigido);
    if (!$corrigido) {
        echo "  [#{$a['id']}] vazio retornado, pulando.\n";
        continue;
    }

    $tokensIn  = (int)($data['usage']['input_tokens']  ?? 0);
    $tokensOut = (int)($data['usage']['output_tokens'] ?? 0);
    // Haiku 4.5: $1/MTok input, $5/MTok output. R$ 5.5 / USD aproximado.
    $custoUsd  = ($tokensIn / 1000000) * 1.0 + ($tokensOut / 1000000) * 5.0;
    $custoBrl  = $custoUsd * 5.5;
    $totalCusto += $custoBrl;
    $totalTokens += $tokensIn + $tokensOut;

    $mudou = $corrigido !== $original;

    echo "\n[" . ($i+1) . "/" . count($rows) . "] AND #{$a['id']} ({$a['data_andamento']} · {$a['tipo']})\n";
    if ($mudou) {
        $alterados++;
        echo "  ANTES:   " . mb_substr($original,  0, 240, 'UTF-8') . (mb_strlen($original)  > 240 ? '...' : '') . "\n";
        echo "  DEPOIS:  " . mb_substr($corrigido, 0, 240, 'UTF-8') . (mb_strlen($corrigido) > 240 ? '...' : '') . "\n";
        if (!$dryRun) {
            $u = $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?");
            $u->execute(array($corrigido, $a['id']));
            echo "  ✓ Salvo.\n";
        }
    } else {
        echo "  (sem mudanças)\n";
    }
}

echo "\n=== RESUMO ===\n";
echo "Total processados: " . count($rows) . "\n";
echo "Alterados:         $alterados\n";
echo "Tokens totais:     $totalTokens\n";
echo "Custo total:       R\$ " . number_format($totalCusto, 4, ',', '.') . "\n";

if ($dryRun) {
    echo "\n[DRY-RUN] Nada salvo. Pra aplicar: adicione &confirma=1 na URL.\n";
} else {
    echo "\n✓ Tudo salvo no banco.\n";
}
