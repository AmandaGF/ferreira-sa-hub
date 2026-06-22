<?php
/** Diag temp: mostra variações da mensagem de grupo. Remover após uso. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_comercial.php';
$partes = array('Nativânia (4)', 'Maria (2)');
for ($i = 1; $i <= 5; $i++) {
    echo "--- variação $i ---\n" . comercial_msg_grupo($partes) . "\n\n";
}
