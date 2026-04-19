<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');

$pdo = db();
echo "=== Migrar: fila de envio WhatsApp ===\n\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS zapi_fila_envio (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    origem VARCHAR(40) NOT NULL,
    client_id INT UNSIGNED NULL,
    case_id INT UNSIGNED NULL,
    lead_id INT UNSIGNED NULL,
    telefone VARCHAR(30) NOT NULL,
    nome_contato VARCHAR(150) NULL,
    canal_sugerido VARCHAR(2) DEFAULT '24',
    mensagem TEXT NOT NULL,
    status ENUM('pendente','enviada','descartada') DEFAULT 'pendente',
    enviada_por INT UNSIGNED NULL,
    enviada_em DATETIME NULL,
    descartada_por INT UNSIGNED NULL,
    descartada_em DATETIME NULL,
    criada_por INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status (status, created_at),
    KEY idx_client (client_id),
    KEY idx_origem (origem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "✓ Tabela zapi_fila_envio criada/verificada\n";

echo "\n=== FIM ===\n";
