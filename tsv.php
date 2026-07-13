<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

// Executar a query problematica do mensagem_ver.php
require_once dirname(__DIR__) . '/salavip/config.php';
$pdo = sv_db();

echo "=== Query da mensagem_ver.php ===\n";
try {
    $q = $pdo->prepare("SELECT m.*, COALESCE(u.name, m.remetente_nome) AS remetente_nome_atual
                        FROM salavip_mensagens m
                        LEFT JOIN users u ON u.id = m.remetente_id AND u.is_active = 1
                        WHERE m.thread_id = ?
                        ORDER BY m.criado_em ASC");
    $q->execute(array(1));
    echo "OK\n";
    var_dump($q->fetchAll());
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== Testar tabela salavip_mensagens ===\n";
try {
    foreach ($pdo->query("DESCRIBE salavip_mensagens") as $r) echo "  " . $r['Field'] . " (" . $r['Type'] . ")\n";
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== Log salavip ATUAL ===\n";
$log = dirname(__DIR__) . '/salavip/error_log';
echo "Mtime: " . date('Y-m-d H:i:s', filemtime($log)) . "\n";
echo substr(file_get_contents($log), -3500);

echo "\n\n=== Ultimos threads ===\n";
try {
    foreach ($pdo->query("SELECT id, cliente_id, assunto, status, created_at FROM salavip_threads ORDER BY id DESC LIMIT 5") as $r) print_r($r);
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }
