<?php
/**
 * Adicionar coluna client_status na tabela clients
 * Execute UMA VEZ
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

$pdo = db();

echo "=== Atualizando banco ===\n\n";

// Adicionar client_status
try {
    $pdo->exec("ALTER TABLE clients ADD COLUMN client_status VARCHAR(30) NOT NULL DEFAULT 'ativo' AFTER source");
    echo "OK: coluna client_status adicionada\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "SKIP: coluna client_status ja existe\n";
    } else {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}

// Adicionar índice
try {
    $pdo->exec("ALTER TABLE clients ADD INDEX idx_client_status (client_status)");
    echo "OK: indice idx_client_status criado\n";
} catch (Exception $e) {
    echo "SKIP: indice ja existe\n";
}

echo "\n=== Concluido ===\n";
