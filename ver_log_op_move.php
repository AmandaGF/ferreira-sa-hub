<?php
/** Lê o log temporário de diagnóstico do "mover card" do Kanban Operacional.
 *  ?key=fsa-hub-deploy-2026   (&limpar=1 zera o log) */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/files/op_move_debug.log';
if (isset($_GET['limpar'])) { @file_put_contents($f, ''); exit("log limpo\n"); }
if (!is_file($f)) { exit("(sem registros ainda — mova um card e recarregue aqui)\n"); }
$linhas = file($f, FILE_IGNORE_NEW_LINES);
echo "=== últimas 60 linhas (op_move_debug) ===\n";
echo implode("\n", array_slice($linhas, -60)) . "\n";
