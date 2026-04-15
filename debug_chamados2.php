<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "=== Tentando carregar chamados.php ===\n\n";

// Carregar fake session de cliente
$base = dirname(__DIR__) . '/salavip';
session_name('salavip_session');
session_start();

// Simular cliente logado (usar primeiro cliente que tenha CPF)
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$cliente = $pdo->query("SELECT id FROM clients WHERE cpf IS NOT NULL ORDER BY id LIMIT 1")->fetch();
if ($cliente) {
    $_SESSION['salavip_cliente_id'] = (int)$cliente['id'];
    $_SESSION['salavip_user'] = array('id' => $cliente['id'], 'name' => 'TESTE');
    echo "Cliente fake: #{$cliente['id']}\n\n";
}

// Tentar carregar chamados.php
echo "--- Carregando chamados.php ---\n";
try {
    ob_start();
    require $base . '/pages/chamados.php';
    $out = ob_get_clean();
    echo "[OK] Carregou! Tamanho: " . strlen($out) . " bytes\n";
} catch (Throwable $e) {
    $out = ob_get_clean();
    echo "[ERRO] " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "Em: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Stack:\n" . $e->getTraceAsString() . "\n";
}
