<?php
/**
 * Script temporário: adiciona GITHUB_TOKEN ao config.php do servidor
 * Acesse UMA VEZ: ferreiraesa.com.br/conecta/add_github_token.php?key=fsa-hub-deploy-2026&t=SEU_TOKEN
 * Passe o token como parametro &t=
 * Depois APAGUE este arquivo!
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
if (empty($token)) {
    die("Passe o token como parametro: ?key=fsa-hub-deploy-2026&t=SEU_TOKEN_AQUI\n");
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
echo "GITHUB_TOKEN adicionado ao config.php com sucesso!\n";
echo "APAGUE este arquivo do servidor!\n";
