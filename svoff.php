<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Estado atual da config ===\n";
$antes = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ia_feature_traducao_leiga_enabled'")->fetchColumn();
echo "  ia_feature_traducao_leiga_enabled = " . var_export($antes, true) . "\n";

$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ia_feature_traducao_leiga_enabled','0')
               ON DUPLICATE KEY UPDATE valor = '0'")->execute();

$depois = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'ia_feature_traducao_leiga_enabled'")->fetchColumn();
echo "  agora = " . var_export($depois, true) . "\n";
echo "\nOK — botao 'Em linguagem comum' escondido em toda Central VIP.\n";
