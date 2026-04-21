<?php
/**
 * Normaliza valores antigos de pipeline_leads.forma_pagamento pras 5 opções padrão:
 *   CARTÃO DE CRÉDITO, CRÉDITO RECORRENTE, PIX RECORRENTE, BOLETO, À VISTA
 *
 * Uso: curl "https://ferreiraesa.com.br/conecta/migrar_forma_pagamento.php?key=fsa-hub-deploy-2026"
 * Dry-run (só mostra o que vai fazer, sem gravar):
 *   curl ".../migrar_forma_pagamento.php?key=fsa-hub-deploy-2026&dry=1"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$dryRun = isset($_GET['dry']) && $_GET['dry'] === '1';

function mapearFormaPagamento($v) {
    $up = mb_strtoupper(trim($v ?? ''));
    if ($up === '') return null;
    // Já tá num dos 5 valores — não mexe
    $padroes = array('CARTÃO DE CRÉDITO', 'CRÉDITO RECORRENTE', 'PIX RECORRENTE', 'BOLETO', 'À VISTA');
    if (in_array($up, $padroes, true)) return null;
    // Matching inteligente
    if (strpos($up, 'BOLETO') !== false) return 'BOLETO';
    if (strpos($up, 'PIX') !== false) return 'PIX RECORRENTE';
    if (strpos($up, 'VISTA') !== false) return 'À VISTA';
    if (strpos($up, 'CARTÃO') !== false || strpos($up, 'CARTAO') !== false
        || strpos($up, 'CRÉDITO') !== false || strpos($up, 'CREDITO') !== false) {
        return (strpos($up, 'RECORRENTE') !== false) ? 'CRÉDITO RECORRENTE' : 'CARTÃO DE CRÉDITO';
    }
    return null; // não consegue mapear — deixa como está
}

// Pega todos leads com forma_pagamento preenchida
$rows = $pdo->query("SELECT id, name, forma_pagamento FROM pipeline_leads WHERE forma_pagamento IS NOT NULL AND forma_pagamento != '' ORDER BY id")->fetchAll();

echo "=== NORMALIZAÇÃO forma_pagamento ===\n";
echo ($dryRun ? "MODO DRY-RUN (não grava)\n" : "MODO REAL (vai gravar)\n") . "\n";
echo "Total leads com forma_pagamento preenchida: " . count($rows) . "\n\n";

$stmt = $pdo->prepare("UPDATE pipeline_leads SET forma_pagamento = ? WHERE id = ?");

$contadores = array('atualizado' => 0, 'ja_padrao' => 0, 'nao_mapeou' => 0);
$exemplosMapeados = array();
$exemplosNaoMapeados = array();

foreach ($rows as $r) {
    $novo = mapearFormaPagamento($r['forma_pagamento']);
    if ($novo === null) {
        // Ou já tá no padrão, ou não conseguiu mapear — checa qual
        $up = mb_strtoupper(trim($r['forma_pagamento']));
        $padroes = array('CARTÃO DE CRÉDITO', 'CRÉDITO RECORRENTE', 'PIX RECORRENTE', 'BOLETO', 'À VISTA');
        if (in_array($up, $padroes, true)) {
            $contadores['ja_padrao']++;
        } else {
            $contadores['nao_mapeou']++;
            if (count($exemplosNaoMapeados) < 10) $exemplosNaoMapeados[] = '#' . $r['id'] . ' [' . $r['forma_pagamento'] . ']';
        }
        continue;
    }
    $contadores['atualizado']++;
    if (count($exemplosMapeados) < 10) {
        $exemplosMapeados[] = '#' . $r['id'] . ' [' . $r['forma_pagamento'] . '] → ' . $novo;
    }
    if (!$dryRun) $stmt->execute(array($novo, $r['id']));
}

echo "Atualizados: " . $contadores['atualizado'] . "\n";
echo "Já no padrão (intocados): " . $contadores['ja_padrao'] . "\n";
echo "Não mapeados (intocados, precisam revisão manual): " . $contadores['nao_mapeou'] . "\n\n";

if (!empty($exemplosMapeados)) {
    echo "=== EXEMPLOS DE MAPEAMENTO ===\n";
    foreach ($exemplosMapeados as $e) echo "  $e\n";
    echo "\n";
}
if (!empty($exemplosNaoMapeados)) {
    echo "=== EXEMPLOS NÃO MAPEADOS ===\n";
    foreach ($exemplosNaoMapeados as $e) echo "  $e\n";
    echo "\n(Esses ficam no banco como estão — usuária normaliza manualmente pelo dropdown quando abrir a planilha.)\n";
}

echo "\n=== FIM ===\n";
