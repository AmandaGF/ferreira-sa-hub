<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$svdir = dirname(__DIR__) . '/salavip';

// 1) Reset OpCache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OpCache reset: OK\n";
} else {
    echo "OpCache não disponível\n";
}

// 2) Grep recursivo por case_id em todo o salavip
echo "\n=== Ocorrências de 'case_id' ou '.case_id' em TODO o salavip ===\n";
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($svdir));
$patch = 0;
foreach ($it as $f) {
    if ($f->isDir()) continue;
    if (!preg_match('/\.php$/', $f->getFilename())) continue;
    if (strpos($f->getFilename(), '.bak') !== false) continue;
    $path = $f->getPathname();
    $c = file_get_contents($path);
    if (strpos($c, 'case_id') === false) continue;
    echo "\n-- " . str_replace($svdir . '/', '', $path) . " --\n";
    foreach (explode("\n", $c) as $i => $line) {
        if (strpos($line, 'case_id') !== false) echo "  linha " . ($i+1) . ": " . trim($line) . "\n";
    }
}

// 3) Simular sessão da cliente 1003 e chamar mensagem_ver.php id=8
echo "\n\n=== TESTE FINAL: mensagem_ver.php?id=8 como cliente 1003 ===\n";
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

try {
    ob_start();
    require $svdir . '/pages/mensagem_ver.php';
    $html = ob_get_clean();
    echo "RENDERIZOU " . strlen($html) . " bytes — OK\n";
} catch (Throwable $e) {
    @ob_end_clean();
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "em " . $e->getFile() . ":" . $e->getLine() . "\n";
}
