<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== DIAG email diario DJEN ===\n\n";

// Ver ultimas execuções do claudin
try {
    $cols = $pdo->query("SHOW TABLES LIKE '%claudin%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "-- Tabelas claudin --\n" . implode(', ', $cols) . "\n\n";
} catch (Throwable $e) {}

// Log runs
try {
    $st = $pdo->query("SELECT * FROM claudin_runs ORDER BY id DESC LIMIT 10");
    echo "-- Ultimas 10 execucoes do cron --\n";
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach ($r as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25) . ": $v\n";
        echo "---\n";
    }
} catch (Throwable $e) { echo "ERRO claudin_runs: " . $e->getMessage() . "\n"; }

// Ver ultimas publicacoes capturadas
echo "\n-- Ultimas 5 publicacoes em case_publicacoes --\n";
try {
    $st = $pdo->query("SELECT id, case_id, tribunal, tipo_publicacao, data_disponibilizacao, created_at FROM case_publicacoes ORDER BY id DESC LIMIT 5");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  #$r[id] case=$r[case_id] · $r[tribunal] · $r[tipo_publicacao] · disp=$r[data_disponibilizacao] · em $r[created_at]\n";
    }
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

// Ver config claudin
echo "\n-- Configs relacionadas --\n";
try {
    $st = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE '%djen%' OR chave LIKE '%claudin%' OR chave LIKE '%brevo%'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v = $r['chave'] === 'brevo_api_key' ? (empty($r['valor']) ? '(vazio)' : '(setado, oculto)') : $r['valor'];
        echo "  $r[chave] = $v\n";
    }
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

// Log file do claudin
$logPath = __DIR__ . '/logs/claudin.log';
if (!file_exists($logPath)) $logPath = __DIR__ . '/cron/claudin.log';
if (file_exists($logPath)) {
    echo "\n-- Ultimas 30 linhas do log ($logPath) --\n";
    $lines = @file($logPath);
    if ($lines) {
        $tail = array_slice($lines, -30);
        foreach ($tail as $l) echo $l;
    }
} else {
    echo "\n-- Log nao encontrado em locais padrao --\n";
}
