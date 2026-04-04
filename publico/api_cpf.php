<?php
/**
 * Proxy para consulta de CPF — usa helper centralizado
 * Mantido para compatibilidade com formulários públicos
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_cpfcnpj.php';

$cpf = isset($_GET['cpf']) ? preg_replace('/\D/', '', $_GET['cpf']) : '';
if (strlen($cpf) !== 11) {
    echo json_encode(array('status' => 'ERROR', 'message' => 'CPF inválido'));
    exit;
}

$resultado = buscar_cpf($cpf);

if (isset($resultado['erro'])) {
    echo json_encode(array('status' => 'ERROR', 'message' => $resultado['erro']));
    exit;
}

// Formato compatível com o antigo
echo json_encode(array(
    'status' => 'OK',
    'cpf_valido' => true,
    'nome' => $resultado['dados']['nome'] ?? null,
    'nascimento' => $resultado['dados']['nascimento'] ?? null,
    'source' => $resultado['fonte'],
));
