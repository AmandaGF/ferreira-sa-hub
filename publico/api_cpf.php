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

// Validar CPF (algoritmo)
function validaCPF($cpf) {
    if (preg_match('/(\d)\1{10}/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) $d += $cpf[$c] * (($t + 1) - $c);
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}

if (!validaCPF($cpf)) {
    echo json_encode(array('status' => 'ERROR', 'message' => 'CPF inválido'));
    exit;
}

// Consultar Hub do Desenvolvedor
$token = '995e0ed2fbfc176e867c0d3a888661fd25f5413d8e95773fd15dab37e8078b18';

$ch = curl_init("https://ws.hubdodesenvolvedor.com.br/v2/cpf/?cpf=$cpf&token=$token");
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'FES-Hub/1.0',
));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);

    if (isset($data['result']) && isset($data['result']['nome_da_pf'])) {
        // Sucesso — retornar no formato que o JS espera
        echo json_encode(array(
            'status' => 'OK',
            'cpf_valido' => true,
            'nome' => $data['result']['nome_da_pf'],
            'nascimento' => isset($data['result']['data_nascimento']) ? $data['result']['data_nascimento'] : null,
        ));
        exit;
    }

    if (isset($data['status']) && $data['status'] === true && isset($data['return'])) {
        echo json_encode(array(
            'status' => 'OK',
            'cpf_valido' => true,
            'nome' => isset($data['return']['nome']) ? $data['return']['nome'] : null,
            'nascimento' => isset($data['return']['nascimento']) ? $data['return']['nascimento'] : null,
        ));
        exit;
    }
}

// Debug + fallback
echo json_encode(array(
    'status' => 'OK',
    'cpf_valido' => true,
    'message' => 'CPF válido (dados indisponíveis)',
    'debug_http' => $httpCode,
    'debug_raw' => $response ? substr($response, 0, 500) : 'sem resposta',
));
