<?php
/**
 * Corrige o bug do dual-write: todos os submit.php de formulários públicos
 * fazem POST pra https://www.ferreiraesa.com.br/conecta/publico/api_form.php
 * mas esse endpoint responde 301 (redirect pra sem www). cURL do submit
 * não segue redirect → dual-write falha silenciosamente.
 *
 * Fix: substitui 'www.ferreiraesa.com.br' por 'ferreiraesa.com.br' nos
 * arquivos de submit conhecidos.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');

$pubHtml = dirname(__DIR__);
$dry = !isset($_GET['executar']);
echo $dry ? ">>> DRY RUN (adicione &executar pra aplicar) <<<\n\n" : ">>> EXECUTANDO <<<\n\n";

$arquivos = array(
    $pubHtml . '/convivencia_form/submit.php',
    $pubHtml . '/cadastro_cliente/submit.php',
    $pubHtml . '/gastos_pensão/submit.php',
    $pubHtml . '/gastos_pensao/submit.php',
    $pubHtml . '/despesas-mensais/submit.php',
    $pubHtml . '/calculadora/submit.php',
    $pubHtml . '/curatela/submit.php',
);

foreach ($arquivos as $path) {
    echo "─── {$path} ───\n";
    if (!file_exists($path)) { echo "  (não existe)\n\n"; continue; }
    $orig = file_get_contents($path);
    $hasWww = (strpos($orig, 'https://www.ferreiraesa.com.br/conecta/publico/api_form.php') !== false);
    $hasApiForm = (strpos($orig, 'api_form.php') !== false);
    echo "  tem dual-write? " . ($hasApiForm ? 'SIM' : 'nao') . "\n";
    echo "  URL com www (quebrada)? " . ($hasWww ? 'SIM ← PRECISA FIX' : 'NÃO (ok)') . "\n";
    if (!$hasWww) { echo "\n"; continue; }

    $novo = str_replace(
        'https://www.ferreiraesa.com.br/conecta/publico/api_form.php',
        'https://ferreiraesa.com.br/conecta/publico/api_form.php',
        $orig
    );
    // Tb adiciona CURLOPT_FOLLOWLOCATION pra segurança futura
    if (strpos($novo, 'CURLOPT_FOLLOWLOCATION') === false) {
        $novo = str_replace(
            'CURLOPT_SSL_VERIFYPEER => false,',
            "CURLOPT_SSL_VERIFYPEER => false,\n    CURLOPT_FOLLOWLOCATION => true,",
            $novo
        );
    }
    echo "  diff: URL www → sem www + FOLLOWLOCATION\n";
    if (!$dry) {
        // Backup
        @copy($path, $path . '.bak_' . date('Ymd_His'));
        $ok = file_put_contents($path, $novo);
        echo "  gravado: " . ($ok ? "{$ok} bytes" : 'FALHA') . "\n";
    }
    echo "\n";
}
echo "Done.\n";
