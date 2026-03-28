<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
echo "=== Deploy Hub ===\n\n";
$dir = rtrim(__DIR__, '/');
$tmp = dirname($dir) . '/tmp_hub';
$repo = 'https://github.com/AmandaGF/ferreira-sa-hub.git';
echo "1. Backup config.php...\n";
$configContent = file_get_contents($dir . '/core/config.php');
echo "   OK (" . strlen($configContent) . " bytes)\n\n";
echo "2. Baixando do GitHub...\n";
$zipUrl = $repo . '/archive/refs/heads/main.zip';
// Usar sem o .git no final
$zipUrl = 'https://github.com/AmandaGF/ferreira-sa-hub/archive/refs/heads/main.zip';
$zip = @file_get_contents($zipUrl);
if (!$zip) { die("ERRO: nao conseguiu baixar!\n"); }
$zipFile = $dir . '/tmp_deploy.zip';
file_put_contents($zipFile, $zip);
echo "   OK (" . strlen($zip) . " bytes)\n\n";
echo "3. Extraindo...\n";
$za = new ZipArchive();
if ($za->open($zipFile) !== true) { die("ERRO ao abrir zip!\n"); }
$za->extractTo($tmp);
$za->close();
unlink($zipFile);
echo "   OK\n\n";
$extracted = glob($tmp . '/ferreira-sa-hub-*');
if (empty($extracted)) { die("ERRO: pasta nao encontrada no zip!\n"); }
$src = $extracted[0];
echo "4. Copiando arquivos...\n";
foreach (array('core','assets','templates','auth','modules') as $p) {
    copyDir($src . '/' . $p, $dir . '/' . $p);
    echo "   $p/\n";
}
foreach (array('.htaccess', 'schema.sql') as $f) {
    if (file_exists($src . '/' . $f)) {
        copy($src . '/' . $f, $dir . '/' . $f);
        echo "   $f\n";
    }
}
echo "\n5. Restaurando config.php...\n";
file_put_contents($dir . '/core/config.php', $configContent);
echo "   OK\n\n";
echo "6. Permissoes...\n";
fixPerms($dir);
echo "   OK\n\n";
deleteDir($tmp);
echo "=== Deploy concluido! ===\n";

function copyDir($src, $dst) {
    if (!is_dir($src)) return;
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) { copyDir($s, $d); } else { copy($s, $d); }
    }
}
function deleteDir($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) { deleteDir($path); } else { unlink($path); }
    }
    rmdir($dir);
}
function fixPerms($dir) {
    if (!is_dir($dir)) return;
    @chmod($dir, 0755);
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.git') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) { fixPerms($path); } else { @chmod($path, 0644); }
    }
}
