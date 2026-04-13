<?php
/**
 * Deploy da Sala VIP — copia arquivos para /salavip/ no public_html
 * Roda a migração SQL e cria estrutura de pastas
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$sourceDir = __DIR__ . '/salavip_src';
$destDir = dirname(__DIR__) . '/salavip';

// 1. Verificar se fonte existe
if (!is_dir($sourceDir)) {
    // Tentar via GitHub (salavip está no mesmo nível que conecta no repo)
    // Se não existir, extrair do zip do repo
    echo "Fonte não encontrada em $sourceDir\n";
    echo "Tentando via download...\n";

    // Download do repo como zip
    $zipUrl = 'https://github.com/AmandaGF/ferreira-sa-hub/archive/refs/heads/main.zip';
    $zipPath = sys_get_temp_dir() . '/salavip_deploy.zip';

    $ch = curl_init($zipUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $zipData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$zipData) {
        die("Erro ao baixar repo: HTTP $httpCode\n");
    }

    file_put_contents($zipPath, $zipData);
    echo "ZIP baixado: " . strlen($zipData) . " bytes\n";

    // Extrair apenas a pasta salavip/
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        die("Erro ao abrir ZIP\n");
    }

    // Criar destino
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $prefix = 'ferreira-sa-hub-main/salavip/';
    $extracted = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, $prefix) !== 0) continue;

        $relPath = substr($name, strlen($prefix));
        if ($relPath === '' || $relPath === false) continue;

        $fullDest = $destDir . '/' . $relPath;

        if (substr($name, -1) === '/') {
            if (!is_dir($fullDest)) mkdir($fullDest, 0755, true);
        } else {
            $dir = dirname($fullDest);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($fullDest, $zip->getFromIndex($i));
            chmod($fullDest, 0644);
            $extracted++;
        }
    }
    $zip->close();
    unlink($zipPath);
    echo "Extraídos: $extracted arquivos\n";

} else {
    // Copiar diretamente
    echo "Fonte encontrada em $sourceDir\n";

    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    function copyDir($src, $dst) {
        $count = 0;
        $dir = opendir($src);
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (is_dir($srcPath)) {
                $count += copyDir($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
                chmod($dstPath, 0644);
                $count++;
            }
        }
        closedir($dir);
        return $count;
    }

    $copied = copyDir($sourceDir, $destDir);
    echo "Copiados: $copied arquivos\n";
}

// 2. Garantir pastas necessárias
$dirs = ['uploads', 'assets/img', 'assets/css', 'assets/js', 'includes', 'pages', 'api'];
foreach ($dirs as $d) {
    $p = $destDir . '/' . $d;
    if (!is_dir($p)) { mkdir($p, 0755, true); echo "Pasta criada: $d\n"; }
}

// 3. Criar .htaccess do uploads se não existir
$htUploads = $destDir . '/uploads/.htaccess';
if (!file_exists($htUploads)) {
    file_put_contents($htUploads, "Deny from all\n");
    echo "uploads/.htaccess criado\n";
}

// 4. Permissões
chmod($destDir . '/uploads', 0755);

// 5. Rodar migração SQL
echo "\n=== Rodando migração SQL ===\n";
$migrateFile = $destDir . '/migrate.php';
if (file_exists($migrateFile)) {
    // Simular o GET key
    $_GET['key'] = 'fsa-hub-deploy-2026';
    ob_start();
    include $migrateFile;
    echo ob_get_clean();
} else {
    echo "migrate.php não encontrado em $migrateFile\n";
}

echo "\n=== DEPLOY SALA VIP CONCLUÍDO ===\n";
echo "URL: https://www.ferreiraesa.com.br/salavip/\n";
