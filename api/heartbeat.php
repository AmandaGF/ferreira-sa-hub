<?php
/**
 * Heartbeat — mantém sessão viva e retorna CSRF token atual.
 * Chamado a cada 4 min pelo JS global do layout.
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(array('error' => 'session_expired'));
    exit;
}

require_once __DIR__ . '/../core/functions_utils.php';
echo json_encode(array(
    'ok'    => true,
    'csrf'  => generate_csrf_token(),
    'user'  => current_user_id(),
    'ts'    => time(),
));
