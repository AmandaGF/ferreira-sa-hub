<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Sistema de Gamificação ===\n\n";

$queries = array(
    "CREATE TABLE IF NOT EXISTS gamificacao_pontos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        evento VARCHAR(50) NOT NULL,
        area ENUM('comercial','operacional') NOT NULL DEFAULT 'comercial',
        pontos INT NOT NULL,
        descricao VARCHAR(200),
        referencia_id INT,
        referencia_tipo VARCHAR(50),
        mes INT NOT NULL,
        ano INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_mes (user_id, mes, ano),
        INDEX idx_area (area),
        INDEX idx_mes_ano (mes, ano)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS gamificacao_totais (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL UNIQUE,
        pontos_mes_comercial INT DEFAULT 0,
        pontos_mes_operacional INT DEFAULT 0,
        pontos_total_comercial INT DEFAULT 0,
        pontos_total_operacional INT DEFAULT 0,
        nivel VARCHAR(30) DEFAULT 'Estagiário',
        nivel_num INT DEFAULT 1,
        contratos_mes INT DEFAULT 0,
        contratos_total INT DEFAULT 0,
        mes_referencia INT,
        ano_referencia INT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS gamificacao_premios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        area ENUM('comercial','operacional') NOT NULL DEFAULT 'comercial',
        posicao INT NOT NULL,
        mes INT NOT NULL,
        ano INT NOT NULL,
        user_id INT UNSIGNED,
        tipo VARCHAR(50),
        valor VARCHAR(200),
        entregue TINYINT(1) DEFAULT 0,
        entregue_em DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS gamificacao_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mes INT NOT NULL,
        ano INT NOT NULL,
        area ENUM('comercial','operacional') NOT NULL DEFAULT 'comercial',
        meta_principal INT DEFAULT 10,
        meta_pontos INT DEFAULT 500,
        premio_1 VARCHAR(200),
        premio_2 VARCHAR(200),
        premio_3 VARCHAR(200),
        UNIQUE KEY uk_mes_ano_area (mes, ano, area)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS gamificacao_niveis (
        nivel_num INT PRIMARY KEY,
        nome VARCHAR(50) NOT NULL,
        pontos_minimos INT NOT NULL,
        badge_emoji VARCHAR(10)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS gamificacao_eventos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payload JSON NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] " . substr(trim($q), 0, 60) . "...\n";
    } catch (Exception $e) {
        echo "[INFO] " . $e->getMessage() . "\n";
    }
}

// Popular níveis
echo "\n--- Populando níveis ---\n";
$niveis = array(
    array(1, 'Estagiário',      0,    '🎓'),
    array(2, 'Associado',       150,  '⭐'),
    array(3, 'Advogado Jr',     500,  '⚖️'),
    array(4, 'Advogado Pleno',  1500, '💼'),
    array(5, 'Advogado Sênior', 3500, '🏆'),
    array(6, 'Sócio',           7500, '👑'),
);
$stmtNivel = $pdo->prepare("INSERT IGNORE INTO gamificacao_niveis (nivel_num, nome, pontos_minimos, badge_emoji) VALUES (?,?,?,?)");
foreach ($niveis as $n) {
    $stmtNivel->execute($n);
    echo "[OK] Nível {$n[0]}: {$n[1]} ({$n[2]} pts) {$n[3]}\n";
}

// Criar registros iniciais para usuários ativos
echo "\n--- Criando totais para usuários ---\n";
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1")->fetchAll();
$stmtTot = $pdo->prepare("INSERT IGNORE INTO gamificacao_totais (user_id, mes_referencia, ano_referencia) VALUES (?, ?, ?)");
$mes = (int)date('n');
$ano = (int)date('Y');
foreach ($users as $u) {
    $stmtTot->execute(array($u['id'], $mes, $ano));
    echo "[OK] {$u['name']}\n";
}

// Config padrão do mês atual
echo "\n--- Config padrão ---\n";
try {
    $pdo->prepare("INSERT IGNORE INTO gamificacao_config (mes, ano, area, meta_principal, meta_pontos) VALUES (?,?,'comercial',10,500)")
        ->execute(array($mes, $ano));
    $pdo->prepare("INSERT IGNORE INTO gamificacao_config (mes, ano, area, meta_principal, meta_pontos) VALUES (?,?,'operacional',10,500)")
        ->execute(array($mes, $ano));
    echo "[OK] Config abril/2026 criada\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
