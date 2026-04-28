<?php
/**
 * Esconde a coluna "Parceria" do Kanban PREV pra Simone Bernardino Lima (id 5).
 * Acesso: ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
$pdo = db();

// Self-heal (caso ainda não exista)
$pdo->exec("CREATE TABLE IF NOT EXISTS user_kanban_hidden_columns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kanban_modulo VARCHAR(40) NOT NULL,
    column_key VARCHAR(60) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_kanban_col (user_id, kanban_modulo, column_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->prepare("INSERT IGNORE INTO user_kanban_hidden_columns (user_id, kanban_modulo, column_key) VALUES (?, ?, ?)")
    ->execute(array(5, 'prev', 'parceria'));

echo "✓ Coluna 'Parceria' do Kanban PREV agora está escondida pra Simone (user_id=5).\n";
echo "Pra desfazer: DELETE FROM user_kanban_hidden_columns WHERE user_id=5 AND column_key='parceria';\n";
