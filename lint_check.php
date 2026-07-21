<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$out = array();
foreach (array(
    __DIR__ . '/modules/operacional/caso_ver.php',
) as $f) {
    $code = shell_exec("php -l " . escapeshellarg($f) . " 2>&1");
    $out[] = $f . ":\n" . $code;
}
echo implode("\n", $out);
