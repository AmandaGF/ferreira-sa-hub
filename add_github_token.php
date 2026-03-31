<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : 'token';

if ($action === 'fix_deploy') {
    // Temporariamente comentar GITHUB_TOKEN no config para deploy usar URL publica
    $cfgPath = __DIR__ . '/core/config.php';
    $cfg = file_get_contents($cfgPath);
    $cfgBackup = $cfg;

    // Comentar a linha do GITHUB_TOKEN
    $cfg = str_replace("define('GITHUB_TOKEN'", "// define('GITHUB_TOKEN'", $cfg);
    file_put_contents($cfgPath, $cfg);
    echo "1. GITHUB_TOKEN desativado temporariamente\n";

    // Agora rodar deploy via URL publica
    echo "2. Baixando ZIP (URL publica)...\n";
    $url = 'https://codeload.github.com/AmandaGF/ferreira-sa-hub/zip/refs/heads/main';
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

    if (!$data || strlen($data) < 1000 || $err) {
        file_put_contents($cfgPath, $cfgBackup);
        die("ERRO download: " . ($err ? $err : "tamanho=" . strlen($data)) . "\nConfig restaurado.\n");
    }
    echo "   OK (" . strlen($data) . " bytes)\n";

    $dir = rtrim(__DIR__, '/');
    $zf = $dir . '/tmp_fix.zip';
    file_put_contents($zf, $data);

    $za = new ZipArchive();
    $r = $za->open($zf);
    if ($r !== true) {
        @unlink($zf);
        file_put_contents($cfgPath, $cfgBackup);
        die("ERRO ZIP (code $r)\nConfig restaurado.\n");
    }

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
    echo "   $count arquivos extraidos\n";

    // Restaurar config COM token
    echo "3. Restaurando config.php com GITHUB_TOKEN...\n";
    file_put_contents($cfgPath, $cfgBackup);
    echo "   OK\n\n";

    echo "=== DEPLOY CONCLUIDO! $count arquivos ===\n";
    echo "deploy2.php atualizado para suportar repo privado.\n";
    exit;
}

// Gerar ENCRYPT_KEY
if ($action === 'gen_key') {
    $cfgPath = __DIR__ . '/core/config.php';
    $cfg = file_get_contents($cfgPath);
    $newKey = bin2hex(random_bytes(32));
    $cfg = preg_replace("/define\('ENCRYPT_KEY',\s*'[^']*'\)/", "define('ENCRYPT_KEY', '$newKey')", $cfg);
    file_put_contents($cfgPath, $cfg);
    echo "ENCRYPT_KEY gerada: " . substr($newKey, 0, 10) . "...\n";
    echo "Agora rode o seed_links.php\n";
    exit;
}

// Corrigir banco
if ($action === 'fix_db') {
    $u = isset($_GET['u']) ? $_GET['u'] : '';
    $p = isset($_GET['p']) ? $_GET['p'] : '';
    if (!$u || !$p) { die("Use: &u=USUARIO&p=SENHA\n"); }
    $cfgPath = __DIR__ . '/core/config.php';
    $cfg = file_get_contents($cfgPath);
    $cfg = preg_replace("/define\('DB_USER',\s*'[^']*'\)/", "define('DB_USER', '$u')", $cfg);
    $cfg = preg_replace("/define\('DB_PASS',\s*'[^']*'\)/", "define('DB_PASS', '$p')", $cfg);
    file_put_contents($cfgPath, $cfg);
    echo "DB corrigido! USER=$u\n";
    exit;
}

// Acao padrao: adicionar token
$token = isset($_GET['t']) ? trim($_GET['t']) : '';
if (empty($token)) {
    die("Passe o token: ?key=...&t=SEU_TOKEN\nOu use: ?key=...&action=fix_deploy\n");
}

$cfgPath = __DIR__ . '/core/config.php';
$cfg = file_get_contents($cfgPath);

if (strpos($cfg, 'GITHUB_TOKEN') !== false) {
    echo "GITHUB_TOKEN ja existe no config.php!\n";
    exit;
}

$line = "\n// GitHub Token (repo privado)\ndefine('GITHUB_TOKEN', '" . addslashes($token) . "');\n";
if (strpos($cfg, '?>') !== false) {
    $cfg = str_replace('?>', $line . '?>', $cfg);
} else {
    $cfg .= $line;
}
file_put_contents($cfgPath, $cfg);
echo "GITHUB_TOKEN adicionado!\n";
