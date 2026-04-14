<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
try {
    $pdo = db();
    $pdo->exec("ALTER TABLE cases ADD COLUMN pro_bono TINYINT(1) DEFAULT 0");
    echo "Coluna pro_bono adicionada com sucesso!\n";
} catch (Exception $e) {
    echo "SKIP: " . $e->getMessage() . "\n";
}
