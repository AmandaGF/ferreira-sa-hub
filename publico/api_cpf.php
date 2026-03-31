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

// Consultar cpfcnpj.com.br
$token = '9320d4099cf4099528cce511241c48a0';

$ch = curl_init("https://api.cpfcnpj.com.br/$token/1/$cpf");
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

    if (isset($data['status']) && $data['status'] == 1 && isset($data['nome'])) {
        echo json_encode(array(
            'status' => 'OK',
            'cpf_valido' => true,
            'nome' => $data['nome'],
            'nascimento' => isset($data['nascimento']) ? $data['nascimento'] : null,
        ));
        exit;
    }
}

// Fallback
echo json_encode(array(
    'status' => 'OK',
    'cpf_valido' => true,
    'message' => 'CPF válido',
));
