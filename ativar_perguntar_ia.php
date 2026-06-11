<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Ativa a feature 'perguntar_ia_chat' (cria se nao existir)
$st = $pdo->prepare("SELECT id FROM configuracoes WHERE chave = ?");
$st->execute(array('ia_feature_perguntar_ia_chat_enabled'));
$id = $st->fetchColumn();

if ($id) {
    $pdo->prepare("UPDATE configuracoes SET valor = '1', atualizado_em = NOW() WHERE id = ?")->execute(array($id));
    echo "✓ Feature 'perguntar_ia_chat' ATIVADA (atualizou registro #$id).\n";
} else {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor, atualizado_em) VALUES (?, '1', NOW())")
        ->execute(array('ia_feature_perguntar_ia_chat_enabled'));
    echo "✓ Feature 'perguntar_ia_chat' CRIADA e ATIVADA.\n";
}

// Verifica
$check = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
$check->execute(array('ia_feature_perguntar_ia_chat_enabled'));
echo "Valor atual no banco: '" . $check->fetchColumn() . "'\n";
