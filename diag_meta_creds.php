<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== meta_config - estado (segredos mascarados) ==\n";
foreach ($pdo->query("SELECT chave, LENGTH(valor) AS len, atualizado_em FROM meta_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  {$r['chave']}: length={$r['len']} (atualizado {$r['atualizado_em']})\n";
}

echo "\n== Validacao especifica ==\n";
$cfg = array();
foreach ($pdo->query("SELECT chave, valor FROM meta_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cfg[$r['chave']] = $r['valor'];
}
echo "  App ID = '" . ($cfg['meta_app_id'] ?? '') . "' " . (($cfg['meta_app_id'] ?? '') === '898812713246847' ? '✓ MATCH' : '✗ DIFERENTE') . "\n";
$asLen = strlen($cfg['meta_app_secret'] ?? '');
echo "  App Secret length = $asLen " . ($asLen === 32 ? '✓ (32 chars - tipico Meta)' : ($asLen > 0 ? '⚠ (preenchido mas len incomum)' : '✗ VAZIO')) . "\n";
echo "  Verify Token = '" . ($cfg['meta_verify_token'] ?? '') . "' " . (($cfg['meta_verify_token'] ?? '') === 'frrbSge5Xme99TbpuF9CgKcdJNusA25AAXPs' ? '✓ MATCH' : '✗ DIFERENTE') . "\n";
echo "  Webhook ativo = '" . ($cfg['meta_webhook_active'] ?? '') . "' " . (($cfg['meta_webhook_active'] ?? '') !== '1' ? '✓ (desativado, esperando App Review)' : '⚠ JA ATIVO') . "\n";
