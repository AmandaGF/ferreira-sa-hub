<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('no');
header('Content-Type: text/plain');
foreach (array(__DIR__.'/files', __DIR__.'/uploads', sys_get_temp_dir()) as $d) {
    $ok = @file_put_contents($d.'/_wtest.txt', 'x');
    echo $d . ' => ' . ($ok !== false ? 'GRAVAVEL' : 'NAO gravavel') . (is_dir($d)?'':' (dir nao existe)') . "\n";
    @unlink($d.'/_wtest.txt');
}
