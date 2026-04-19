<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';

$pdo = db();

echo "=== Debug Financeiro ===\n\n";

// Simulador do que o index.php faz
echo "1. Teste can_access_financeiro:\n";
echo "   function_exists: " . (function_exists('can_access_financeiro') ? 'sim' : 'não') . "\n";

echo "\n2. Teste query mesesDisponiveis (UNION):\n";
try {
    $rows = $pdo->query(
        "SELECT DISTINCT DATE_FORMAT(vencimento,'%Y-%m') as m FROM asaas_cobrancas WHERE vencimento IS NOT NULL
         UNION SELECT DISTINCT DATE_FORMAT(data_pagamento,'%Y-%m') as m FROM asaas_cobrancas WHERE data_pagamento IS NOT NULL
         ORDER BY m DESC"
    )->fetchAll(PDO::FETCH_COLUMN);
    echo "   OK, " . count($rows) . " meses\n";
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
}

echo "\n3. Teste query KPIs:\n";
$mesAtual = '2026-04';
try {
    $v = $pdo->query("SELECT IFNULL(SUM(valor),0) FROM asaas_cobrancas WHERE status = 'PENDING' AND DATE_FORMAT(vencimento,'%Y-%m') = '$mesAtual'")->fetchColumn();
    echo "   PENDING mês OK: {$v}\n";
} catch (Exception $e) { echo "   ERRO: " . $e->getMessage() . "\n"; }

echo "\n4. Teste query inadimplentes com ORDER BY dinâmico:\n";
$orderBy = 'dias_atraso DESC';
try {
    $r = $pdo->query(
        "SELECT ac.client_id, cl.name, SUM(ac.valor) as valor_aberto,
         MIN(ac.vencimento) as primeiro_vencimento, DATEDIFF(CURDATE(), MIN(ac.vencimento)) as dias_atraso,
         COUNT(*) as qtd_parcelas
         FROM asaas_cobrancas ac
         LEFT JOIN clients cl ON cl.id = ac.client_id
         WHERE ac.status = 'OVERDUE'
         GROUP BY ac.client_id ORDER BY {$orderBy} LIMIT 5"
    )->fetchAll();
    echo "   OK, " . count($r) . " inadimplentes\n";
} catch (Exception $e) { echo "   ERRO: " . $e->getMessage() . "\n"; }

echo "\n5. Teste includes (helper, middleware):\n";
try {
    require_once __DIR__ . '/core/middleware.php';
    echo "   middleware.php OK\n";
} catch (Throwable $e) { echo "   ERRO: " . $e->getMessage() . "\n"; }
try {
    require_once __DIR__ . '/core/asaas_helper.php';
    echo "   asaas_helper.php OK\n";
} catch (Throwable $e) { echo "   ERRO: " . $e->getMessage() . "\n"; }

echo "\n6. Teste layout_start:\n";
try {
    if (file_exists(APP_ROOT . '/templates/layout_start.php')) {
        echo "   arquivo existe\n";
    } else echo "   arquivo NÃO existe em APP_ROOT=" . APP_ROOT . "\n";
} catch (Throwable $e) { echo "   ERRO: " . $e->getMessage() . "\n"; }

echo "\n7. Pegar últimas linhas do error log PHP:\n";
// Tentar achar o error log do PHP
$candidatos = array(
    APP_ROOT . '/error_log',
    APP_ROOT . '/modules/financeiro/error_log',
    ini_get('error_log'),
    '/home/ferreira/public_html/error_log',
);
foreach ($candidatos as $c) {
    if ($c && is_readable($c)) {
        $lines = file($c);
        $tail = array_slice($lines, -20);
        echo "--- {$c} (últimas " . count($tail) . " linhas) ---\n";
        foreach ($tail as $l) echo "  " . trim($l) . "\n";
        break;
    }
}
