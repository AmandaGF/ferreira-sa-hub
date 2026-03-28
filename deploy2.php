<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);
// Carregar config para ler GITHUB_TOKEN
$cfgFile = __DIR__ . '/core/config.php';
if (file_exists($cfgFile)) { require_once $cfgFile; }
echo "=== Deploy Hub ===\n\n";
$dir = rtrim(__DIR__, '/');

echo "1. Backup config + deploy...\n";
$cfg = file_get_contents($dir . '/core/config.php');
$dep = file_get_contents(__FILE__);
echo "   OK\n\n";

echo "2. Baixando ZIP com cURL...\n";
$ghToken = defined('GITHUB_TOKEN') ? GITHUB_TOKEN : '';
if ($ghToken) {
    $url = 'https://api.github.com/repos/AmandaGF/ferreira-sa-hub/zipball/main';
} else {
    $url = 'https://github.com/AmandaGF/ferreira-sa-hub/archive/refs/heads/main.zip';
}
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'FES-Deploy');
if ($ghToken) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: token ' . $ghToken));
}
$data = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
if (!$data || $err) { die("ERRO download: $err\n"); }
$zf = $dir . '/tmp_d.zip';
file_put_contents($zf, $data);
echo "   OK (" . strlen($data) . " bytes)\n\n";

echo "3. Extraindo arquivo por arquivo...\n";
$za = new ZipArchive();
$r = $za->open($zf);
if ($r !== true) { unlink($zf); die("ERRO ao abrir ZIP (code $r)\n"); }
// Detectar prefixo dinamicamente (API usa hash no nome)
$firstName = $za->getNameIndex(0);
$prefix = substr($firstName, 0, strpos($firstName, '/') + 1);
$prefixLen = strlen($prefix);
echo "   Prefixo: $prefix\n";
$count = 0;
for ($i = 0; $i < $za->numFiles; $i++) {
    $name = $za->getNameIndex($i);
    if (strpos($name, $prefix) !== 0) continue;
    $rel = substr($name, $prefixLen);
    if ($rel === '' || $rel === false) continue;
    $target = $dir . '/' . $rel;
    if (substr($name, -1) === '/') {
        if (!is_dir($target)) { @mkdir($target, 0755, true); }
    } else {
        $tdir = dirname($target);
        if (!is_dir($tdir)) { @mkdir($tdir, 0755, true); }
        file_put_contents($target, $za->getFromIndex($i));
        @chmod($target, 0644);
        $count++;
    }
}
$za->close();
@unlink($zf);
echo "   $count arquivos extraidos\n\n";

echo "4. Restaurando config + deploy...\n";
file_put_contents($dir . '/core/config.php', $cfg);
file_put_contents($dir . '/deploy2.php', $dep);
echo "   OK\n\n";

echo "5. Permissoes...\n";
fixP($dir);
echo "   OK\n\n";

echo "=== DEPLOY CONCLUIDO! ===\n";
echo "Arquivos atualizados: $count\n";

function fixP($d) {
    if (!is_dir($d)) return;
    @chmod($d, 0755);
    foreach (scandir($d) as $i) {
        if ($i === '.' || $i === '..' || $i === '.git') continue;
        $p = $d . '/' . $i;
        if (is_dir($p)) { fixP($p); } else { @chmod($p, 0644); }
    }
}
