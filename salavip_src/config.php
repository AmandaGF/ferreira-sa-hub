<?php
/**
 * Central VIP F&S — Configuração
 */
date_default_timezone_set('America/Sao_Paulo');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('SALAVIP_VERSION', '1.0');
define('SALAVIP_BASE_URL', '/salavip');
define('SALAVIP_UPLOAD_DIR', __DIR__ . '/uploads/');
define('SALAVIP_MAX_UPLOAD', 10485760); // 10MB
define('SALAVIP_SESSION_TIMEOUT', 1800); // 30 min
define('SALAVIP_SESSION_PREFIX', 'salavip_');

// Ler credenciais do Conecta Hub (sem incluir o middleware)
$_configPath = dirname(__DIR__) . '/conecta/core/config.php';
if (!file_exists($_configPath)) {
    die('Configuração do Conecta não encontrada.');
}
// Only include if constants not yet defined (avoid re-definition)
if (!defined('DB_HOST')) {
    require_once $_configPath;
}

// Conexão PDO própria (não usar a do Conecta)
function sv_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES     => false,
                // Buffered query: baixa todos os resultados na hora, libera o cursor automaticamente.
                // Sem isso, fetch() em loop deixa cursor aberto e a próxima execute() erra com
                // "SQLSTATE[HY000] 2014: Cannot execute queries while other unbuffered queries are active"
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
    }
    return $pdo;
}

// Sessão separada do Conecta
if (session_status() === PHP_SESSION_NONE) {
    session_name('salavip_session');
    session_start();
}
