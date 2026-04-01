<?php
/**
 * Atualiza a calculadora para enviar ao Conecta além do Firebase.
 * Injeta um snippet que faz fetch ao api_form.php.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$dryRun = !isset($_GET['executar']);
echo "=== FIX CALCULADORA → CONECTA ===\n";
echo $dryRun ? ">>> SIMULAÇÃO <<<\n\n" : ">>> EXECUTANDO <<<\n\n";

$publicHtml = dirname(__DIR__);
$calcFile = $publicHtml . '/calculadora/index.html';

if (!file_exists($calcFile)) {
    die("ERRO: $calcFile não encontrado\n");
}

$calc = file_get_contents($calcFile);
echo "Arquivo: " . strlen($calc) . " bytes\n\n";

if (strpos($calc, 'api_form.php') !== false) {
    die("JÁ ATUALIZADO (contém api_form.php)\n");
}

// Encontrar a posição EXATA do submit handler
$submitPos = strpos($calc, "addEventListener('submit'");
if ($submitPos === false) {
    $submitPos = strpos($calc, 'addEventListener("submit"');
}
if ($submitPos === false) {
    die("ERRO: addEventListener submit não encontrado\n");
}
echo "Submit handler encontrado na posição: $submitPos\n";

// Encontrar o primeiro db.collection DEPOIS do submit handler
$collPos = strpos($calc, 'leads_calculadora', $submitPos);
if ($collPos === false) {
    die("ERRO: leads_calculadora não encontrado após submit handler\n");
}
echo "leads_calculadora encontrado na posição: $collPos\n";

// Voltar para encontrar o início da linha com db.collection
$lineStart = strrpos(substr($calc, 0, $collPos), "\n") + 1;
echo "Início da linha: $lineStart\n";

// Mostrar a linha e as próximas 15 linhas
$lines = explode("\n", substr($calc, $lineStart, 1000));
echo "\nCódigo encontrado:\n";
for ($i = 0; $i < min(20, count($lines)); $i++) {
    echo "  >" . $lines[$i] . "\n";
}

// Estratégia: injetar um bloco de código ANTES do db.collection que faz fetch ao Conecta
// O Firebase continua funcionando como backup

$injectPoint = $lineStart;

$newCode = '
        // ── Dual-write: enviar para o Conecta ──
        var conectaData = {
            form_type: "calculadora_lead",
            client_name: dados.nome,
            client_phone: dados.whatsapp,
            nome: dados.nome,
            whatsapp: dados.whatsapp,
            idade_filhos: dados.idade_filhos,
            situacao: dados.situacao,
            porcentagem: dados.porcentagem,
            ano_referencia: dados.ano_referencia,
            data_envio: dados.data_envio.toISOString()
        };
        fetch("https://www.ferreiraesa.com.br/conecta/publico/api_form.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(conectaData)
        }).catch(function(e){ console.log("Conecta:", e); });

';

echo "\nInjetar " . strlen($newCode) . " bytes na posição $injectPoint\n";

if ($dryRun) {
    echo "\n[SIMULAÇÃO] Adicionaria dual-write antes do db.collection\n";
    echo ">>> Para executar: adicione &executar <<<\n";
} else {
    $newCalc = substr($calc, 0, $injectPoint) . $newCode . substr($calc, $injectPoint);
    file_put_contents($calcFile, $newCalc);
    echo "\n[OK] Dual-write adicionado à calculadora!\n";
    echo "Novo tamanho: " . strlen($newCalc) . " bytes\n";
}

echo "\n=== FIM ===\n";
