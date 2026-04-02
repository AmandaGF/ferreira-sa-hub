<?php
/**
 * Ferreira & Sá Hub — API de Permissões por Usuário
 * Acesso: SOMENTE admin
 *
 * GET  ?action=get&user_id=X  → retorna overrides atuais
 * POST { user_id, overrides: { module: 0|1 } } → salva overrides
 * POST { user_id, action: "reset" } → remove todos os overrides
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/middleware.php';

require_login();
require_role('admin');

header('Content-Type: application/json; charset=utf-8');
$pdo = db();

// ── GET: buscar overrides ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) {
        echo json_encode(array('error' => 'user_id obrigatório'));
        exit;
    }

    $overrides = array();
    try {
        $stmt = $pdo->prepare("SELECT module, allowed FROM user_permissions WHERE user_id = ?");
        $stmt->execute(array($userId));
        foreach ($stmt->fetchAll() as $r) {
            $overrides[$r['module']] = (int)$r['allowed'];
        }
    } catch (Exception $e) {
        // Tabela pode não existir — criar
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                module VARCHAR(50) NOT NULL,
                allowed TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_user_module (user_id, module)
            )");
        } catch (Exception $e2) {}
    }

    echo json_encode(array('ok' => true, 'overrides' => $overrides));
    exit;
}

// ── POST: salvar ou resetar ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || !isset($body['user_id'])) {
        echo json_encode(array('error' => 'Dados inválidos'));
        exit;
    }

    $userId = (int)$body['user_id'];
    if (!$userId) {
        echo json_encode(array('error' => 'user_id inválido'));
        exit;
    }

    // Verificar que o usuário existe e não é admin
    $userStmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ?");
    $userStmt->execute(array($userId));
    $user = $userStmt->fetch();
    if (!$user) {
        echo json_encode(array('error' => 'Usuário não encontrado'));
        exit;
    }
    if ($user['role'] === 'admin') {
        echo json_encode(array('error' => 'Admin tem acesso total, sem overrides'));
        exit;
    }

    // Garantir que a tabela existe
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            module VARCHAR(50) NOT NULL,
            allowed TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_module (user_id, module)
        )");
    } catch (Exception $e) {}

    // ── RESET: apagar todos os overrides ──
    if (isset($body['action']) && $body['action'] === 'reset') {
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute(array($userId));
        audit_log('permissions_reset', 'user', $userId, 'Overrides removidos por ' . current_user_name());
        echo json_encode(array('ok' => true, 'message' => 'Permissões resetadas'));
        exit;
    }

    // ── SAVE: gravar overrides ──
    $overrides = isset($body['overrides']) ? $body['overrides'] : array();
    $defaults = _permission_defaults();
    $validModules = array_keys($defaults);

    // Primeiro: apagar todos os overrides do usuário
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute(array($userId));

    // Depois: inserir apenas os que diferem do padrão
    $inserted = 0;
    $insertStmt = $pdo->prepare("INSERT INTO user_permissions (user_id, module, allowed) VALUES (?, ?, ?)");

    foreach ($overrides as $module => $allowed) {
        if (!in_array($module, $validModules)) continue;
        $allowed = (int)$allowed;

        // Verificar se é diferente do padrão
        $defaultAllowed = isset($defaults[$module]) && in_array($user['role'], $defaults[$module], true);
        $overrideAllowed = (bool)$allowed;

        // Só gravar se for diferente do padrão
        if ($overrideAllowed !== $defaultAllowed) {
            $insertStmt->execute(array($userId, $module, $allowed));
            $inserted++;
        }
    }

    $details = $inserted . ' override(s) para ' . $user['name'] . ' (' . $user['role'] . ')';
    audit_log('permissions_updated', 'user', $userId, $details);

    echo json_encode(array('ok' => true, 'message' => $inserted . ' override(s) salvos', 'count' => $inserted));
    exit;
}

echo json_encode(array('error' => 'Método não suportado'));
