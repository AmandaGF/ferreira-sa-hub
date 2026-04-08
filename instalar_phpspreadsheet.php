<?php
/**
 * Instala PhpSpreadsheet no servidor via Composer
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

$dir = __DIR__;
echo "=== Instalando PhpSpreadsheet ===\n\n";

// 1. Baixar composer.phar se não existir
$composerPath = $dir . '/composer.phar';
if (!file_exists($composerPath)) {
    echo "1. Baixando composer.phar...\n";
    $ch = curl_init('https://getcomposer.org/download/latest-stable/composer.phar');
    $fp = fopen($composerPath, 'w');
    curl_setopt_array($ch, array(
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if ($err) { echo "ERRO: $err\n"; exit; }
    chmod($composerPath, 0755);
    echo "   OK (" . filesize($composerPath) . " bytes)\n\n";
} else {
    echo "1. composer.phar já existe\n\n";
}

// 2. Criar composer.json
echo "2. Criando composer.json...\n";
$composerJson = json_encode(array(
    'require' => array(
        'phpoffice/phpspreadsheet' => '^1.29'
    ),
    'config' => array(
        'vendor-dir' => 'vendor',
        'optimize-autoloader' => true,
    ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($dir . '/composer.json', $composerJson);
echo "   OK\n\n";

// 3. Rodar composer install
echo "3. Rodando composer install...\n";
$phpBin = PHP_BINARY ?: 'php';
$cmd = "$phpBin $composerPath install --no-dev --optimize-autoloader --working-dir=$dir 2>&1";
echo "   CMD: $cmd\n";
$output = shell_exec($cmd);
echo $output . "\n";

// 4. Verificar
if (file_exists($dir . '/vendor/autoload.php')) {
    echo "\n=== SUCESSO! PhpSpreadsheet instalado! ===\n";
    // Limpar composer.phar para não ficar no repo
    // @unlink($composerPath);
} else {
    echo "\n=== ERRO: vendor/autoload.php não encontrado ===\n";
    echo "Tentando método alternativo...\n\n";

    // Método alternativo: baixar ZIP direto do GitHub
    echo "Baixando PhpSpreadsheet via GitHub...\n";
    $zipUrl = 'https://github.com/PHPOffice/PhpSpreadsheet/archive/refs/tags/1.29.0.zip';
    $zipFile = $dir . '/phpspreadsheet.zip';
    $ch = curl_init($zipUrl);
    $fp = fopen($zipFile, 'w');
    curl_setopt_array($ch, array(
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'FES-Deploy',
    ));
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    echo "   HTTP: $httpCode (" . filesize($zipFile) . " bytes)\n";

    if ($httpCode === 200 && filesize($zipFile) > 10000) {
        echo "   OK, ZIP baixado\n";
    } else {
        echo "   ERRO ao baixar\n";
    }
}
