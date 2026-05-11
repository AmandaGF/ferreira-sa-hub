<?php
/**
 * Gera a chave de criptografia TOTP e injeta no core/config.php (uma vez).
 * Roda só uma vez — se a constante TOTP_ENCRYPTION_KEY já existe, nada acontece.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_totp_key.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');

$configPath = __DIR__ . '/core/config.php';
if (!file_exists($configPath)) { echo "config.php nao encontrado\n"; exit; }

$content = file_get_contents($configPath);
if (strpos($content, 'TOTP_ENCRYPTION_KEY') !== false) {
    echo "TOTP_ENCRYPTION_KEY já existe no config.php — nada a fazer.\n";
    exit;
}

// Gera chave de 32 bytes em hex (64 chars)
$key = bin2hex(random_bytes(32));

// Encontra o último `define(...)` no config e adiciona depois dele
// (ou adiciona antes do fechamento do PHP se houver)
$insertion = "\n// Chave de criptografia AES-256 pras chaves secretas TOTP em sistemas_2fa e users_2fa.\n"
           . "// Gerada automaticamente em " . date('Y-m-d H:i:s') . ". NÃO TROCAR — chaves cifradas viram lixo.\n"
           . "define('TOTP_ENCRYPTION_KEY', '" . $key . "');\n";

// Se tem `?>` no final, insere antes; senão append
if (preg_match('/\?>\s*$/', $content)) {
    $newContent = preg_replace('/\?>\s*$/', $insertion . "?>\n", $content);
} else {
    $newContent = rtrim($content) . "\n" . $insertion;
}

if ($newContent === $content) { echo "ERRO: nao consegui modificar o config.\n"; exit; }

if (file_put_contents($configPath, $newContent) === false) {
    echo "ERRO: nao consegui escrever em config.php (permissao?).\n";
    exit;
}

echo "✓ TOTP_ENCRYPTION_KEY adicionada ao config.php com sucesso.\n";
echo "Chave gerada com 64 chars hex (32 bytes de entropia).\n";
echo "\nIMPORTANTE: nao trocar essa chave depois — todas as chaves 2FA salvas viram lixo se trocar.\n";
