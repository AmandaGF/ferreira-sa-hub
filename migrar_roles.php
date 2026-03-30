<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// 1. Alterar ENUM da coluna role para incluir novos perfis
try {
    $pdo->exec("ALTER TABLE `users` MODIFY `role` VARCHAR(20) NOT NULL DEFAULT 'colaborador'");
    echo "1. users.role convertido para VARCHAR(20)!\n";
} catch (Exception $e) { echo "1. " . $e->getMessage() . "\n"; }

// 2. Atualizar roles dos usuários
$updates = array(
    array('andressia@ferreiraesa.com.br', 'comercial'),
    array('naiaradourado@ferreiraesa.com.br', 'cx'),
    array('carinacastro@ferreiraesa.com.br', 'operacional'),
    array('simonebernardino@ferreiraesa.com.br', 'operacional'),
);

foreach ($updates as $u) {
    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE email = ?");
    $stmt->execute(array($u[1], $u[0]));
    $count = $stmt->rowCount();
    echo "2. {$u[0]} → {$u[1]}" . ($count ? " OK" : " (não encontrado)") . "\n";
}

echo "\nPronto!\n";
