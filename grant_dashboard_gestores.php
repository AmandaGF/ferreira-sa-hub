<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Conceder acesso aos dashboards Comercial e Operacional ===\n\n";

// Garantir que tabela user_permissions existe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        user_id INT NOT NULL,
        module VARCHAR(50) NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (user_id, module)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela user_permissions (já existia ou criada)\n\n";
} catch (Exception $e) { echo "[WARN] " . $e->getMessage() . "\n\n"; }

// Buscar usuários que devem ter acesso
$nomes = array('Luiz Eduardo', 'Rodrigo Gustavo', 'Amanda');
echo "--- Usuários a conceder permissão ---\n";
$found = array();
foreach ($nomes as $n) {
    $s = $pdo->prepare("SELECT id, name, role FROM users WHERE name LIKE ? AND is_active = 1");
    $s->execute(array('%' . $n . '%'));
    $rows = $s->fetchAll();
    foreach ($rows as $r) {
        echo "  #{$r['id']} | {$r['name']} | role={$r['role']}\n";
        $found[$r['id']] = $r;
    }
}
if (empty($found)) { echo "  (nenhum usuário encontrado)\n"; exit; }

echo "\n--- Aplicando permissões ---\n";
$ins = $pdo->prepare("INSERT INTO user_permissions (user_id, module, allowed) VALUES (?, ?, 1)
                      ON DUPLICATE KEY UPDATE allowed = 1");
foreach ($found as $uid => $u) {
    if ($u['role'] === 'admin') {
        echo "  ⏭️  #{$uid} {$u['name']} — é admin, já tem acesso total (pula)\n";
        continue;
    }
    $ins->execute(array($uid, 'dashboard_comercial'));
    $ins->execute(array($uid, 'dashboard_operacional'));
    echo "  ✅ #{$uid} {$u['name']} — dashboard_comercial + dashboard_operacional\n";
}

// Também remover acesso explicit de OUTROS usuários (se porventura tiverem allowed=1)
echo "\n--- Limpar acessos extras ---\n";
$keepIds = array_keys($found);
$keepIdsStr = !empty($keepIds) ? implode(',', array_map('intval', $keepIds)) : '0';
$r1 = $pdo->exec("UPDATE user_permissions SET allowed = 0
                  WHERE module IN ('dashboard_comercial','dashboard_operacional')
                    AND user_id NOT IN ({$keepIdsStr})");
echo "  Acessos removidos de outros usuários: {$r1}\n";

echo "\n=== CONCLUIDO ===\n";
echo "\nAmanda (admin) → acesso garantido pelo bypass de role\n";
echo "Luiz Eduardo e Rodrigo Gustavo → acesso via user_permissions\n";
echo "Demais usuários → vão ver apenas a aba Geral\n";
