<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== MIGRACAO: Aniversario via WhatsApp ===\n\n";

// Garantir birthday_greetings existe (normalmente ja existe)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS birthday_greetings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        year INT NOT NULL,
        sent_by INT DEFAULT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_client_year (client_id, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela birthday_greetings (já existia ou criada)\n";
} catch (Exception $e) { echo "[INFO] " . $e->getMessage() . "\n"; }

// Seed template aniversario
try {
    $chk = (int)$pdo->query("SELECT COUNT(*) FROM zapi_templates WHERE categoria = 'aniversario'")->fetchColumn();
    if ($chk === 0) {
        $conteudo = "Feliz aniversário, {{nome}}! 🎂🎉\n\nTodos do escritório Ferreira & Sá Advocacia desejam um dia cheio de alegria e um ano repleto de conquistas.\n\nCom carinho,\nEquipe Ferreira & Sá";
        $pdo->prepare("INSERT INTO zapi_templates (nome, conteudo, canal, categoria, ativo) VALUES (?,?,?,?,1)")
            ->execute(array('🎂 Aniversário Cliente', $conteudo, '24', 'aniversario'));
        echo "[OK] Template seed '🎂 Aniversário Cliente' criado\n";
    } else {
        echo "[SKIP] Ja existe template categoria 'aniversario' ({$chk})\n";
    }
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

echo "\n=== CONCLUIDO ===\n";
