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

// Retornar todos os dados disponíveis
$dados = $resultado['dados'];
echo json_encode(array(
    'status'       => 'OK',
    'cpf_valido'   => true,
    'nome'         => isset($dados['nome']) ? $dados['nome'] : null,
    'nascimento'   => isset($dados['nascimento']) ? $dados['nascimento'] : null,
    'rg'           => isset($dados['rg']) ? $dados['rg'] : null,
    'profissao'    => isset($dados['profissao']) ? $dados['profissao'] : null,
    'estado_civil' => isset($dados['estado_civil']) ? $dados['estado_civil'] : null,
    'genero'       => isset($dados['genero']) ? $dados['genero'] : null,
    'nacionalidade'=> isset($dados['nacionalidade']) ? $dados['nacionalidade'] : null,
    'email'        => isset($dados['email']) ? $dados['email'] : null,
    'telefone'     => isset($dados['telefone']) ? $dados['telefone'] : null,
    'telefone2'    => isset($dados['telefone2']) ? $dados['telefone2'] : null,
    'endereco'     => isset($dados['endereco']) ? $dados['endereco'] : null,
    'cidade'       => isset($dados['cidade']) ? $dados['cidade'] : null,
    'uf'           => isset($dados['uf']) ? $dados['uf'] : null,
    'cep'          => isset($dados['cep']) ? $dados['cep'] : null,
    'pix'          => isset($dados['pix']) ? $dados['pix'] : null,
    'filhos'       => isset($dados['filhos']) ? $dados['filhos'] : null,
    'source'       => $resultado['fonte'],
), JSON_UNESCAPED_UNICODE);
