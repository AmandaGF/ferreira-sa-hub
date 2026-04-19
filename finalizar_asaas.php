<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');

$pdo = db();
echo "=== Finalizar Asaas (token webhook + limpeza) ===\n\n";

// 1. Gerar token aleatório pro webhook (se ainda não tiver)
$cur = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'asaas_webhook_token'")->fetchColumn();
if (!$cur) {
    $token = bin2hex(random_bytes(24)); // 48 chars hex
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('asaas_webhook_token', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array($token));
    echo "✓ Novo token gerado e salvo:\n  {$token}\n\n";
} else {
    $token = $cur;
    echo "= Token webhook já existia:\n  {$token}\n\n";
}

// 2. Apagar scripts de diagnóstico/setup que cumpriram papel
$obsoletos = array(
    'salvar_asaas.php',
    'testar_asaas.php',
    'reconfig_webhook_24.php',
    'diag_zapi_24.php',
    'migrar_transcricao.php',
);
echo "--- Limpeza de scripts temporários ---\n";
foreach ($obsoletos as $f) {
    $p = __DIR__ . '/' . $f;
    if (file_exists($p)) {
        unlink($p) ? print("✓ Apagado: {$f}\n") : print("✗ Falha apagar: {$f}\n");
    } else {
        echo "= Já não existe: {$f}\n";
    }
}

echo "\n=== URL do Webhook pro Asaas ===\n";
echo "URL: https://ferreiraesa.com.br/conecta/modules/financeiro/webhook.php\n";
echo "Token (header Asaas-Access-Token): {$token}\n";
echo "\n=== FIM ===\n";
