<?php
/**
 * Ferreira & Sá Conecta — Sistema de Autenticação
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

// ─── Estado da sessão ───────────────────────────────────
function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_user_id(): ?int
{
    return $_SESSION['user']['id'] ?? null;
}

function current_user_role(): ?string
{
    return $_SESSION['user']['role'] ?? null;
}

function current_user_name(): string
{
    return $_SESSION['user']['name'] ?? '';
}

/**
 * Nome do usuário pra atendimento WhatsApp / Central VIP.
 *
 * 1. Se o usuário preencheu users.wa_display_name, usa esse.
 * 2. Senão, usa o users.name COMPLETO.
 *
 * Amanda 12/06/2026: antes o fallback (quando wa_display_name era vazio)
 * truncava pra 'primeiro + último' — isso causava confusão porque o
 * colaborador mudava o nome no Hub mas o cliente continuava vendo nome
 * truncado. Agora o fallback usa o nome completo direto. Quem quiser
 * versão curta preenche wa_display_name explicitamente no form.
 *
 * Aceita user_id (int) OU array (user row) OU null (usa o logado).
 */
function user_display_name($userOrId = null): string
{
    $arr = null;
    if (is_array($userOrId)) {
        $arr = $userOrId;
    } elseif (is_int($userOrId) && $userOrId > 0) {
        try {
            $stmt = db()->prepare("SELECT name, wa_display_name FROM users WHERE id = ?");
            $stmt->execute(array($userOrId));
            $arr = $stmt->fetch() ?: null;
        } catch (Exception $e) {}
    } else {
        $arr = current_user();
        // A sessão NÃO guarda wa_display_name (só id/name/email/role). Sem isso,
        // a assinatura e o nome de atendimento sempre caíam no nome completo.
        // Busca o wa_display_name no banco quando a sessão não tem a chave.
        if (is_array($arr) && !array_key_exists('wa_display_name', $arr) && !empty($arr['id'])) {
            try {
                $stmt = db()->prepare("SELECT name, wa_display_name FROM users WHERE id = ?");
                $stmt->execute(array((int)$arr['id']));
                $row = $stmt->fetch();
                if ($row) $arr = $row;
            } catch (Exception $e) {}
        }
    }
    if (!$arr) return '';

    $display = trim($arr['wa_display_name'] ?? '');
    if ($display !== '') return $display;

    return trim($arr['name'] ?? '');
}

// ─── Login / Logout ─────────────────────────────────────
function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'    => (int)$user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
        'setor' => $user['setor'] ?? null,
    ];

    // Atualizar last_login_at
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([$user['id']]);

    audit_log('login', 'user', (int)$user['id']);
}

function logout_user(): void
{
    $userId = current_user_id();
    if ($userId) {
        audit_log('logout', 'user', $userId);
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }

    session_destroy();
}

// ─── Autenticação ───────────────────────────────────────
function authenticate(string $email, string $password): ?array
{
    // Verificar rate limiting
    if (is_login_locked($email)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, password_hash, role, setor, is_active FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        record_failed_login($email);
        return null;
    }

    if (!$user['is_active']) {
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        record_failed_login($email);
        return null;
    }

    clear_failed_logins($email);
    return $user;
}

// ─── Rate Limiting ──────────────────────────────────────
function record_failed_login(string $email): void
{
    db()->prepare(
        'INSERT INTO audit_log (action, details, ip_address) VALUES (?, ?, ?)'
    )->execute([
        'login_failed',
        'email: ' . $email,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function is_login_locked(string $email): bool
{
    $minutes = LOGIN_LOCKOUT_MINUTES;
    $maxAttempts = LOGIN_MAX_ATTEMPTS;

    $stmt = db()->prepare(
        "SELECT COUNT(*) as attempts FROM audit_log
         WHERE action = 'login_failed'
         AND details = ?
         AND ip_address = ?
         AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([
        'email: ' . $email,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $minutes,
    ]);

    $row = $stmt->fetch();
    return ($row['attempts'] ?? 0) >= $maxAttempts;
}

function clear_failed_logins(string $email): void
{
    db()->prepare(
        "DELETE FROM audit_log WHERE action = 'login_failed' AND details = ? AND ip_address = ?"
    )->execute([
        'email: ' . $email,
        $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

// ─── Verificação de role ────────────────────────────────
function has_role(string ...$roles): bool
{
    $current = current_user_role();
    return $current && in_array($current, $roles, true);
}

function has_min_role(string $minRole): bool
{
    $current = current_user_role();
    return $current && role_level($current) >= role_level($minRole);
}
