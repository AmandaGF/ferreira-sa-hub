<?php
/**
 * Proxy para consulta de CPF na ReceitaWS
 * Evita bloqueio de CORS chamando do servidor
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cpf = isset($_GET['cpf']) ? preg_replace('/\D/', '', $_GET['cpf']) : '';
if (strlen($cpf) !== 11) {
    echo json_encode(array('status' => 'ERROR', 'message' => 'CPF inválido'));
    exit;
}

// Tentar ReceitaWS primeiro
$apis = array(
    "https://receitaws.com.br/v1/cpf/$cpf",
    "https://www.receitaws.com.br/v1/cpf/$cpf",
);

$response = null;
$httpCode = 0;

foreach ($apis as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $response && strpos($response, 'nome') !== false) {
        echo $response;
        exit;
    }
}

// Debug: retornar info do erro
echo json_encode(array(
    'status' => 'ERROR',
    'message' => 'API indisponível',
    'debug_http' => $httpCode,
    'debug_error' => isset($error) ? $error : '',
    'debug_response' => $response ? substr($response, 0, 200) : 'vazio',
));
