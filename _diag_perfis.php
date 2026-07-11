<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "Perfis atuais no banco:\n\n";
foreach ($pdo->query("SELECT id, nome, slug, ticket_min, ticket_max, verba_min, verba_max, cor_hex, ordem, ativo, created_at FROM presenca_perfil") as $r) {
    print_r($r);
}
echo "\nCSRF token name esperado: ";
require_once __DIR__ . '/core/functions_utils.php';
if (defined('CSRF_TOKEN_NAME')) echo CSRF_TOKEN_NAME;
else echo '(nao definida — usar padrao csrf_token)';
echo "\n\nfunction validate_csrf existe: " . (function_exists('validate_csrf') ? 'sim' : 'nao') . "\n";
echo "csrf_input() saida: " . (function_exists('csrf_input') ? csrf_input() : '(nao existe)') . "\n";
