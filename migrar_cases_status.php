<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

try {
    $pdo->exec("ALTER TABLE `cases` MODIFY `status` VARCHAR(40) NOT NULL DEFAULT 'aguardando_docs'");
    echo "cases.status convertido para VARCHAR(40)!\n";
} catch (Exception $e) { echo "Erro: " . $e->getMessage() . "\n"; }

echo "Pronto!\n";
