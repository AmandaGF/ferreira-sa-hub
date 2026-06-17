<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

// Confere se o arquivo layout_start.php tem mesmo o banner
$arq = __DIR__ . '/templates/layout_start.php';
echo "Arquivo: $arq\n";
echo "Tamanho: " . filesize($arq) . " bytes\n";
echo "Mtime: " . date('Y-m-d H:i:s', filemtime($arq)) . "\n\n";

$cnt = file_get_contents($arq);
echo "Tem string 'prazoBanner': " . (strpos($cnt, 'prazoBanner') !== false ? 'SIM' : 'NAO') . "\n";
echo "Tem string '🚨 N prazo': " . (strpos($cnt, 'prazos VENCIDOS') !== false ? 'SIM' : 'NAO') . "\n";
echo "Tem comentário <!-- prazoBanner: : " . (strpos($cnt, 'prazoBanner: venc') !== false ? 'SIM' : 'NAO') . "\n";

// Procura por aspecto importante
$pos = strpos($cnt, '$_prazoBannerData');
echo "Posicao de \$_prazoBannerData: " . ($pos === false ? 'NAO ENCONTRADO' : $pos) . "\n";

echo "\n=== Header da pagina de Dashboard como CHAMADO COM SESSAO ===\n";
echo "(Vou tentar carregar o dashboard usando sua sessao, depois grep no output)\n\n";

// Vamos tentar puxar a pagina autenticada — vai falhar mas conta o erro
$ch = curl_init('https://ferreiraesa.com.br/conecta/modules/dashboard/index.php');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HEADER => true,
    CURLOPT_SSL_VERIFYPEER => false,
));
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP $http (302 = redirect pra login, esperado)\n";
echo "Primeiras 500 linhas da resposta:\n" . substr($resp, 0, 800) . "\n";
