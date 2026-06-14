<?php
/**
 * Cria tabela user_favoritos — favoritos da sidebar por usuário (antes só
 * ficavam no localStorage do navegador, sumiam ao trocar de PC).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: user_favoritos ===\n\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_favoritos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        fav_id VARCHAR(120) NOT NULL,
        label VARCHAR(160) NOT NULL,
        icon VARCHAR(40) DEFAULT '',
        href VARCHAR(255) NOT NULL,
        ordem INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_fav (user_id, fav_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK: tabela user_favoritos criada (ou já existia).\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "Registros atuais: " . $pdo->query("SELECT COUNT(*) FROM user_favoritos")->fetchColumn() . "\n";
echo "\nPronto!\n";
