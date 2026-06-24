<?php
/**
 * Migração — Audiencistas (correspondentes de audiência) + audiências a contratar.
 *   curl -s "https://ferreiraesa.com.br/conecta/migrar_audiencistas.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração Audiencistas ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audiencistas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(150) NOT NULL,
        telefone VARCHAR(30) NULL,
        email VARCHAR(190) NULL,
        areas TEXT NULL,
        tipos TEXT NULL,
        valor_medio_cents INT NULL,
        dados_deposito TEXT NULL,
        observacoes TEXT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ tabela audiencistas\n";
} catch (Exception $e) { echo "⚠️ audiencistas: " . $e->getMessage() . "\n"; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audiencias (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(80) NOT NULL,
        data_hora DATETIME NULL,
        comarca VARCHAR(160) NULL,
        client_id INT UNSIGNED NULL,
        case_id INT UNSIGNED NULL,
        processo_numero VARCHAR(40) NULL,
        orientacoes TEXT NULL,
        audiencista_id INT UNSIGNED NULL,
        valor_cents INT NULL,
        arquivo_nome VARCHAR(255) NULL,
        arquivo_path VARCHAR(255) NULL,
        arquivo_mime VARCHAR(80) NULL,
        arquivo_enviado_em DATETIME NULL,
        status ENUM('aberta','designada','realizada','cancelada') NOT NULL DEFAULT 'aberta',
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_audiencista (audiencista_id),
        INDEX idx_data (data_hora)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ tabela audiencias\n";
} catch (Exception $e) { echo "⚠️ audiencias: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
