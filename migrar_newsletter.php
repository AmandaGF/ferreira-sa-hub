<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Newsletter ===\n\n";

$queries = array(
    "CREATE TABLE IF NOT EXISTS newsletter_campanhas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(200) NOT NULL,
        assunto VARCHAR(200) NOT NULL,
        template_tipo VARCHAR(30) DEFAULT 'informativo',
        conteudo_html TEXT NOT NULL,
        segmento VARCHAR(50),
        segmento_filtro VARCHAR(100),
        status VARCHAR(20) DEFAULT 'rascunho',
        agendado_para DATETIME,
        total_destinatarios INT DEFAULT 0,
        total_enviados INT DEFAULT 0,
        total_abertos INT DEFAULT 0,
        total_cliques INT DEFAULT 0,
        brevo_campaign_id VARCHAR(50),
        created_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS newsletter_descadastros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT,
        email VARCHAR(200) NOT NULL,
        motivo VARCHAR(200),
        descadastrado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_email (email),
        INDEX idx_client (client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] Tabela criada\n";
    } catch (Exception $e) {
        echo "[INFO] " . $e->getMessage() . "\n";
    }
}

// Salvar configs padrão do Brevo se não existirem
$configs = array(
    'brevo_api_key' => '',
    'brevo_sender_email' => 'contato@ferreiraesa.com.br',
    'brevo_sender_name' => 'Ferreira & Sá Advocacia',
);
foreach ($configs as $chave => $valor) {
    try {
        $pdo->prepare("INSERT IGNORE INTO configuracoes (chave, valor) VALUES (?, ?)")->execute(array($chave, $valor));
        echo "[OK] Config: $chave\n";
    } catch (Exception $e) {
        echo "[SKIP] $chave: " . $e->getMessage() . "\n";
    }
}

echo "\n=== FIM ===\n";
