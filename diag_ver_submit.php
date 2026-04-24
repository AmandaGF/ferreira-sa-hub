<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
$path = dirname(__DIR__) . '/convivencia_form/submit.php';
echo "=== Conteúdo de {$path} ===\n\n";
echo file_get_contents($path);
