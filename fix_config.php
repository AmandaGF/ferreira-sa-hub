<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$cfgPath = __DIR__ . '/core/config.php';
$cfg = file_get_contents($cfgPath);

echo "Config atual:\n";
// Mostrar usuario e senha atuais (mascarados)
preg_match("/DB_USER.*?'([^']+)'/", $cfg, $m);
echo "  DB_USER: " . ($m[1] ?? '?') . "\n";
preg_match("/DB_PASS.*?'([^']+)'/", $cfg, $m);
echo "  DB_PASS: " . ($m[1] ?? '?') . "\n";
preg_match("/ENCRYPT_KEY.*?'([^']+)'/", $cfg, $m);
echo "  ENCRYPT_KEY: " . substr(($m[1] ?? '?'), 0, 10) . "...\n";

// Se tem parametros para corrigir
$user = isset($_GET['u']) ? $_GET['u'] : '';
$pass = isset($_GET['p']) ? $_GET['p'] : '';

if ($user && $pass) {
    $cfg = preg_replace("/define\('DB_USER',\s*'[^']*'\)/", "define('DB_USER', '$user')", $cfg);
    $cfg = preg_replace("/define\('DB_PASS',\s*'[^']*'\)/", "define('DB_PASS', '$pass')", $cfg);
    file_put_contents($cfgPath, $cfg);
    echo "\nAtualizado! DB_USER=$user\n";
    echo "Teste: ferreiraesa.com.br/conecta/debug_login.php\n";
} else {
    echo "\nPara corrigir, acesse:\n";
    echo "?key=fsa-hub-deploy-2026&u=USUARIO&p=SENHA\n";
}
