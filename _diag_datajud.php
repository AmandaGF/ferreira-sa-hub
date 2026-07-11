<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAG DataJud ===\n\n";

echo "== Contadores ==\n";
$total   = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE case_number IS NOT NULL AND case_number <> '' AND status NOT IN ('arquivado','cancelado')")->fetchColumn();
$sync    = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE datajud_sincronizado = 1 AND status NOT IN ('arquivado','cancelado')")->fetchColumn();
$erro    = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE datajud_erro IS NOT NULL AND datajud_erro <> '' AND status NOT IN ('arquivado','cancelado')")->fetchColumn();
$nunca   = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE datajud_ultima_sync IS NULL AND case_number IS NOT NULL AND case_number <> '' AND status NOT IN ('arquivado','cancelado')")->fetchColumn();
$sync7d  = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE tipo_origem='datajud' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$syncTd  = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE datajud_ultima_sync >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status NOT IN ('arquivado','cancelado')")->fetchColumn();

echo "Cases ativos com CNJ:       $total\n";
echo "  Sincronizados:            $sync\n";
echo "  Com erro:                 $erro\n";
echo "  Nunca sincronizados:      $nunca\n";
echo "  Sincronizados <24h:       $syncTd\n";
echo "Movimentos importados (7d): $sync7d\n\n";

echo "== TIPOS de erro (top 10) ==\n";
$stmt = $pdo->query("
    SELECT SUBSTRING(datajud_erro, 1, 80) AS erro, COUNT(*) q
    FROM cases
    WHERE datajud_erro IS NOT NULL AND datajud_erro <> '' AND status NOT IN ('arquivado','cancelado')
    GROUP BY SUBSTRING(datajud_erro, 1, 80)
    ORDER BY q DESC LIMIT 10
");
foreach ($stmt as $r) printf("  %4d | %s\n", $r['q'], $r['erro']);

echo "\n== ULTIMOS SYNCS ==\n";
$stmt = $pdo->query("
    SELECT datajud_ultima_sync, case_number, datajud_sincronizado, SUBSTRING(datajud_erro,1,60) e
    FROM cases
    WHERE datajud_ultima_sync IS NOT NULL
    ORDER BY datajud_ultima_sync DESC LIMIT 10
");
foreach ($stmt as $r) printf("  %s | sync=%d | %s | %s\n", $r['datajud_ultima_sync'], $r['datajud_sincronizado'], $r['case_number'], $r['e'] ?: 'OK');

echo "\n== Configuracao API key ==\n";
$key = defined('DATAJUD_API_KEY') ? DATAJUD_API_KEY : (getenv('DATAJUD_API_KEY') ?: '');
if (empty($key)) {
    echo "  ⚠ DATAJUD_API_KEY nao definido! Sync nao funciona sem key.\n";
} else {
    echo "  ✓ DATAJUD_API_KEY definido (len=" . strlen($key) . ", inicio=" . substr($key, 0, 8) . "...)\n";
}

// Testa uma chamada real com um processo conhecido
echo "\n== TESTE de chamada real (1 processo) ==\n";
$stmt = $pdo->query("SELECT id, case_number FROM cases WHERE case_number IS NOT NULL AND case_number <> '' AND status NOT IN ('arquivado','cancelado') AND (datajud_erro IS NOT NULL OR datajud_sincronizado = 0) ORDER BY id DESC LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "  Testando case #$row[id] CNJ={$row['case_number']}\n";
    require_once __DIR__ . '/core/functions_datajud.php';
    $t0 = microtime(true);
    $r = datajud_sync_case($pdo, (int)$row['id']);
    $ms = round((microtime(true) - $t0) * 1000);
    echo "  Duracao: ${ms}ms\n";
    echo "  Resultado: " . json_encode($r) . "\n";
} else {
    echo "  Nenhum case com erro/nao sincronizado pra testar.\n";
}
