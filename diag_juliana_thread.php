<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== DIAG chamado Juliana - thread #7 Central VIP ===\n\n";

// Thread
$st = $pdo->query("SELECT * FROM salavip_threads WHERE id = 7");
$t = $st->fetch(PDO::FETCH_ASSOC);
echo "-- Thread --\n";
foreach ($t as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25).": $v\n";
echo "\n";

// Mensagens
echo "-- Mensagens do thread #7 --\n";
$st = $pdo->query("SELECT * FROM salavip_mensagens WHERE thread_id = 7 ORDER BY id");
$msgs = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Total: " . count($msgs) . "\n\n";
foreach ($msgs as $m) {
    echo "-- msg #$m[id] --\n";
    foreach ($m as $k=>$v) {
        if ($v === null || $v === '') continue;
        if ($k === 'mensagem') { echo str_pad($k,25).": " . mb_substr($v, 0, 200) . (mb_strlen($v)>200?'…':'') . "\n"; }
        else { echo str_pad($k,25).": $v\n"; }
    }
    echo "\n";
}

// Cliente Juliana
echo "-- Cliente Juliana #881 --\n";
$st = $pdo->query("SELECT id, name, cpf, email, phone FROM clients WHERE id = 881");
foreach ($st->fetch(PDO::FETCH_ASSOC) as $k=>$v) echo str_pad($k,15).": $v\n";
echo "\n";

// Central VIP tem conta ativa da Juliana?
echo "-- Central VIP usuario da Juliana --\n";
try {
    $st = $pdo->query("SELECT id, cliente_id, cpf, email, nome_exibicao, ativo, ultimo_acesso, tentativas_login, bloqueado_ate, created_at FROM salavip_usuarios WHERE cliente_id = 881 OR cpf LIKE '%19902303773%'");
    $usrs = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "Total: " . count($usrs) . "\n";
    foreach ($usrs as $u) {
        foreach ($u as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25).": $v\n";
        echo "\n";
    }
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

// Ultimo acesso da Juliana em logs
echo "-- Ultimos acessos da Juliana --\n";
try {
    $st = $pdo->prepare("SELECT * FROM salavip_logs_acesso WHERE cliente_id = 881 OR salavip_usuario_id IN (SELECT id FROM salavip_usuarios WHERE cliente_id = 881) ORDER BY created_at DESC LIMIT 10");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        foreach ($l as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25).": $v\n";
        echo "---\n";
    }
} catch (Throwable $e) { echo "ERRO ou tabela nao existe: " . $e->getMessage() . "\n"; }

// Colunas salavip_usuarios
echo "\n-- Colunas salavip_usuarios --\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM salavip_usuarios")->fetchAll(PDO::FETCH_COLUMN, 0);
    echo implode(', ', $cols) . "\n";
} catch (Throwable $e) { echo $e->getMessage() . "\n"; }
