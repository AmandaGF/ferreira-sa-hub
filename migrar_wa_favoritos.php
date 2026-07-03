<?php
/**
 * Migration: tabela user_wa_favoritos — favoritos do menu de acoes do
 * WhatsApp, por usuario logado E por canal (21 comercial / 24 op).
 *
 * Amanda 03/07: reclamou que localStorage salvava por conversa (parecia).
 * Na verdade era por dispositivo, mas ela quer sincronizacao entre PC
 * e celular + separacao por canal (o canal 21 tem bot, o 24 nao — botoes
 * diferentes fazem sentido em conjuntos diferentes).
 *
 * Uso: GET ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migration user_wa_favoritos ===\n\n";
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_wa_favoritos (
            user_id INT NOT NULL,
            canal VARCHAR(4) NOT NULL,
            fav_id VARCHAR(48) NOT NULL,
            ordem INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, canal, fav_id),
            KEY idx_user_canal (user_id, canal, ordem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "✓ Tabela criada/existente\n";
} catch (Exception $e) {
    echo "✗ ERRO: " . $e->getMessage() . "\n";
}
echo "\nRegistros atuais: " . $pdo->query("SELECT COUNT(*) FROM user_wa_favoritos")->fetchColumn() . "\n";
