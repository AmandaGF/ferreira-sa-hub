<?php
/** Lê o log temporário de diagnóstico do submit do despesas-mensais.
 *  ?key=fsa-hub-deploy-2026  (&limpar=1 zera) */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/publico/despesas-mensais/_submit_debug.log';
if (isset($_GET['limpar'])) { @file_put_contents($f, ''); exit("log limpo\n"); }
if (!is_file($f)) { exit("(sem registros — peça pra preencher e enviar uma vez)\n"); }
$l = file($f, FILE_IGNORE_NEW_LINES);
echo "=== últimas 80 linhas (_submit_debug) ===\n" . implode("\n", array_slice($l, -80)) . "\n";
