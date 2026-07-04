<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
foreach (array('cliente_trace.log', 'cliente_last_error.log') as $nome) {
    echo "\n========== $nome ==========\n";
    $achou = false;
    foreach (array(__DIR__ . '/files', __DIR__ . '/uploads', sys_get_temp_dir()) as $dir) {
        $f = $dir . '/' . $nome;
        if (file_exists($f)) { echo file_get_contents($f); $achou = true; }
    }
    if (!$achou) echo "(inexistente)\n";
}
// ?limpar=1 zera os logs pra próxima captura ficar limpa
if (!empty($_GET['limpar'])) {
    foreach (array('cliente_trace.log', 'cliente_last_error.log') as $nome) {
        foreach (array(__DIR__ . '/files', __DIR__ . '/uploads', sys_get_temp_dir()) as $dir) { @unlink($dir . '/' . $nome); }
    }
    echo "\n[logs limpos]\n";
}
