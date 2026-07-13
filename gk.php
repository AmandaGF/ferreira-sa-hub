<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
header('Content-Type: text/plain; charset=utf-8');
echo "ANTHROPIC_API_KEY:\n";
echo (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '(nao definida)') . "\n";
