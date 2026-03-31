<?php
/**
 * Fix temporário: atualiza deploy2.php e add_github_token.php no servidor
 * usando raw.githubusercontent.com
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$dir = rtrim(__DIR__, '/');
// Usa token do config se disponível
$cfgFile = __DIR__ . '/core/config.php';
if (file_exists($cfgFile)) { require_once $cfgFile; }
$token = defined('GITHUB_TOKEN') ? GITHUB_TOKEN : '';

$files = array(
    'deploy2.php' => 'https://raw.githubusercontent.com/AmandaGF/ferreira-sa-hub/main/deploy2.php',
    'add_github_token.php' => 'https://raw.githubusercontent.com/AmandaGF/ferreira-sa-hub/main/add_github_token.php',
);

foreach ($files as $local => $url) {
    echo "Atualizando $local...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FES-Deploy');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: token ' . $token));
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($data && strlen($data) > 100 && $code == 200) {
        file_put_contents($dir . '/' . $local, $data);
        echo "  OK (" . strlen($data) . " bytes)\n";
    } else {
        echo "  ERRO: code=$code, size=" . strlen($data) . ", err=$err\n";
        // Tentar sem auth
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FES-Deploy');
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data && strlen($data) > 100 && $code == 200) {
            file_put_contents($dir . '/' . $local, $data);
            echo "  OK sem auth (" . strlen($data) . " bytes)\n";
        } else {
            echo "  FALHA TOTAL: code=$code, size=" . strlen($data) . "\n";
        }
    }
}

echo "\nFeito! Agora rode deploy2.php novamente.\n";
