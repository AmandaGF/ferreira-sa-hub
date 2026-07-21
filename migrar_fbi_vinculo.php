<?php
/**
 * Migração — Pesquisas de vínculo empregatício via FBI $.
 *   curl -s "https://ferreiraesa.com.br/conecta/migrar_fbi_vinculo.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração Pesquisa FBI $ ===\n\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fbi_vinculo_pesquisas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id INT UNSIGNED NULL,
        client_id INT UNSIGNED NULL,
        parte_nome VARCHAR(160) NOT NULL,
        parte_cpf VARCHAR(20) NULL,
        parente ENUM('pai','mae','outro') NULL,
        observacao TEXT NULL,
        status ENUM('pendente','concluida') NOT NULL DEFAULT 'pendente',
        tem_vinculo TINYINT(1) NULL,
        resultado TEXT NULL,
        task_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        pesquisado_por INT UNSIGNED NULL,
        pesquisado_em DATETIME NULL,
        INDEX idx_status (status), INDEX idx_case (case_id), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ tabela fbi_vinculo_pesquisas\n";
} catch (Exception $e) { echo "⚠️ " . $e->getMessage() . "\n"; }
echo "\n=== FIM ===\n";
