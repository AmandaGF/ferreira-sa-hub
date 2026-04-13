<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

// Ler credenciais do Conecta
require_once dirname(__DIR__) . '/conecta/core/config.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "=== Migração Sala VIP ===\n\n";

$sqls = [
    'salavip_usuarios' => "CREATE TABLE IF NOT EXISTS salavip_usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT UNSIGNED NOT NULL,
        cpf VARCHAR(14) NOT NULL UNIQUE,
        senha_hash VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        nome_exibicao VARCHAR(150) NOT NULL,
        ativo TINYINT(1) DEFAULT 0,
        token_ativacao VARCHAR(64) DEFAULT NULL,
        token_expira DATETIME DEFAULT NULL,
        ultimo_acesso DATETIME DEFAULT NULL,
        tentativas_login INT DEFAULT 0,
        bloqueado_ate DATETIME DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        criado_por INT DEFAULT NULL,
        atualizado_em DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cpf (cpf),
        INDEX idx_cliente (cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'salavip_threads' => "CREATE TABLE IF NOT EXISTS salavip_threads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        assunto VARCHAR(255) NOT NULL,
        status ENUM('aberta','respondida','fechada') DEFAULT 'aberta',
        categoria VARCHAR(100) DEFAULT 'geral',
        processo_id INT DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'salavip_mensagens' => "CREATE TABLE IF NOT EXISTS salavip_mensagens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thread_id INT NOT NULL,
        cliente_id INT NOT NULL,
        assunto VARCHAR(255) DEFAULT NULL,
        mensagem TEXT NOT NULL,
        origem ENUM('salavip','conecta') NOT NULL,
        remetente_id INT DEFAULT NULL,
        remetente_nome VARCHAR(150) DEFAULT NULL,
        lida_cliente TINYINT(1) DEFAULT 0,
        lida_equipe TINYINT(1) DEFAULT 0,
        anexo_path VARCHAR(500) DEFAULT NULL,
        anexo_nome VARCHAR(255) DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_thread (thread_id),
        INDEX idx_cliente (cliente_id),
        INDEX idx_lida_equipe (lida_equipe, origem)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'salavip_documentos_cliente' => "CREATE TABLE IF NOT EXISTS salavip_documentos_cliente (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        processo_id INT DEFAULT NULL,
        titulo VARCHAR(255) NOT NULL,
        descricao TEXT DEFAULT NULL,
        arquivo_path VARCHAR(500) NOT NULL,
        arquivo_nome VARCHAR(255) NOT NULL,
        arquivo_tipo VARCHAR(100) NOT NULL,
        arquivo_tamanho INT NOT NULL,
        status ENUM('pendente','aceito','rejeitado') DEFAULT 'pendente',
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'salavip_log_acesso' => "CREATE TABLE IF NOT EXISTS salavip_log_acesso (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        ip VARCHAR(45) NOT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        acao VARCHAR(100) NOT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario (usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'salavip_faq' => "CREATE TABLE IF NOT EXISTS salavip_faq (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pergunta VARCHAR(500) NOT NULL,
        resposta TEXT NOT NULL,
        ordem INT DEFAULT 0,
        ativo TINYINT(1) DEFAULT 1,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'salavip_palavras_bloqueio' => "CREATE TABLE IF NOT EXISTS salavip_palavras_bloqueio (
        id INT AUTO_INCREMENT PRIMARY KEY,
        termo VARCHAR(200) NOT NULL,
        ativo TINYINT(1) DEFAULT 1,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($sqls as $nome => $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $nome\n";
    } catch (Exception $e) {
        echo "ERRO ($nome): " . $e->getMessage() . "\n";
    }
}

// Palavras de bloqueio padrão
echo "\n--- Palavras de bloqueio ---\n";
$termos = ['interno','despacho de mero expediente','vista ao MP','conclusos','carga','remessa'];
$stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM salavip_palavras_bloqueio WHERE termo = ?");
$stmtIns = $pdo->prepare("INSERT INTO salavip_palavras_bloqueio (termo) VALUES (?)");
foreach ($termos as $t) {
    $stmtCheck->execute([$t]);
    if ((int)$stmtCheck->fetchColumn() === 0) {
        $stmtIns->execute([$t]);
        echo "  + $t\n";
    } else {
        echo "  = $t (já existe)\n";
    }
}

// ALTER nas tabelas existentes (ADD COLUMN IF NOT EXISTS simulado)
echo "\n--- ALTER tabelas existentes ---\n";

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "  + $table.$column\n";
        } else {
            echo "  = $table.$column (já existe)\n";
        }
    } catch (Exception $e) {
        echo "  ! $table.$column: " . $e->getMessage() . "\n";
    }
}

// documentos_pendentes
addColumnIfNotExists($pdo, 'documentos_pendentes', 'visivel_cliente', "TINYINT(1) DEFAULT 0");
addColumnIfNotExists($pdo, 'documentos_pendentes', 'arquivo_path', "VARCHAR(500) DEFAULT NULL");
addColumnIfNotExists($pdo, 'documentos_pendentes', 'arquivo_nome', "VARCHAR(255) DEFAULT NULL");
addColumnIfNotExists($pdo, 'documentos_pendentes', 'arquivo_tipo', "VARCHAR(100) DEFAULT NULL");
addColumnIfNotExists($pdo, 'documentos_pendentes', 'arquivo_tamanho', "INT DEFAULT NULL");
addColumnIfNotExists($pdo, 'documentos_pendentes', 'compartilhado_em', "DATETIME DEFAULT NULL");

// agenda_eventos
addColumnIfNotExists($pdo, 'agenda_eventos', 'visivel_cliente', "TINYINT(1) DEFAULT 0");

// case_documents (pode não existir)
try {
    $pdo->query("SELECT 1 FROM case_documents LIMIT 1");
    addColumnIfNotExists($pdo, 'case_documents', 'visivel_cliente', "TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    echo "  - case_documents: tabela não existe (OK)\n";
}

// cases
addColumnIfNotExists($pdo, 'cases', 'salavip_ativo', "TINYINT(1) DEFAULT 0");

echo "\n=== MIGRAÇÃO CONCLUÍDA ===\n";
