<?php
/**
 * Central VIP F&S — Migração: Tabela GED (Gestão Eletrônica de Documentos)
 * Executar uma vez: /salavip/migrate_ged.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/conecta/core/config.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "=== Migração GED (Central VIP) ===\n\n";

$sql = "CREATE TABLE IF NOT EXISTS salavip_ged (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    processo_id INT UNSIGNED DEFAULT NULL,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT DEFAULT NULL,
    categoria VARCHAR(100) DEFAULT 'geral',
    arquivo_path VARCHAR(500) NOT NULL,
    arquivo_nome VARCHAR(255) NOT NULL,
    arquivo_tipo VARCHAR(100) NOT NULL,
    arquivo_tamanho INT NOT NULL,
    visivel_cliente TINYINT(1) DEFAULT 1,
    compartilhado_por INT DEFAULT NULL,
    compartilhado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cliente (cliente_id),
    INDEX idx_processo (processo_id),
    INDEX idx_visivel (visivel_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $pdo->exec($sql);
    echo "[OK] Tabela salavip_ged criada/verificada.\n";
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}

echo "\nCategorias disponíveis: Procuração, Contrato, Petição, Decisão, Sentença, Certidão, Comprovante, Acordo, Parecer, Outro\n";
echo "\n=== Migração concluída ===\n";
