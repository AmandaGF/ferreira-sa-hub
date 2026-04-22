<?php
/**
 * One-shot: baixa deploy2.php atualizado do GitHub e sobrescreve o do servidor.
 * Necessário porque o deploy2.php se auto-preserva (não vem do GitHub em deploys normais).
 *
 * URL: /conecta/atualizar_deploy2.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');

$url = 'https://raw.githubusercontent.com/AmandaGF/ferreira-sa-hub/main/deploy2.php';
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERAGENT => 'FES-Deploy-Upgrader',
));
$novo = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$novo) { echo "ERRO: HTTP $code\n"; exit(1); }
if (strpos($novo, '<?php') !== 0) { echo "ERRO: conteudo nao parece PHP valido\n"; exit(1); }

$alvo = __DIR__ . '/deploy2.php';
$atual = file_exists($alvo) ? file_get_contents($alvo) : '';

echo "Tamanho atual: " . strlen($atual) . " bytes\n";
echo "Tamanho novo:  " . strlen($novo) . " bytes\n";

if ($atual === $novo) { echo "Já está atualizado. Nada a fazer.\n"; exit; }

// Backup antes de sobrescrever
file_put_contents(__DIR__ . '/deploy2.backup.' . date('YmdHis') . '.php', $atual);
file_put_contents($alvo, $novo);
echo "✅ deploy2.php atualizado.\n";
echo "Backup salvo como: deploy2.backup." . date('YmdHis') . ".php\n";
