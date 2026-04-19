<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');

$pdo = db();
echo "=== Migrar: transcrição de áudio ===\n\n";

// 1. Colunas novas em zapi_mensagens
$cols = $pdo->query("SHOW COLUMNS FROM zapi_mensagens")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('transcricao', $cols, true)) {
    $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN transcricao TEXT NULL AFTER arquivo_tamanho");
    echo "✓ Coluna 'transcricao' criada\n";
} else {
    echo "= Coluna 'transcricao' já existe\n";
}
if (!in_array('transcricao_em', $cols, true)) {
    $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN transcricao_em DATETIME NULL AFTER transcricao");
    echo "✓ Coluna 'transcricao_em' criada\n";
} else {
    echo "= Coluna 'transcricao_em' já existe\n";
}

// 2. Salvar chave Groq
$pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(80) PRIMARY KEY,
    valor TEXT,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Chave passada via ?groq_key=... (evita commitar no git)
$key = $_GET['groq_key'] ?? '';
if ($key !== '') {
    $up = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('groq_api_key', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $up->execute(array($key));
    echo "✓ Chave Groq salva (" . substr($key, 0, 10) . "...)\n";
} else {
    echo "⚠ Passe a chave via &groq_key=... na URL para configurá-la\n";
}

// 3. Flag pra ligar/desligar transcrição automática (default: on)
$pdo->prepare("INSERT IGNORE INTO configuracoes (chave, valor) VALUES ('groq_transcribe_on', '1')")->execute();
echo "✓ Flag 'groq_transcribe_on' = 1 (ativa)\n";

echo "\n=== FIM ===\n";
