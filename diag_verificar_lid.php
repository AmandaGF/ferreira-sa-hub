<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

/**
 * Consulta endpoint Z-API /get-iswhatsapp para descobrir o @lid real
 * de um número. Útil pra cruzar com webhooks e validar associações.
 */
function zapi_check_iswhatsapp($ddd, $phone) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst) return array('erro' => 'Instância DDD ' . $ddd . ' não configurada');
    $cfg = zapi_get_config();
    $phoneNum = preg_replace('/\D/', '', $phone);
    if (strlen($phoneNum) === 10 || strlen($phoneNum) === 11) $phoneNum = '55' . $phoneNum;

    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/get-iswhatsapp?phone=' . urlencode($phoneNum);
    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array(
        'phone_enviado' => $phoneNum,
        'http_code'     => $code,
        'response'      => json_decode($resp, true) ?: $resp,
    );
}

echo "=== Verificação Z-API /get-iswhatsapp ===\n\n";

// 1) Alícia cadastrada no Hub
echo "--- 1) Telefone CADASTRADO da Alícia (24998137649) ---\n";
$r1 = zapi_check_iswhatsapp('24', '24998137649');
print_r($r1);
echo "\n";

// 2) Número fantasma que recebeu as msgs (554832031248)
echo "--- 2) Telefone do @lid 99037785145538 (554832031248) ---\n";
$r2 = zapi_check_iswhatsapp('24', '554832031248');
print_r($r2);
echo "\n";

// 3) Extrai o @lid de cada
function extrai_lid($resp) {
    if (isset($resp['response'][0]['lid'])) return $resp['response'][0]['lid'];
    if (isset($resp['response']['lid'])) return $resp['response']['lid'];
    return '(sem @lid)';
}

$lidAlicia = extrai_lid($r1);
$lidFantasma = extrai_lid($r2);

echo "=== CONCLUSÃO ===\n";
echo "Alícia cadastrada (24998137649) → @lid: {$lidAlicia}\n";
echo "Contato que conversava (554832031248) → @lid: {$lidFantasma}\n";

if ($lidAlicia !== '(sem @lid)' && $lidFantasma !== '(sem @lid)') {
    if ($lidAlicia === $lidFantasma) {
        echo "\n[!] @lids IGUAIS — é a mesma pessoa. Alícia USA o 554832031248 (cadastro Hub está com número errado).\n";
    } else {
        echo "\n[OK] @lids DIFERENTES — são contatos diferentes. O 554832031248 NÃO é a Alícia.\n";
        echo "     Provavelmente: outra pessoa conversou e foi vinculada por engano ao cadastro dela.\n";
    }
}
