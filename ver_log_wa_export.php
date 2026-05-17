<?php
/** Lê o log de diagnóstico do "exportar conversa → Drive".
 *  ?key=fsa-hub-deploy-2026   (&limpar=1 zera) */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/files/wa_export_debug.log';
if (isset($_GET['limpar'])) { @file_put_contents($f, ''); exit("log limpo\n"); }
if (!is_file($f)) { exit("(sem registros — exporte uma conversa pra uma pasta e recarregue aqui)\n"); }
echo implode("\n", array_slice(file($f, FILE_IGNORE_NEW_LINES), -40)) . "\n";
