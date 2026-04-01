<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== MIGRAÇÃO: Módulo Financeiro ===\n\n";

// 1. Colunas em clients
$cols = array(
    "ADD COLUMN asaas_customer_id VARCHAR(50) DEFAULT NULL" => "asaas_customer_id",
    "ADD COLUMN asaas_sincronizado TINYINT(1) DEFAULT 0" => "asaas_sincronizado",
);
foreach ($cols as $sql => $col) {
    $chk = $pdo->query("SHOW COLUMNS FROM clients LIKE '$col'");
    if ($chk->fetch()) { echo "[JÁ EXISTE] clients.$col\n"; continue; }
    try { $pdo->exec("ALTER TABLE clients $sql"); echo "[OK] clients.$col\n"; }
    catch (Exception $e) { echo "[ERRO] $col: " . $e->getMessage() . "\n"; }
}

// 2. Tabela contratos_financeiros
echo "\n--- contratos_financeiros ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contratos_financeiros (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        case_id INT UNSIGNED NULL,
        tipo_honorario VARCHAR(30) NOT NULL DEFAULT 'fixo',
        valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        valor_entrada DECIMAL(12,2) DEFAULT 0,
        num_parcelas INT DEFAULT 1,
        valor_parcela DECIMAL(12,2) DEFAULT NULL,
        dia_vencimento INT DEFAULT NULL,
        forma_pagamento VARCHAR(30) NOT NULL DEFAULT 'pix',
        data_fechamento DATE NOT NULL,
        status VARCHAR(20) DEFAULT 'ativo',
        pct_exito DECIMAL(5,2) DEFAULT NULL,
        observacoes TEXT,
        asaas_subscription_id VARCHAR(50) DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_client (client_id),
        KEY idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] Tabela criada/verificada\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// 3. Tabela asaas_cobrancas
echo "\n--- asaas_cobrancas ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS asaas_cobrancas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NULL,
        contrato_id INT UNSIGNED NULL,
        asaas_payment_id VARCHAR(50) UNIQUE NOT NULL,
        asaas_customer_id VARCHAR(50) DEFAULT NULL,
        descricao VARCHAR(250) DEFAULT NULL,
        valor DECIMAL(12,2) NOT NULL,
        vencimento DATE NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
        forma_pagamento VARCHAR(30) DEFAULT NULL,
        data_pagamento DATE DEFAULT NULL,
        valor_pago DECIMAL(12,2) DEFAULT NULL,
        link_boleto VARCHAR(500) DEFAULT NULL,
        link_pix VARCHAR(500) DEFAULT NULL,
        invoice_url VARCHAR(500) DEFAULT NULL,
        ultima_sync DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_client (client_id),
        KEY idx_contrato (contrato_id),
        KEY idx_status (status),
        KEY idx_vencimento (vencimento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] Tabela criada/verificada\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// 4. Config Asaas
echo "\n--- Configurações Asaas ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chave VARCHAR(60) UNIQUE NOT NULL, valor TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $cfgs = array(
        'asaas_api_key' => '6f1cd5b8-0a24-48b4-bd24-c8095552af78',
        'asaas_env' => 'sandbox',
    );
    foreach ($cfgs as $k => $v) {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = valor")->execute(array($k, $v));
        echo "[OK] $k configurado\n";
    }
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

echo "\n=== MIGRAÇÃO CONCLUÍDA ===\n";
