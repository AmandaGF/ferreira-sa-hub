<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
foreach (['zapi_mensagens','pipeline_history'] as $t) {
    echo "=== $t ===\n";
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            echo "  {$c['Field']} ({$c['Type']})\n";
        }
    } catch (Exception $e) { echo "  ERRO: ".$e->getMessage()."\n"; }
}
