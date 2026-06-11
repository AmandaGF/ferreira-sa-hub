<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Mostra schema real pra debug
echo "Colunas de configuracoes:\n";
foreach ($pdo->query("SHOW COLUMNS FROM configuracoes") as $c) echo "  {$c['Field']}\n";
echo "\n";

// Usa INSERT ... ON DUPLICATE KEY UPDATE (assume chave UNIQUE)
$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, '1') ON DUPLICATE KEY UPDATE valor = '1'")
    ->execute(array('ia_feature_perguntar_ia_chat_enabled'));
echo "✓ Feature 'perguntar_ia_chat' ATIVADA.\n";

$check = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
$check->execute(array('ia_feature_perguntar_ia_chat_enabled'));
echo "Valor atual no banco: '" . $check->fetchColumn() . "'\n";
