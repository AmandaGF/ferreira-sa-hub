<?php
require_once __DIR__ . '/core/config.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

echo "=== Diagnostico Apps Script Google ===\n\n";
echo "URL configurada: " . (defined('GOOGLE_APPS_SCRIPT_URL') ? GOOGLE_APPS_SCRIPT_URL : '(nao definida)') . "\n\n";

if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) exit;

// Teste POST simulando getOrCreateSubfolder
$payload = json_encode(array(
    'action' => 'getOrCreateSubfolder',
    'parentFolderId' => 'TESTE_INVALIDO_123',
    'subfolderName' => 'ping-diagnostico',
));
$ch = curl_init(GOOGLE_APPS_SCRIPT_URL);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
));
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);
echo "HTTP: $http\n";
echo "URL final: $finalUrl\n";
if ($err) echo "Erro cURL: $err\n";
echo "\nResposta (primeiros 1000 chars):\n" . substr((string)$resp, 0, 1000) . "\n\n";

if ($http === 404) echo "=> URL do Apps Script esta 404. Precisa gerar deploy novo e atualizar config.php.\n";
elseif ($http === 200) echo "=> URL OK. Erro no case especifico (pasta pai deletada ou sem permissao).\n";
else echo "=> HTTP $http inesperado.\n";
