<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

// Simular sessão da cliente 1003 e chamar mensagem_ver.php
session_name('salavip_session');
session_start();
$_SESSION['salavip_user_id'] = 1;
$_SESSION['salavip_cliente_id'] = 1003;
$_SESSION['salavip_nome_exibicao'] = 'Cliente Teste';
$_SESSION['salavip_email'] = 'teste@teste.com';
$_SESSION['salavip_ultimo_atividade'] = time();
$_SESSION['salavip_logado_em'] = date('Y-m-d H:i:s');

$_GET['id'] = 8;
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "=== Executando mensagem_ver.php id=8 como cliente 1003 ===\n\n";

try {
    ob_start();
    require dirname(__DIR__) . '/salavip/pages/mensagem_ver.php';
    $html = ob_get_clean();
    echo "OK renderizou " . strlen($html) . " bytes\n";
    // Se tiver erro embutido no HTML, mostrar
    if (stripos($html, 'error') !== false || stripos($html, 'warning') !== false || stripos($html, 'notice') !== false || stripos($html, 'fatal') !== false) {
        $pos = stripos($html, 'error');
        echo "\n[possivel erro no output]:\n" . substr($html, max(0, $pos-100), 600) . "\n";
    }
} catch (Throwable $e) {
    @ob_end_clean();
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "em: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTRACE:\n" . $e->getTraceAsString() . "\n";
}
