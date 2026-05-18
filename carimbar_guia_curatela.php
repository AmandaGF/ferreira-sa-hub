<?php
/**
 * Carimba o monograma FF nas 11 páginas do guia de Curatela
 * (/curatela/assets/page-XX.png). Idempotente: sempre estampa a partir do
 * backup original (re-rodar não duplica). Reversível.
 *
 *   ?key=fsa-hub-deploy-2026             → status
 *   ?key=...&go=1                        → carimba (backup automático)
 *   ?key=...&restore=1                   → restaura os originais
 */
ini_set('display_errors','1'); error_reporting(E_ALL);
ini_set('memory_limit','512M'); @set_time_limit(300);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$dir   = dirname(__DIR__) . '/curatela/assets';
$bak   = $dir . '/_orig_bak';
$logoP = __DIR__ . '/assets/img/site/monograma.png';
$pages = array();
for ($i = 1; $i <= 11; $i++) $pages[] = sprintf('page-%02d.png', $i);

if (!is_dir($dir))  exit("assets não encontrado: $dir\n");
if (!is_file($logoP)) exit("logo não encontrada: $logoP\n");
if (!function_exists('imagecreatefrompng')) exit("GD indisponível no PHP\n");

$acao = isset($_GET['go']) ? 'go' : (isset($_GET['restore']) ? 'restore' : 'status');

if ($acao === 'status') {
    echo "=== STATUS carimbo guia ===\n";
    echo "Backup: " . (is_dir($bak) ? 'existe' : '(nenhum — ainda não carimbado)') . "\n";
    foreach ($pages as $p) {
        $o = is_file($dir."/$p") ? round(filesize($dir."/$p")/1024).'KB' : '—';
        $b = is_file($bak."/$p") ? round(filesize($bak."/$p")/1024).'KB' : '—';
        echo "  $p  atual=$o  backup=$b\n";
    }
    echo "\n&go=1 carimba · &restore=1 restaura\n";
    exit;
}

if ($acao === 'restore') {
    if (!is_dir($bak)) exit("Sem backup pra restaurar.\n");
    $n = 0;
    foreach ($pages as $p) if (is_file($bak."/$p") && @copy($bak."/$p", $dir."/$p")) $n++;
    exit("✓ RESTORE: $n página(s) voltaram ao original.\n");
}

// === GO ===
if (!is_dir($bak) && !@mkdir($bak, 0755)) exit("FALHA ao criar backup dir. Abortado.\n");
$logo = @imagecreatefrompng($logoP);
if (!$logo) exit("FALHA ao ler a logo.\n");
$lw = imagesx($logo); $lh = imagesy($logo);

$ok = 0; $msg = array();
foreach ($pages as $p) {
    $src = $dir . "/$p";
    if (!is_file($src)) { $msg[] = "  (pulado, não existe: $p)"; continue; }
    // backup 1x; depois sempre carimba a partir do backup (idempotente)
    if (!is_file($bak."/$p")) { if (!@copy($src, $bak."/$p")) { $msg[]="  ✗ falha backup $p (pulado)"; continue; } }
    $im = @imagecreatefrompng($bak."/$p");
    if (!$im) { $msg[] = "  ✗ falha ler $p"; continue; }
    imagealphablending($im, true);
    $pw = imagesx($im); $ph = imagesy($im);
    $tw = max(70, (int)round($pw * 0.11)); $th = (int)round($tw * ($lh / $lw));
    $margin = (int)round($pw * 0.028);
    $x = $pw - $tw - $margin; $y = $margin;
    // moldura branca atrás (vira "selo")
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, $x-4, $y-4, $x+$tw+4, $y+$th+4, $white);
    imagecopyresampled($im, $logo, $x, $y, 0, 0, $tw, $th, $lw, $lh);
    if (@imagepng($im, $src, 6)) { $ok++; $msg[] = "  ✓ $p ({$pw}x{$ph}, selo {$tw}px)"; }
    else $msg[] = "  ✗ falha gravar $p";
    imagedestroy($im);
}
imagedestroy($logo);
echo "=== CARIMBO CONCLUÍDO: $ok/11 ===\n" . implode("\n", $msg) . "\n";
echo "\nOriginais em {$bak}. Reverter: carimbar_guia_curatela.php?key=fsa-hub-deploy-2026&restore=1\n";
echo "Obs: o PDF baixável é arquivo separado e NÃO foi alterado.\n";
