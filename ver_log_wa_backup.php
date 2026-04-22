<?php
/**
 * ver_log_wa_backup.php — leitura rápida do log do backup WhatsApp
 * Os logs em /files/ são bloqueados por web server (403), então
 * este arquivo na raiz serve pra consultar o conteúdo.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
$log = __DIR__ . '/files/wa_backup.log';
if (!file_exists($log)) {
    echo "Log ainda não existe: $log\n";
    exit;
}
echo "=== " . $log . " ===\n";
echo "Tamanho: " . filesize($log) . " bytes\n";
echo "Última modificação: " . date('Y-m-d H:i:s', filemtime($log)) . "\n\n";

// Últimas 300 linhas
$lines = file($log, FILE_IGNORE_NEW_LINES);
$tail = array_slice($lines, -300);
echo implode("\n", $tail);
