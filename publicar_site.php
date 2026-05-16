<?php
/**
 * Publica o site novo na RAIZ (home do www) — troca SÓ a homepage.
 * Tudo o mais (WordPress, /conecta, /salavip, formulários públicos) fica igual.
 * 100% reversível: backup do .htaccess + rollback de 1 clique.
 *
 *   ?key=fsa-hub-deploy-2026                 → status (não muda nada)
 *   ?key=...&preview=1                       → mostra o .htaccess que seria gravado
 *   ?key=...&go=1                            → publica (faz backup antes)
 *   ?key=...&rollback=1                      → restaura o último backup
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);                 // /home7/.../public_html
$ht   = $root . '/.htaccess';
$MARK_INI = '# BEGIN SITE NOVO (Ferreira & Sa) -- publicar_site.php';
$MARK_FIM = '# END SITE NOVO';
$bloco = $MARK_INI . "\n"
       . "RewriteRule ^$ /conecta/lp/v2.php [L]\n"
       . $MARK_FIM;

if (!is_file($ht)) { exit("ERRO: {$ht} não existe.\n"); }
$cur = file_get_contents($ht);
$jaTem = (strpos($cur, $MARK_INI) !== false);

function montar($cur, $bloco, $MARK_INI, $MARK_FIM) {
    // Remove bloco antigo se existir (idempotente)
    $cur = preg_replace('/\R?' . preg_quote($MARK_INI, '/') . '.*?' . preg_quote($MARK_FIM, '/') . '\R?/s', "\n", $cur);
    // Insere logo após a regra do /conecta (antes do WP Rocket / WP)
    $anchor = "RewriteRule ^conecta/ - [L]";
    if (strpos($cur, $anchor) !== false) {
        return str_replace($anchor, $anchor . "\n\n" . $bloco . "\n", $cur);
    }
    if (preg_match('/RewriteEngine\s+On/i', $cur)) {
        return preg_replace('/(RewriteEngine\s+On)/i', "$1\n\n" . $bloco . "\n", $cur, 1);
    }
    return "RewriteEngine On\n" . $bloco . "\n\n" . $cur;
}

$acao = isset($_GET['go']) ? 'go' : (isset($_GET['rollback']) ? 'rollback' : (isset($_GET['preview']) ? 'preview' : 'status'));

if ($acao === 'status') {
    echo "=== STATUS ===\n";
    echo "Raiz       : {$root}\n";
    echo ".htaccess  : " . filesize($ht) . " bytes\n";
    echo "Site novo na home? " . ($jaTem ? "SIM (publicado)" : "NÃO (WordPress ainda na home)") . "\n";
    $bks = glob($root . '/.htaccess.pre-site-*');
    echo "Backups    : " . (count($bks) ? implode(', ', array_map('basename', $bks)) : '(nenhum)') . "\n";
    echo "\nTopo atual do .htaccess:\n---\n" . substr($cur, 0, 400) . "\n---\n";
    echo "\nUse &preview=1 (ver), &go=1 (publicar), &rollback=1 (reverter).\n";
    exit;
}

if ($acao === 'preview') {
    echo "=== PREVIEW (nada gravado) ===\n\n" . substr(montar($cur, $bloco, $MARK_INI, $MARK_FIM), 0, 700) . "\n...\n";
    exit;
}

if ($acao === 'rollback') {
    $bks = glob($root . '/.htaccess.pre-site-*');
    if (!$bks) { exit("Nenhum backup .htaccess.pre-site-* pra restaurar.\n"); }
    natsort($bks); $ultimo = end($bks);
    if (!@copy($ultimo, $ht)) { exit("FALHA ao restaurar {$ultimo}\n"); }
    echo "✓ ROLLBACK feito. Restaurado: " . basename($ultimo) . "\n";
    echo "A home voltou ao estado anterior (WordPress). Confira https://ferreiraesa.com.br/\n";
    exit;
}

// === GO: publicar ===
if ($jaTem) { exit("Já estava publicado (bloco presente). Nada a fazer. (use &rollback=1 pra reverter)\n"); }

$bkName = $root . '/.htaccess.pre-site-' . date('Ymd-His');
if (!@copy($ht, $bkName)) { exit("FALHA ao criar backup {$bkName}. Abortado (nada alterado).\n"); }

$novo = montar($cur, $bloco, $MARK_INI, $MARK_FIM);
if (strpos($novo, $MARK_INI) === false) { exit("FALHA ao montar o bloco. Abortado (nada alterado).\n"); }
if (@file_put_contents($ht, $novo) === false) { exit("FALHA ao gravar .htaccess. Backup em " . basename($bkName) . "\n"); }

echo "✓ PUBLICADO! A home (https://ferreiraesa.com.br/) agora serve o site novo.\n";
echo "  Backup do .htaccess anterior: " . basename($bkName) . "\n";
echo "  Reverter a qualquer momento: publicar_site.php?key=fsa-hub-deploy-2026&rollback=1\n";
echo "\nResto intacto: WordPress (wp-admin), /conecta, /salavip, formulários públicos.\n";
