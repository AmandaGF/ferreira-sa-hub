<?php
/** Diag temp: render caso_ver com botão Renúncia/Desistência. Remover. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        echo "\n\n!!! FATAL: " . $e['message'] . " em " . $e['file'] . ":" . $e['line'] . "\n";
    }
});
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$cid = (int)$pdo->query("SELECT id FROM cases ORDER BY id DESC LIMIT 1")->fetchColumn();
@session_start();
$_SESSION['user'] = array('id' => 1, 'name' => 'Amanda', 'email' => 'a@a.com', 'role' => 'admin');
$_GET['id'] = $cid;
ob_start(); require __DIR__ . '/modules/operacional/caso_ver.php'; $h = ob_get_clean();
header('Content-Type: text/plain; charset=utf-8');
echo "case #$cid — bytes: " . strlen($h) . "\n";
echo "botão Renúncia/Desistência: " . (strpos($h, '📤 Renúncia/Desistência') !== false ? 'sim' : 'NAO') . "\n";
echo "modal renModal: " . (strpos($h, 'renModal') !== false ? 'sim' : 'NAO') . "\n";
echo "case_ids[] no modal: " . (strpos($h, 'name="case_ids[]"') !== false ? 'sim' : 'NAO') . "\n";
echo "erro PHP: " . (strpos($h, 'Fatal error') !== false ? 'SIM' : 'nao') . "\n";
