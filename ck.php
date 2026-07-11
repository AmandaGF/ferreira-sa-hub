<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$log = __DIR__ . '/error_log';
if (is_file($log)) {
    $c = @file_get_contents($log);
    echo substr($c, -6000);
} else echo "sem log";
