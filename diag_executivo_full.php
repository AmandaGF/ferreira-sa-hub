<?php
/**
 * Diag completo: simula sessão e roda o index.php do executivo capturando
 * qualquer erro fatal. Mostra a stack na tela.
 *
 * ferreiraesa.com.br/conecta/diag_executivo_full.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Simular sessão admin (id 1 = Amanda) pra passar pelos middlewares
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['user_name'] = 'Amanda (diag)';
// Tokens auxiliares
$_SESSION['csrf_token'] = 'diag';

// Captura tudo
ob_start();

try {
    require __DIR__ . '/modules/executivo/index.php';
    $body = ob_get_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== STATUS: OK ===\n";
    echo "Tamanho da saida: " . strlen($body) . " bytes\n";
    echo "Primeiros 500 chars do HTML:\n";
    echo substr(strip_tags($body), 0, 500) . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== ERRO CAPTURADO ===\n";
    echo "Tipo: " . get_class($e) . "\n";
    echo "Mensagem: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
