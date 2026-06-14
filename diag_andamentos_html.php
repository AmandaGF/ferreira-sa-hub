<?php
/**
 * Testa limpar_html_juridico() em andamentos reais que contêm HTML cru.
 * Mostra antes/depois. Não altera nada no banco.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
$pdo = db();

echo "=== Teste limpar_html_juridico em andamentos ===\n\n";

// Andamentos que parecem ter HTML cru (tag ou entidade nomeada)
$rows = $pdo->query("SELECT id, case_id, LEFT(descricao, 4000) descricao
    FROM case_andamentos
    WHERE descricao REGEXP '<[a-zA-Z/]|&[a-zA-Z]+;'
    ORDER BY id DESC LIMIT 5")->fetchAll();

echo "Andamentos com HTML detectado: " . count($rows) . " (mostrando até 5)\n";
echo str_repeat('=', 60) . "\n\n";

foreach ($rows as $r) {
    echo "--- andamento #{$r['id']} (caso {$r['case_id']}) ---\n";
    // janela em torno da PRIMEIRA tag/entidade pra ver o HTML cru de fato
    $pos = 0;
    if (preg_match('/<[a-zA-Z\/]|&[a-zA-Z]+;/', $r['descricao'], $m, PREG_OFFSET_CAPTURE)) {
        $pos = max(0, $m[0][1] - 60);
    }
    $limpo = limpar_html_juridico($r['descricao']);
    echo "[ANTES — janela do HTML]\n..." . substr($r['descricao'], $pos, 700) . "...\n\n";
    echo "[DEPOIS — final do texto limpo]\n..." . substr($limpo, max(0, mb_strlen($limpo)-700)) . "\n";
    echo str_repeat('-', 60) . "\n\n";
}

// Sanidade: texto puro não pode ser alterado
$amostras = array(
    'Petição protocolada hoje. Valor R$ 1.000,00.',
    'Prazo de 3<5 dias e 10>2 horas (comparação solta).',
    'Cliente AT&T comunicado.',
);
echo "=== Sanidade (texto puro deve passar intacto) ===\n";
foreach ($amostras as $a) {
    $out = limpar_html_juridico($a);
    echo ($out === $a ? 'OK   ' : 'MUDOU') . " | \"$a\" -> \"$out\"\n";
}

echo "\n=== FIM ===\n";
