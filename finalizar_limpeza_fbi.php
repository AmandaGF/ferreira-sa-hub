<?php
/**
 * Ultimo passo do rename GERID -> FBI $: remover a pasta modules/gerid/ que
 * sobrou no servidor contendo apenas 'error_log' (arquivo gerado pelo PHP,
 * nao e codigo). O index.php velho ja foi apagado, entao o modulo antigo nao
 * roda mais — isso aqui e so pra nao restar a palavra no filesystem.
 *
 * Arquivo com nome NOVO de proposito: o limpar_gerid_orfaos.php editado nao
 * propagava (opcache servindo bytecode antigo do mesmo caminho).
 *
 * Key-protected. Idempotente.
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$dir = __DIR__ . '/modules/gerid';

echo "=== Finalizar limpeza: modules/gerid/ ===\n\n";

if (!is_dir($dir)) {
    echo "[SKIP] pasta ja nao existe. Nada a fazer.\n";
    exit;
}

// Trava de seguranca: se por algum motivo houver .php aqui, NAO apaga nada.
$itens = array_values(array_diff(scandir($dir), array('.', '..')));
echo "Conteudo atual: " . (empty($itens) ? '(vazia)' : implode(', ', $itens)) . "\n\n";

$temPhp = false;
foreach ($itens as $i) {
    if (substr(strtolower($i), -4) === '.php') { $temPhp = true; break; }
}
if ($temPhp) {
    echo "[ABORTA] ainda ha arquivo .php na pasta — nao vou apagar.\n";
    echo "         Rode limpar_gerid_orfaos.php primeiro.\n";
    exit;
}

// Mostra o error_log antes de remover (pode ter erro util do modulo velho)
$log = $dir . '/error_log';
if (file_exists($log)) {
    $tam = (int)filesize($log);
    echo "--- modules/gerid/error_log ({$tam} bytes) — ultimas 15 linhas ---\n";
    $txt = (string)@file_get_contents($log, false, null, max(0, $tam - 3000));
    $linhas = array_values(array_filter(array_map('rtrim', explode("\n", $txt))));
    foreach (array_slice($linhas, -15) as $ln) echo "  " . $ln . "\n";
    echo "--- fim do log ---\n\n";
}

foreach ($itens as $i) {
    $p = $dir . '/' . $i;
    if (is_dir($p)) { echo "[PULA] {$i} e uma pasta\n"; continue; }
    echo (@unlink($p) ? "[OK] apagado {$i}\n" : "[ERRO] nao consegui apagar {$i}\n");
}

clearstatcache();
$resto = array_values(array_diff(scandir($dir), array('.', '..')));
if (empty($resto)) {
    echo (@rmdir($dir) ? "\n[OK] pasta modules/gerid/ REMOVIDA\n"
                       : "\n[ERRO] nao consegui remover a pasta (permissao?)\n");
} else {
    echo "\n[ATENCAO] ainda restou: " . implode(', ', $resto) . "\n";
}

clearstatcache();
echo "\nmodules/gerid/ ainda existe? " . (is_dir($dir) ? 'SIM' : 'NAO') . "\n";
echo "modules/fbi_vinculo/index.php existe? "
   . (file_exists(__DIR__ . '/modules/fbi_vinculo/index.php') ? 'SIM' : 'NAO — PROBLEMA!') . "\n";
echo "\n=== Fim ===\n";
