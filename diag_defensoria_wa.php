<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "-- Colunas zapi_conversas --\n";
echo implode(', ', $pdo->query("SHOW COLUMNS FROM zapi_conversas")->fetchAll(PDO::FETCH_COLUMN,0)) . "\n\n";

echo "-- Colunas zapi_mensagens --\n";
echo implode(', ', $pdo->query("SHOW COLUMNS FROM zapi_mensagens")->fetchAll(PDO::FETCH_COLUMN,0)) . "\n\n";

echo "-- zapi_conversas com 'defensoria' no nome --\n";
try {
    $st = $pdo->query("SELECT * FROM zapi_conversas WHERE nome_contato LIKE '%efensoria%' LIMIT 5");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach ($r as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25).": $v\n";
        echo "---\n";
        // pega ultimas msgs
        $st2 = $pdo->prepare("SELECT * FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 8");
        $st2->execute(array($r['id']));
        echo "  -- ultimas mensagens conversa #{$r['id']} --\n";
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $m) {
            foreach ($m as $k=>$v) if ($v !== null && $v !== '') echo "    " . str_pad($k,22).": $v\n";
            echo "    ---\n";
        }
    }
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }
