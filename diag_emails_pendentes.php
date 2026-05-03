<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Email Monitor — fila de pendentes ===\n\n";

try {
    $st = $pdo->query("SELECT status, COUNT(*) AS n FROM email_monitor_pendentes GROUP BY status");
    $totais = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($totais as $s => $n) echo "  $s: $n\n";
    if (!$totais) echo "  (tabela vazia)\n";
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }

echo "\n=== Ultimas 5 execucoes do cron ===\n";
try {
    $st = $pdo->query("SELECT executado_em, emails_lidos, andamentos_inseridos, emails_ignorados, duplicatas_ignoradas, erros
                       FROM email_monitor_log ORDER BY executado_em DESC LIMIT 5");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  {$r['executado_em']}  lidos={$r['emails_lidos']}  inseridos={$r['andamentos_inseridos']}  ignorados={$r['emails_ignorados']}  dup={$r['duplicatas_ignoradas']}  erros={$r['erros']}\n";
    }
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }
