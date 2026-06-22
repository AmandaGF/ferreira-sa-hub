<?php
/**
 * Migração — Renúncia/Desistência de processos.
 *   curl -s "https://ferreiraesa.com.br/conecta/migrar_renuncias.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração Renúncia/Desistência ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS renuncias (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id INT UNSIGNED NOT NULL,
        client_id INT UNSIGNED NOT NULL,
        tipo ENUM('renuncia','desistencia') NOT NULL,
        motivo VARCHAR(40) NOT NULL,
        motivo_outro VARCHAR(300) NULL,
        observacao TEXT NULL,
        comprovante_nome VARCHAR(255) NULL,
        comprovante_path VARCHAR(255) NULL,
        comprovante_mime VARCHAR(80) NULL,
        task_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case (case_id),
        INDEX idx_client (client_id),
        INDEX idx_tipo (tipo),
        INDEX idx_motivo (motivo),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ tabela renuncias\n";
} catch (Exception $e) { echo "⚠️ renuncias: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
