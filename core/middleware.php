<?php
/**
 * Ferreira & Sá Conecta — Middleware de Proteção
 */

require_once __DIR__ . '/auth.php';

function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('error', 'Faça login para acessar o sistema.');
        redirect(url('auth/login.php'));
    }
}

function require_role(string ...$roles): void
{
    require_login();

    if (!has_role(...$roles)) {
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
