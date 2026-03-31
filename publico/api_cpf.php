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

// CPF válido — retornar confirmação
// A ReceitaWS gratuita não está mais disponível para consulta de nome por CPF.
// Retornamos a validação do CPF para que o formulário saiba que é válido.
echo json_encode(array(
    'status' => 'OK',
    'cpf_valido' => true,
    'message' => 'CPF válido',
));
