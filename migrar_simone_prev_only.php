<?php
/**
 * Migra: limita o acesso da usuária Simone exclusivamente ao Kanban PREV.
 *
 * Estratégia:
 * 1. Acha o(s) usuário(s) com nome LIKE '%Simone%' e is_active=1
 * 2. Para cada módulo do _permission_defaults() — bloqueia (allowed=0)
 * 3. Permite explicitamente 'prev' (allowed=1)
 *
 * Com isso o sidebar (que filtra via can_access pros itens em defaults) só
 * mostra o link do Kanban PREV. O fallback do login.php (_landing_module)
 * já redireciona ela direto pra modules/prev/ no login.
 *
 * Acesso admin: ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_auth.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
$pdo = db();

// 1. Achar Simone
$st = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE name LIKE ? OR email LIKE ?");
$st->execute(array('%Simone%', '%simone%'));
$matches = $st->fetchAll();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migrar Simone → só PREV</title>';
echo '<style>body{font-family:system-ui,Arial;padding:20px;max-width:900px;margin:0 auto;}h1{color:#052228}table{width:100%;border-collapse:collapse;margin-top:1rem}th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}th{background:#052228;color:#fff}.ok{background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:99px;font-weight:700}.no{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:99px;font-weight:700}</style>';
echo '</head><body><h1>Migrar Simone → acesso só ao Kanban PREV</h1>';

if (empty($matches)) {
    echo '<p>Nenhuma usuária encontrada com "Simone" no nome ou e-mail. Nada a fazer.</p>';
    exit;
}

echo '<h3>Usuárias encontradas:</h3><ul>';
foreach ($matches as $u) {
    echo '<li>id <strong>' . (int)$u['id'] . '</strong> — ' . htmlspecialchars($u['name']) . ' (' . htmlspecialchars($u['email']) . ') — role: ' . htmlspecialchars($u['role']) . ' — ' . ($u['is_active'] ? 'ativa' : '<em>inativa</em>') . '</li>';
}
echo '</ul>';

if (count($matches) > 1 && !isset($_GET['confirmar_id'])) {
    echo '<p><strong>Mais de uma usuária com "Simone" no nome.</strong> Confirme qual aplicar adicionando <code>&confirmar_id=ID</code> à URL.</p>';
    exit;
}

$alvo = isset($_GET['confirmar_id']) ? (int)$_GET['confirmar_id'] : (int)$matches[0]['id'];
$alvoRow = null;
foreach ($matches as $u) { if ((int)$u['id'] === $alvo) $alvoRow = $u; }
if (!$alvoRow) { echo '<p>ID inválido.</p>'; exit; }

echo '<hr><h3>Aplicando em: ' . htmlspecialchars($alvoRow['name']) . ' (id ' . $alvo . ')</h3>';

// 2. Self-heal user_permissions
$pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_user_module (user_id, module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 3. Aplicar overrides
$defaults = _permission_defaults();
$ins = $pdo->prepare("INSERT INTO user_permissions (user_id, module, allowed) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)");

echo '<table><thead><tr><th>Módulo</th><th>Permissão</th></tr></thead><tbody>';
$ok = 0;
foreach (array_keys($defaults) as $modulo) {
    $allowed = ($modulo === 'prev') ? 1 : 0;
    $ins->execute(array($alvo, $modulo, $allowed));
    echo '<tr><td>' . htmlspecialchars($modulo) . '</td><td>' . ($allowed ? '<span class="ok">✓ liberado</span>' : '<span class="no">✕ bloqueado</span>') . '</td></tr>';
    $ok++;
}
echo '</tbody></table>';

echo '<hr><p><strong>Pronto.</strong> ' . $ok . ' overrides aplicados. ' . htmlspecialchars($alvoRow['name']) . ' agora só vê o Kanban PREV.</p>';
echo '<p>Próximo login dela cai direto em <code>/modules/prev/</code> via <code>_landing_module()</code>.</p>';
echo '<p><a href="modules/admin/permissoes.php">→ Conferir tela de permissões (admin)</a></p>';
echo '</body></html>';
