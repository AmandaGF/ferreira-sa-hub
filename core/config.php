<?php
/**
 * Ferreira & Sá Conecta — Configuração Central
 */

// ─── Ambiente ───────────────────────────────────────────
date_default_timezone_set('America/Sao_Paulo');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ─── Aplicação ──────────────────────────────────────────
define('APP_NAME',    'Ferreira & Sá Hub');
define('APP_VERSION', '1.0.0');
define('APP_ROOT',    dirname(__DIR__));          // /conecta
define('BASE_URL',    '/conecta');                // ajustar se usar subdomínio

// ─── Banco de Dados ─────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'ferre3151357_conecta');
define('DB_USER',    'ferre3151357_conecta_user');
define('DB_PASS',    'ALTERAR_SENHA_AQUI');       // ← trocar no servidor
define('DB_CHARSET', 'utf8mb4');

// ─── Segurança ──────────────────────────────────────────
define('SESSION_NAME',     'FSA_CONECTA');
define('SESSION_LIFETIME', 28800);                // 8 horas
define('CSRF_TOKEN_NAME',  'csrf_token');
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// Chave para criptografar credenciais do portal
define('ENCRYPT_KEY', 'ALTERAR_CHAVE_AQUI');      // ← gerar com: bin2hex(random_bytes(32))
define('ENCRYPT_METHOD', 'aes-256-cbc');

// ─── Headers de Segurança ───────────────────────────────
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
}

// ─── Sessão ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    }
    session_name(SESSION_NAME);
    session_set_cookie_params(SESSION_LIFETIME);
    session_start();
}
