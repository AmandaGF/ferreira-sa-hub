<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/modules/operacional/caso_ver.php';
$cont = file_get_contents($f);
echo "filemtime: " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
echo "size: " . strlen($cont) . " bytes\n\n";

// Chave: procura pelo textarea novo
$marker = 'modalRealAud_obsCliente';
$has = strpos($cont, $marker);
echo ($has !== false ? "OK: textarea '{$marker}' PRESENTE (offset ~{$has})" : "FALTA: textarea '{$marker}' AUSENTE") . "\n";

$aviso = 'o cliente <strong>terá acesso ao texto</strong>';
$hasAviso = strpos($cont, $aviso);
echo ($hasAviso !== false ? "OK: banner de aviso PRESENTE" : "FALTA: banner de aviso AUSENTE") . "\n";

// Verifica sw.js pra confirmar CACHE_NAME atual
$sw = file_get_contents(__DIR__ . '/sw.js');
if (preg_match("/CACHE_NAME = '([^']+)'/", $sw, $m)) {
    echo "\nsw.js CACHE_NAME: {$m[1]}\n";
}
