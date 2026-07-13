<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$svdir = dirname(__DIR__) . '/salavip';

// FIX 1: mensagem_ver.php
$f = $svdir . '/pages/mensagem_ver.php';
$c = file_get_contents($f);
$c2 = str_replace('LEFT JOIN cases c ON c.id = t.case_id', 'LEFT JOIN cases c ON c.id = t.processo_id', $c);
if ($c === $c2) {
    echo "mensagem_ver.php: nada pra trocar (ja corrigido)\n";
} else {
    file_put_contents($f . '.bak-' . date('Ymd_His'), $c);
    $written = file_put_contents($f, $c2);
    echo "mensagem_ver.php: $written bytes escritos\n";
    // Reler pra confirmar
    clearstatcache();
    $verifica = file_get_contents($f);
    if (strpos($verifica, 't.processo_id') !== false && strpos($verifica, 't.case_id') === false) {
        echo "mensagem_ver.php: verificado, esta com 't.processo_id' agora\n";
    } else {
        echo "mensagem_ver.php: ATENCAO - relei e ainda tem 't.case_id'!\n";
    }
}

// FIX 2: reset opcache pra bytecode antigo nao servir
if (function_exists('opcache_reset')) opcache_reset();
if (function_exists('opcache_invalidate')) opcache_invalidate($f, true);
echo "OpCache invalidado.\n";

// FIX 3: testar
echo "\n=== TESTE ===\n";
session_name('salavip_session');
session_start();
$_SESSION['salavip_user_id'] = 1;
$_SESSION['salavip_cliente_id'] = 1003;
$_SESSION['salavip_nome_exibicao'] = 'X'; $_SESSION['salavip_email'] = 'x';
$_SESSION['salavip_ultimo_atividade'] = time(); $_SESSION['salavip_logado_em'] = date('Y-m-d H:i:s');
$_GET['id'] = 8;
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
    ob_start();
    require $f;
    $html = ob_get_clean();
    echo "OK renderizou " . strlen($html) . " bytes\n";
} catch (Throwable $e) {
    @ob_end_clean();
    echo "ERRO: " . $e->getMessage() . "\nem " . $e->getFile() . ":" . $e->getLine() . "\n";
}
