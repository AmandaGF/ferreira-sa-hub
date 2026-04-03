<?php
/**
 * Ferreira & Sá Conecta — Funções Utilitárias Gerais
 *
 * Helpers: escape, redirect, flash messages, CSRF, sanitização,
 * formatação, URL, criptografia, auditoria, paginação.
 */

// ─── Escape HTML ────────────────────────────────────────
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─── Redirect ───────────────────────────────────────────
function redirect(string $url)
{
    header('Location: ' . $url);
    exit;
}

// ─── Flash Messages ─────────────────────────────────────
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][$type] = $message;
}

function flash_get(string $type): ?string
{
    $msg = $_SESSION['flash'][$type] ?? null;
    unset($_SESSION['flash'][$type]);
    return $msg;
}

function flash_html(): string
{
    $html = '';
    foreach (['success', 'error', 'warning', 'info'] as $type) {
        $msg = flash_get($type);
        if ($msg) {
            $icons = ['success' => '✓', 'error' => '✕', 'warning' => '⚠', 'info' => 'ℹ'];
            $icon = $icons[$type] ?? '';
            $html .= '<div class="alert alert-' . $type . '">'
                    . '<span class="alert-icon">' . $icon . '</span> '
                    . e($msg) . '</div>';
        }
    }
    return $html;
}

// ─── CSRF ───────────────────────────────────────────────
function generate_csrf_token(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_input(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generate_csrf_token() . '">';
}

function validate_csrf(): bool
{
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    $valid = hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $token);
    // Regenerar token para o próximo uso
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    return $valid;
}

// ─── Sanitização ────────────────────────────────────────
function clean_str(?string $input, int $maxLength = 500): string
{
    if ($input === null) return '';
    $input = trim($input);
    $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
    return mb_substr($input, 0, $maxLength, 'UTF-8');
}

// ─── Formatação ─────────────────────────────────────────
function brl(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function data_br(?string $date): string
{
    if (!$date) return '—';
    $dt = DateTime::createFromFormat('Y-m-d', $date)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $date);
    return $dt ? $dt->format('d/m/Y') : '—';
}

function data_hora_br(?string $datetime): string
{
    if (!$datetime) return '—';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    return $dt ? $dt->format('d/m/Y H:i') : '—';
}

// ─── Protocolo ──────────────────────────────────────────
function generate_protocol(string $prefix = 'FSA'): string
{
    return $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
}

// ─── Criptografia (credenciais do portal) ───────────────
function encrypt_value(string $value): string
{
    $iv = random_bytes(openssl_cipher_iv_length(ENCRYPT_METHOD));
    $encrypted = openssl_encrypt($value, ENCRYPT_METHOD, ENCRYPT_KEY, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function decrypt_value(string $encoded): string
{
    $data = base64_decode($encoded);
    [$iv, $encrypted] = explode('::', $data, 2);
    return openssl_decrypt($encrypted, ENCRYPT_METHOD, ENCRYPT_KEY, 0, $iv);
}

// ─── URL helpers ────────────────────────────────────────
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function module_url(string $module, string $page = 'index.php'): string
{
    return url('modules/' . $module . '/' . $page);
}

function is_current_module(string $module): bool
{
    return strpos($_SERVER['REQUEST_URI'] ?? '', '/modules/' . $module) !== false;
}

/**
 * Exibe botão "Voltar ao processo" se o usuário veio de um caso.
 * Usar no topo das páginas de destino (financeiro, helpdesk, agenda).
 */
function voltar_ao_processo_html(): string
{
    // Prioridade: param URL > sessão (se referer veio do processo)
    $fromCase = (int)($_GET['from_case'] ?? 0);
    if (!$fromCase) {
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $veioDoProcesso = (strpos($referer, 'caso_ver') !== false || strpos($referer, 'from_case') !== false);
        if ($veioDoProcesso) $fromCase = (int)($_SESSION['origem_case_id'] ?? 0);
    }
    if (!$fromCase) return '';
    try {
        $stmt = db()->prepare("SELECT title, case_number FROM cases WHERE id = ?");
        $stmt->execute(array($fromCase));
        $c = $stmt->fetch();
        if (!$c) return '';
        $label = $c['title'] ?: ($c['case_number'] ?: 'Processo #' . $fromCase);
        return '<div style="margin-bottom:.75rem;">'
            . '<a href="' . module_url('operacional', 'caso_ver.php?id=' . $fromCase) . '" class="btn btn-outline btn-sm" style="font-size:.82rem;">'
            . '&larr; Voltar ao processo: <strong>' . e($label) . '</strong></a></div>';
    } catch (Exception $e) { return ''; }
}

// ─── Auditoria ──────────────────────────────────────────
function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    $userId = $_SESSION['user']['id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    db()->prepare(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $action, $entityType, $entityId, $details, $ip]);
}

// ─── Paginação ──────────────────────────────────────────
function paginate(int $total, int $perPage = 20, int $currentPage = 1): array
{
    $totalPages = max(1, (int)ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $currentPage,
        'total_pages' => $totalPages,
        'offset'      => $offset,
    ];
}
