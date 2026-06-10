<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Colunas reais da tabela planilha_debito ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM planilha_debito") as $col) {
    echo "  {$col['Field']} ({$col['Type']}) NULL={$col['Null']}\n";
}

echo "\n=== Ultimas 5 entradas (raw) ===\n";
$st = $pdo->query("SELECT id, titulo, case_id, client_id, created_by, created_at FROM planilha_debito ORDER BY id DESC LIMIT 5");
foreach ($st as $r) {
    echo "  #{$r['id']} | titulo={$r['titulo']}\n";
    echo "    case_id=" . var_export($r['case_id'], true) . " | client_id=" . var_export($r['client_id'], true) . "\n";
    echo "    created_by={$r['created_by']} | em={$r['created_at']}\n";
}

echo "\n=== Query da listagem (igual ao index.php) ===\n";
$rows = $pdo->query("SELECT pd.id, pd.titulo, pd.case_id, pd.client_id, u.name as user_name, cs.title as case_title, cl.name as client_name
                     FROM planilha_debito pd
                     LEFT JOIN users u ON u.id = pd.created_by
                     LEFT JOIN cases cs ON cs.id = pd.case_id
                     LEFT JOIN clients cl ON cl.id = pd.client_id
                     ORDER BY pd.id DESC LIMIT 5")->fetchAll();
foreach ($rows as $r) {
    echo "  #{$r['id']} | case_id=" . ($r['case_id'] ?? 'NULL') . " (case_title=" . ($r['case_title'] ?? 'NULL') . ")\n";
    echo "          | client_id=" . ($r['client_id'] ?? 'NULL') . " (client_name=" . ($r['client_name'] ?? 'NULL') . ")\n";
}
