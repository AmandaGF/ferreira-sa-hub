<?php
/**
 * Ferreira & Sá Conecta — Middleware de Proteção
 */

require_once __DIR__ . '/auth.php';

/**
 * Detecta requisição AJAX (XMLHttpRequest / fetch) pelo header padrão.
 */
function _mid_is_ajax(): bool {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    // fetch() moderno sem header customizado: se Accept for JSON, tratamos como AJAX
    if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
    return false;
}

/**
 * Retorna erro JSON em AJAX (evita que XHR receba HTML de redirect como 200 OK).
 */
function _mid_json_fail(int $code, string $msg): void {
    http_response_code($code);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $msg));
    exit;
}

function require_login(): void
{
    if (!is_logged_in()) {
        if (_mid_is_ajax()) _mid_json_fail(401, 'Sessão expirada. Recarregue a página (F5).');
        flash_set('error', 'Faça login para acessar o sistema.');
        redirect(url('auth/login.php'));
    }
}

function require_role(string ...$roles): void
{
    require_login();

    if (!has_role(...$roles)) {
        if (_mid_is_ajax()) _mid_json_fail(403, 'Sem permissão para esta ação.');
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Acesso Negado</title>';
        echo '<style>body{font-family:"Open Sans",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f4f4;color:#052228}';
        echo '.box{text-align:center;padding:3rem;background:#fff;border-radius:18px;box-shadow:0 4px 24px rgba(0,0,0,.08)}';
        echo 'h1{font-size:1.5rem;margin:0 0 .5rem}p{color:#6b7280;margin:0 0 1.5rem}';
        echo 'a{color:#d7ab90;text-decoration:none;font-weight:600}</style></head>';
        echo '<body><div class="box"><h1>Acesso Negado</h1>';
        echo '<p>Você não tem permissão para acessar esta página.</p>';
        echo '<a href="' . url('modules/dashboard/') . '">Voltar ao painel</a>';
        echo '</div></body></html>';
        exit;
    }
}

function require_min_role(string $minRole): void
{
    require_login();

    if (!has_min_role($minRole)) {
        if (_mid_is_ajax()) _mid_json_fail(403, 'Permissão insuficiente.');
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Acesso Negado</title>';
        echo '<style>body{font-family:"Open Sans",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f4f4;color:#052228}';
        echo '.box{text-align:center;padding:3rem;background:#fff;border-radius:18px;box-shadow:0 4px 24px rgba(0,0,0,.08)}';
        echo 'h1{font-size:1.5rem;margin:0 0 .5rem}p{color:#6b7280;margin:0 0 1.5rem}';
        echo 'a{color:#d7ab90;text-decoration:none;font-weight:600}</style></head>';
        echo '<body><div class="box"><h1>Acesso Negado</h1>';
        echo '<p>Você não tem permissão para acessar esta página.</p>';
        echo '<a href="' . url('modules/dashboard/') . '">Voltar ao painel</a>';
        echo '</div></body></html>';
        exit;
    }
}

/**
 * Verifica acesso a um módulo respeitando overrides de user_permissions.
 * Usa can_access() que checa: admin → override → default do role.
 */
function require_access(string $module): void
{
    require_login();

    if (!can_access($module)) {
        if (_mid_is_ajax()) _mid_json_fail(403, "Sem acesso ao módulo '{$module}'.");
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Acesso Negado</title>';
        echo '<style>body{font-family:"Open Sans",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f4f4;color:#052228}';
        echo '.box{text-align:center;padding:3rem;background:#fff;border-radius:18px;box-shadow:0 4px 24px rgba(0,0,0,.08)}';
        echo 'h1{font-size:1.5rem;margin:0 0 .5rem}p{color:#6b7280;margin:0 0 1.5rem}';
        echo 'a{color:#d7ab90;text-decoration:none;font-weight:600}</style></head>';
        echo '<body><div class="box"><h1>Acesso Negado</h1>';
        echo '<p>Você não tem permissão para acessar este módulo.</p>';
        echo '<a href="' . url('modules/dashboard/') . '">Voltar ao painel</a>';
        echo '</div></body></html>';
        exit;
    }
}
