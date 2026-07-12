<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$log = __DIR__ . '/error_log';
if (is_file($log)) echo substr(file_get_contents($log), -3000);
