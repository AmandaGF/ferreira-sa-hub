<?php
/** Diag temp: render gerid + caso_ver com botão GERID. Remover. */
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
@session_start();
$_SESSION['user'] = array('id' => 1, 'name' => 'Amanda', 'email' => 'a@a.com', 'role' => 'admin');
header('Content-Type: text/plain; charset=utf-8');

// 1) módulo gerid
$_GET = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require __DIR__ . '/modules/gerid/index.php'; $h = ob_get_clean();
echo "gerid RENDER — bytes: " . strlen($h) . " | pendentes/concluidas: " . (strpos($h,'Pendentes')!==false?'sim':'NAO') . " | fatal: " . (strpos($h,'Fatal error')!==false?'SIM':'nao') . "\n";

// 2) caso_ver com botão
$cid = (int)$pdo->query("SELECT id FROM cases ORDER BY id DESC LIMIT 1")->fetchColumn();
$_GET = array('id'=>$cid);
ob_start(); require __DIR__ . '/modules/operacional/caso_ver.php'; $h2 = ob_get_clean();
echo "caso_ver #$cid — bytes: " . strlen($h2) . " | botão GERID: " . (strpos($h2,'Pesquisar vínculo (GERID)')!==false?'sim':'NAO') . " | modal gdModal: " . (strpos($h2,'gdModal')!==false?'sim':'NAO') . " | fatal: " . (strpos($h2,'Fatal error')!==false?'SIM':'nao') . "\n";
