<?php
/**
 * Migracao: Adicionar coluna comarca na tabela cases
 * Uso: migrar_comarca.php?key=fsa-hub-deploy-2026
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    die('Acesso negado.');
}

require_once __DIR__ . '/core/database.php';

$pdo = db();

// Verificar se a coluna ja existe
$cols = $pdo->query("SHOW COLUMNS FROM cases LIKE 'comarca'")->fetchAll();

if (!empty($cols)) {
    echo "Coluna 'comarca' ja existe na tabela cases. Nenhuma alteracao necessaria.";
    exit;
}

$pdo->exec("ALTER TABLE cases ADD COLUMN comarca VARCHAR(100) DEFAULT NULL AFTER court");

echo "Coluna 'comarca' adicionada com sucesso na tabela cases.";
