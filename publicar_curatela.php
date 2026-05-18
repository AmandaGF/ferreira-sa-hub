<?php
/**
 * Publica a versão melhorada do /curatela/ (UX mobile) do repositório
 * (conecta/publico/curatela/) pra raiz do servidor (/curatela/).
 * Mexe SÓ em index.html, styles.css, script.js — PDFs e assets/ intactos.
 * Backup automático + rollback de 1 clique.
 *
 *   ?key=fsa-hub-deploy-2026            → status
 *   ?key=...&go=1                       → publica (faz backup antes)
 *   ?key=...&rollback=1                 → restaura o último backup
 */
ini_set('display_errors','1'); error_reporting(E_ALL);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$root  = dirname(__DIR__) . '/curatela';            // /home7/.../public_html/curatela
$srcDir = __DIR__ . '/publico/curatela';            // versão nova no repo
$arquivos = array('index.html','styles.css','script.js');

if (!is_dir($root))   exit("Pasta destino não existe: {$root}\n");
if (!is_dir($srcDir)) exit("Fonte do repo não existe: {$srcDir}\n");

$acao = isset($_GET['go']) ? 'go' : (isset($_GET['rollback']) ? 'rollback' : 'status');

if ($acao === 'status') {
    echo "=== STATUS /curatela/ ===\n";
    foreach ($arquivos as $f) {
        $d = $root . '/' . $f; $s = $srcDir . '/' . $f;
        echo sprintf("  %-12s servidor=%s  repo=%s  %s\n", $f,
            is_file($d) ? round(filesize($d)/1024,1).'KB' : '—',
            is_file($s) ? round(filesize($s)/1024,1).'KB' : '—',
            (is_file($d) && is_file($s) && md5_file($d) === md5_file($s)) ? '(iguais)' : '(DIFERENTE)');
    }
    $bks = glob($root . '/.bak-*');
    echo "Backups: " . (count($bks) ? implode(', ', array_map('basename',$bks)) : '(nenhum)') . "\n";
    echo "\n&go=1 publica · &rollback=1 reverte\n";
    exit;
}

if ($acao === 'rollback') {
    $bks = glob($root . '/.bak-*', GLOB_ONLYDIR);
    if (!$bks) { exit("Sem backup pra restaurar.\n"); }
    natsort($bks); $ultimo = end($bks);
    $n = 0;
    foreach ($arquivos as $f) {
        if (is_file($ultimo . '/' . $f) && @copy($ultimo . '/' . $f, $root . '/' . $f)) $n++;
    }
    echo "✓ ROLLBACK: $n arquivo(s) restaurado(s) de " . basename($ultimo) . "\n";
    exit;
}

// === GO ===
$bkDir = $root . '/.bak-' . date('Ymd-His');
if (!@mkdir($bkDir, 0755) && !is_dir($bkDir)) { exit("FALHA ao criar backup {$bkDir}. Abortado.\n"); }
foreach ($arquivos as $f) {
    if (is_file($root . '/' . $f)) {
        if (!@copy($root . '/' . $f, $bkDir . '/' . $f)) { exit("FALHA backup de $f. Abortado (nada publicado).\n"); }
    }
}
$pub = 0;
foreach ($arquivos as $f) {
    if (!is_file($srcDir . '/' . $f)) { echo "  (pulado, sem fonte: $f)\n"; continue; }
    if (@copy($srcDir . '/' . $f, $root . '/' . $f)) { @chmod($root . '/' . $f, 0644); $pub++; echo "  ✓ $f publicado\n"; }
    else echo "  ✗ FALHA ao publicar $f\n";
}
echo "\n✓ {$pub} arquivo(s) publicados em {$root}\n";
echo "Backup do anterior: " . basename($bkDir) . "\n";
echo "Reverter: publicar_curatela.php?key=fsa-hub-deploy-2026&rollback=1\n";
echo "PDFs e assets/ NÃO foram tocados.\n";
