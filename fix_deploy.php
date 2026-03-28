<?php
/**
 * Temporariamente remove GITHUB_TOKEN do config.php para o deploy funcionar
 * e depois roda o deploy com URL publica
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$cfgPath = __DIR__ . '/core/config.php';
$cfg = file_get_contents($cfgPath);

// Salvar backup
$backup = $cfg;

// Remover GITHUB_TOKEN temporariamente
$cfg = preg_replace('/\n\/\/ GitHub Token.*\ndefine\(\'GITHUB_TOKEN\'.*\n/', "\n", $cfg);
file_put_contents($cfgPath, $cfg);
echo "1. GITHUB_TOKEN removido temporariamente do config.php\n";

// Forcar deploy2.php a usar URL publica
// Sobrescrever deploy2.php com versao que usa URL publica
$oldDeploy = file_get_contents(__DIR__ . '/deploy2.php');

// Rodar deploy via URL publica (incluindo o proprio arquivo)
echo "2. Rodando deploy via URL publica...\n";
$url = 'https://github.com/AmandaGF/ferreira-sa-hub/archive/refs/heads/main.zip';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
$data = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if (!$data || $err) {
    // Restaurar config
    file_put_contents($cfgPath, $backup);
    die("ERRO download: $err\n");
}
echo "   Download OK (" . strlen($data) . " bytes)\n";

$zf = __DIR__ . '/tmp_d.zip';
file_put_contents($zf, $data);

$za = new ZipArchive();
$r = $za->open($zf);
if ($r !== true) {
    @unlink($zf);
    file_put_contents($cfgPath, $backup);
    die("ERRO ao abrir ZIP (code $r)\n");
}

$firstName = $za->getNameIndex(0);
$prefix = substr($firstName, 0, strpos($firstName, '/') + 1);
$prefixLen = strlen($prefix);
echo "   Prefixo: $prefix\n";

$dir = rtrim(__DIR__, '/');
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
echo "   $count arquivos extraidos\n";

// Restaurar config.php COM o token
echo "3. Restaurando config.php com GITHUB_TOKEN...\n";
file_put_contents($cfgPath, $backup);
echo "   OK\n";

echo "\n=== DEPLOY CONCLUIDO! $count arquivos ===\n";
echo "O deploy2.php agora suporta repo privado.\n";
echo "Pode colocar o repo em modo privado.\n";
