<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: Fábrica de Petições ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS case_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id INT NOT NULL,
        client_id INT NOT NULL,
        tipo_peca VARCHAR(80) NOT NULL,
        tipo_acao VARCHAR(80) NOT NULL,
        titulo VARCHAR(200),
        conteudo_html LONGTEXT,
        gerado_por INT,
        drive_file_id VARCHAR(100),
        drive_file_url VARCHAR(500),
        tokens_input INT,
        tokens_output INT,
        custo_usd DECIMAL(8,6),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
        FOREIGN KEY (gerado_por) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK: tabela case_documents criada\n";
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\nVerificação: " . $pdo->query("SELECT COUNT(*) FROM case_documents")->fetchColumn() . " registros\n";
echo "Pronto!\n";
