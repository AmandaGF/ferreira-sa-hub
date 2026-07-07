<?php
/**
 * Migração: rastreamento de cliques em links enviados via WhatsApp.
 * - Tabela short_links (código curto → URL real + contexto lead/case/client)
 * - Tabela link_clicks (log detalhado de cada clique)
 * - Killswitch shortlinks_ativo
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');

$pdo = db();
echo "=== Migração shortlinks (rastreamento cliques WhatsApp) ===\n\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS short_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(12) NOT NULL,
    url_original TEXT NOT NULL,
    conversa_id INT UNSIGNED NULL,
    mensagem_id INT UNSIGNED NULL,
    client_id INT UNSIGNED NULL,
    lead_id INT UNSIGNED NULL,
    case_id INT UNSIGNED NULL,
    canal VARCHAR(2) NULL,
    criado_por INT UNSIGNED NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    cliques_total INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_clique_em DATETIME NULL,
    UNIQUE KEY uk_codigo (codigo),
    INDEX idx_lead (lead_id, ultimo_clique_em),
    INDEX idx_case (case_id, ultimo_clique_em),
    INDEX idx_client (client_id, ultimo_clique_em),
    INDEX idx_conversa (conversa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "✓ Tabela short_links\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS link_clicks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id INT UNSIGNED NOT NULL,
    ip VARCHAR(45),
    user_agent VARCHAR(500),
    clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_link (link_id, clicked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "✓ Tabela link_clicks\n";

$pdo->exec("INSERT IGNORE INTO configuracoes (chave, valor) VALUES ('shortlinks_ativo', '1')");
echo "✓ Killswitch shortlinks_ativo (default ON)\n";

echo "\n=== FIM ===\n";
