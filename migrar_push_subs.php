<?php
/**
 * Cria tabela push_subscriptions + gera chaves VAPID (one-shot).
 *
 * Uso:
 *   curl "https://ferreiraesa.com.br/conecta/migrar_push_subs.php?key=fsa-hub-deploy-2026"
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_push.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "--- Tabela push_subscriptions ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        user_agent VARCHAR(300),
        ativo TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_endpoint_prefix (endpoint(191)),
        CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela criada ou já existia.\n";
} catch (Exception $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
    // Tenta sem foreign key (caso cPanel não aceite)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            user_agent VARCHAR(300),
            ativo TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_endpoint_prefix (endpoint(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[OK] Tabela criada sem FK.\n";
    } catch (Exception $e2) {
        echo "[ERR2] " . $e2->getMessage() . "\n";
    }
}

echo "\n--- Chaves VAPID ---\n";
$existing = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('vapid_public','vapid_private','vapid_subject')")->fetchAll();
$byKey = array();
foreach ($existing as $r) $byKey[$r['chave']] = $r['valor'];

if (!empty($byKey['vapid_public']) && !empty($byKey['vapid_private'])) {
    echo "[SKIP] Chaves VAPID já existem. public = " . substr($byKey['vapid_public'], 0, 20) . "...\n";
} else {
    $keys = push_gerar_vapid();
    if (!$keys) { echo "[ERR] Falha ao gerar par VAPID\n"; exit; }
    $up = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $up->execute(array('vapid_public', $keys['public_b64url']));
    $up->execute(array('vapid_private', $keys['private_pem']));
    echo "[OK] Chaves VAPID geradas.\n";
    echo "     public (b64url): " . substr($keys['public_b64url'], 0, 30) . "...\n";
}

if (empty($byKey['vapid_subject'])) {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
        ->execute(array('vapid_subject', 'mailto:contato@ferreiraesa.com.br'));
    echo "[OK] vapid_subject = mailto:contato@ferreiraesa.com.br\n";
} else {
    echo "[SKIP] vapid_subject já existe: " . $byKey['vapid_subject'] . "\n";
}

echo "\n--- Pronto ---\n";
echo "Próximo passo: usuários acessam o Hub e aceitam permissão de notificação.\n";
