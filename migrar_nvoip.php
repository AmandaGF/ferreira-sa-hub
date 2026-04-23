<?php
/**
 * migrar_nvoip.php — schema da integração Nvoip (telefonia VoIP)
 *
 * Rodar 1x via: https://ferreiraesa.com.br/conecta/migrar_nvoip.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== MIGRAÇÃO: Integração Nvoip ===\n\n";

// 1) Tabela ligacoes_historico
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ligacoes_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        call_id VARCHAR(100) NOT NULL UNIQUE,
        client_id INT UNSIGNED NULL,
        lead_id INT UNSIGNED NULL,
        case_id INT UNSIGNED NULL,
        atendente_id INT UNSIGNED NOT NULL,
        ramal VARCHAR(20) NULL,
        telefone_destino VARCHAR(30) NOT NULL,
        duracao_segundos INT DEFAULT 0,
        status ENUM('calling','established','noanswer','busy','finished','failed') DEFAULT 'calling',
        gravacao_url VARCHAR(500) NULL,
        gravacao_local VARCHAR(200) NULL,
        transcricao LONGTEXT NULL,
        resumo_ia TEXT NULL,
        custo DECIMAL(10,4) NULL,
        iniciada_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        encerrada_em DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_client (client_id),
        INDEX idx_lead (lead_id),
        INDEX idx_case (case_id),
        INDEX idx_call_id (call_id),
        INDEX idx_atendente (atendente_id),
        INDEX idx_iniciada (iniciada_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] ligacoes_historico criada\n";
} catch (Exception $e) { echo "[ERRO] ligacoes_historico: " . $e->getMessage() . "\n"; }

// 2) Coluna users.nvoip_ramal
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN nvoip_ramal VARCHAR(20) DEFAULT NULL");
    echo "[OK] users.nvoip_ramal adicionado\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "[SKIP] users.nvoip_ramal já existe\n";
    } else {
        echo "[ERRO] users.nvoip_ramal: " . $e->getMessage() . "\n";
    }
}

// 3) Configuracoes (chaves vazias — admin preenche no painel)
$cfgs = array(
    array('nvoip_napikey',       '', 'Chave de API Nvoip (napikey)'),
    array('nvoip_numbersip',     '', 'Número SIP principal da conta Nvoip'),
    array('nvoip_user_token',    '', 'User token para gerar OAuth'),
    array('nvoip_access_token',  '', 'Token OAuth atual (gerado automaticamente)'),
    array('nvoip_refresh_token', '', 'Refresh token OAuth (gerado automaticamente)'),
    array('nvoip_token_expiry',  '', 'Expiração do token OAuth (ISO datetime)'),
);
try {
    $ins = $pdo->prepare("INSERT IGNORE INTO configuracoes (chave, valor, descricao) VALUES (?, ?, ?)");
    foreach ($cfgs as $c) $ins->execute($c);
    echo "[OK] 6 chaves nvoip_* em configuracoes (se ainda não existiam)\n";
} catch (Exception $e) {
    echo "[ERRO] configuracoes: " . $e->getMessage() . "\n";
}

// 4) Pasta de gravações (/files é bloqueada por web server — download via endpoint dedicado)
$dir = __DIR__ . '/files/ligacoes';
if (!is_dir($dir)) {
    if (@mkdir($dir, 0755, true)) echo "[OK] pasta /files/ligacoes/ criada\n";
    else echo "[AVISO] não consegui criar /files/ligacoes — criar manualmente no Gerenciador\n";
} else {
    echo "[SKIP] /files/ligacoes/ já existe\n";
}

echo "\n=== CONCLUÍDO ===\n";
