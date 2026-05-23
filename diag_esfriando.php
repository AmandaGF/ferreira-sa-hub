<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Negado.'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
foreach (array('zapi_mensagens','zapi_conversas','honorarios_cobrancas','case_tasks') as $t) {
    echo "=== $t ===\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) echo "  {$c['Field']}  ({$c['Type']})\n";
    } catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
    echo "\n";
}
