<?php
/** Leitor READ-ONLY de pastas/arquivos da RAIZ (planejar melhoria de forms
 *  antigos fora do repo, ex.: /curatela/). Não altera nada.
 *  ?key=fsa-hub-deploy-2026&dir=curatela        → lista a pasta
 *  ?key=...&file=curatela/index.php             → mostra o arquivo
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$root = dirname(__DIR__); // public_html

// whitelist defensiva: só pastas de forms públicos conhecidas
$permitidas = array('curatela','cadastro','calculadora','convivencia_form','gastos_pensao','audiencias','helpdesk','portal','cadastro_cliente');

$dir  = trim($_GET['dir'] ?? '');
$file = trim($_GET['file'] ?? '');

function _safe($p, $permitidas) {
    $p = ltrim(str_replace('\\', '/', $p), '/');
    if (strpos($p, '..') !== false) return false;
    $base = strtok($p, '/');
    return in_array($base, $permitidas, true) ? $p : false;
}

if ($dir !== '') {
    $d = _safe($dir, $permitidas);
    if ($d === false) exit("pasta não permitida\n");
    $full = $root . '/' . $d;
    if (!is_dir($full)) exit("não é pasta: $full\n");
    echo "=== $full ===\n";
    foreach (scandir($full) as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = $full . '/' . $f;
        echo (is_dir($fp) ? '[DIR ] ' : '[file] ') . $f . (is_file($fp) ? ' (' . round(filesize($fp)/1024,1) . ' KB)' : '') . "\n";
    }
    exit;
}

if ($file !== '') {
    $fl = _safe($file, $permitidas);
    if ($fl === false) exit("arquivo não permitido\n");
    $full = $root . '/' . $fl;
    if (!is_file($full)) exit("não existe: $full\n");
    echo "=== $full (" . round(filesize($full)/1024,1) . " KB) ===\n\n";
    echo file_get_contents($full);
    exit;
}
exit("Use ?dir=curatela  ou  ?file=curatela/index.php\n");
