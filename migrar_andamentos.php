<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: Andamentos Processuais ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS case_andamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id INT NOT NULL,
        data_andamento DATE NOT NULL,
        tipo VARCHAR(30) NOT NULL DEFAULT 'movimentacao',
        descricao TEXT NOT NULL,
        created_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case (case_id),
        INDEX idx_data (data_andamento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK: tabela case_andamentos criada\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

$stmt = $pdo->query("SHOW COLUMNS FROM case_andamentos");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Colunas: " . implode(', ', $cols) . "\n";
echo "\n=== FIM ===\n";
