<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$f = dirname(__DIR__) . '/salavip/pages/mensagem_ver.php';
echo "== $f ==\n\n";
echo file_get_contents($f);
