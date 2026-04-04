<?php
/**
 * Proxy para consulta de CPF
 * 1º tenta base interna (clients + case_partes)
 * 2º tenta API externa
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

// 1. Buscar na base interna
try {
    require_once __DIR__ . '/../core/config.php';
    require_once __DIR__ . '/../core/database.php';
    $pdo = db();

    $cpfFmt = substr($cpf,0,3).'.'.substr($cpf,3,3).'.'.substr($cpf,6,3).'-'.substr($cpf,9,2);

    // clients
    $stmt = $pdo->prepare("SELECT name, birth_date FROM clients WHERE REPLACE(REPLACE(cpf,'.',''),'-','') = ? LIMIT 1");
    $stmt->execute(array($cpf));
    $client = $stmt->fetch();
    if ($client && $client['name']) {
        echo json_encode(array('status' => 'OK', 'cpf_valido' => true, 'nome' => $client['name'], 'nascimento' => $client['birth_date'], 'source' => 'portal'));
        exit;
    }

    // case_partes
    $stmt2 = $pdo->prepare("SELECT nome FROM case_partes WHERE cpf = ? OR cpf = ? LIMIT 1");
    $stmt2->execute(array($cpf, $cpfFmt));
    $parte = $stmt2->fetch();
    if ($parte && $parte['nome']) {
        echo json_encode(array('status' => 'OK', 'cpf_valido' => true, 'nome' => $parte['nome'], 'source' => 'partes'));
        exit;
    }
} catch (Exception $e) {}

// 2. Tentar API externa — cpfcnpj.com.br
$token = '9320d4099cf4099528cce511241c48a0';
$ch = curl_init("https://api.cpfcnpj.com.br/$token/1/$cpf");
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'FES-Hub/1.0',
));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    if (isset($data['nome']) && $data['nome']) {
        echo json_encode(array(
            'status' => 'OK',
            'cpf_valido' => true,
            'nome' => $data['nome'],
            'nascimento' => isset($data['nascimento']) ? $data['nascimento'] : null,
            'source' => 'api_cpfcnpj',
        ));
        exit;
    }
}

// Fallback: CPF válido mas sem dados
echo json_encode(array(
    'status' => 'OK',
    'cpf_valido' => true,
    'message' => 'CPF válido, nome não encontrado',
));
