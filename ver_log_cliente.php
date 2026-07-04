<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
$achou = false;
foreach (array(__DIR__ . '/files', __DIR__ . '/uploads', sys_get_temp_dir()) as $dir) {
    $f = $dir . '/cliente_last_error.log';
    if (file_exists($f)) { echo "### $f ###\n" . file_get_contents($f) . "\n"; $achou = true; }
}
if (!$achou) echo "log inexistente em nenhum dir (handler nao gravou / 500 nao e E_ERROR)\n";
