<?php
/** Diag temp: render-test do seletor de grupos no CRM Comercial. Remover após uso. */
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
// quantos grupos por canal existem (o que vai aparecer pra clicar)
foreach ($pdo->query("SELECT i.ddd canal, COUNT(*) q FROM zapi_conversas co JOIN zapi_instancias i ON i.id=co.instancia_id WHERE COALESCE(co.eh_grupo,0)=1 GROUP BY i.ddd")->fetchAll() as $r) {
    echo "canal {$r['canal']}: {$r['q']} grupo(s)\n";
}
echo "\n--- render ---\n";
@session_start();
$_SESSION['user'] = array('id' => 1, 'name' => 'Amanda', 'email' => 'a@a.com', 'role' => 'admin');
ob_start();
require __DIR__ . '/modules/crm_comercial/index.php';
$html = ob_get_clean();
echo "RENDER OK — bytes: " . strlen($html) . "\n";
echo "tem seletor de grupos: " . (strpos($html, 'cc-grpbtn') !== false ? 'sim' : 'NAO (provavel 0 grupos)') . "\n";
echo "tem botão Salvar: " . (strpos($html, 'salvar_config') !== false ? 'sim' : 'NAO') . "\n";
echo "erro PHP: " . (strpos($html, 'Fatal error') !== false ? 'SIM' : 'nao') . "\n";
