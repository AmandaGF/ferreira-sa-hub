<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Listar error_log em todas as subpastas de modules/
$base = __DIR__;
function findLogs($dir) {
    $res = array();
    foreach (@scandir($dir) ?: array() as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        if (is_dir($p)) $res = array_merge($res, findLogs($p));
        elseif (in_array($e, array('error_log', 'php_errorlog'), true)) {
            $sz = filesize($p);
            $mt = filemtime($p);
            if ($sz > 0 && (time() - $mt) < 86400*2) {
                $res[] = array('path' => $p, 'size' => $sz, 'mtime' => $mt);
            }
        }
    }
    return $res;
}
$logs = findLogs($base);
usort($logs, function($a,$b){ return $b['mtime'] - $a['mtime']; });

echo "=== Error logs recentes (últimas 48h) ===\n\n";
foreach (array_slice($logs, 0, 5) as $l) {
    echo "--- {$l['path']} ({$l['size']} bytes, " . date('d/m/Y H:i:s', $l['mtime']) . ") ---\n";
    $lines = file($l['path']);
    foreach (array_slice($lines, -15) as $line) echo "  " . trim($line) . "\n";
    echo "\n";
}
