<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
$log = __DIR__ . '/files/zapi_webhook.log';
if (!file_exists($log)) { echo "Sem log."; exit; }
// Últimas N linhas via tail manual
$n = (int)($_GET['n'] ?? 200);
$lines = file($log, FILE_IGNORE_NEW_LINES);
$tail = array_slice($lines, -$n);
echo implode("\n", $tail);
