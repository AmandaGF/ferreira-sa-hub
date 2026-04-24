<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

function zapi_try_endpoint($ddd, $phone, $pathTemplate) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst) return array('erro' => 'Instância não configurada');
    $cfg = zapi_get_config();
    $phoneNum = preg_replace('/\D/', '', $phone);
    if (strlen($phoneNum) === 10 || strlen($phoneNum) === 11) $phoneNum = '55' . $phoneNum;

    $path = str_replace('{phone}', urlencode($phoneNum), $pathTemplate);
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . $path;

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array('url' => $url, 'http_code' => $code, 'response' => json_decode($resp, true) ?: $resp);
}

$endpointsPossiveis = array(
    '/phone-exists/{phone}',
    '/phone-exists-batch?phones={phone}',
    '/get-iswhatsapp?phone={phone}',
    '/contacts/get-iswhatsapp?phone={phone}',
    '/is-whatsapp?phone={phone}',
);

function descobrir_endpoint($ddd, $phone) {
    global $endpointsPossiveis;
    foreach ($endpointsPossiveis as $tpl) {
        $r = zapi_try_endpoint($ddd, $phone, $tpl);
        $respStr = is_array($r['response']) ? json_encode($r['response']) : (string)$r['response'];
        $ehNotFound = (strpos($respStr, 'NOT_FOUND') !== false || $r['http_code'] === 404);
        if ($r['http_code'] === 200 && !$ehNotFound) {
            return array('tpl' => $tpl, 'resp' => $r);
        }
        echo "  [miss] {$tpl} → HTTP {$r['http_code']} — " . substr($respStr, 0, 120) . "\n";
    }
    return null;
}

echo "=== DESCOBRINDO endpoint correto ===\n";
$achou = descobrir_endpoint('24', '24998137649');
if (!$achou) { echo "\n[!] Nenhum endpoint funcionou. Verifique a doc oficial.\n"; exit; }

echo "\n[OK] Endpoint funcional: {$achou['tpl']}\n";
echo "Response amostra: " . json_encode($achou['resp']['response']) . "\n\n";

// Agora usa esse endpoint pros dois números
function consulta($ddd, $phone, $tpl) {
    $r = zapi_try_endpoint($ddd, $phone, $tpl);
    return $r;
}

echo "=== CONSULTAS ===\n\n";
echo "--- 1) Alícia CADASTRADA (24998137649) ---\n";
$r1 = consulta('24', '24998137649', $achou['tpl']);
print_r($r1);

echo "\n--- 2) Contato que conversou (554832031248) ---\n";
$r2 = consulta('24', '554832031248', $achou['tpl']);
print_r($r2);

echo "\n=== EXTRAINDO @LIDs ===\n";
function extrai_lid($r) {
    $resp = $r['response'];
    if (isset($resp[0]['lid'])) return $resp[0]['lid'];
    if (isset($resp['lid'])) return $resp['lid'];
    if (isset($resp[0]['@lid'])) return $resp[0]['@lid'];
    return null;
}
$l1 = extrai_lid($r1);
$l2 = extrai_lid($r2);
echo "Alícia 24998137649 → @lid: " . ($l1 ?: '(null)') . "\n";
echo "Contato 554832031248 → @lid: " . ($l2 ?: '(null)') . "\n";

echo "\n=== CONCLUSÃO ===\n";
if ($l1 && $l2) {
    if ($l1 === $l2) echo "[!] MESMO @lid — é a mesma pessoa. A Alícia USA o 554832031248 no WhatsApp (cadastro tem número desatualizado).\n";
    else echo "[OK] @lids DIFERENTES — são contatos distintos. O 554832031248 NÃO é a Alícia real.\n";
} else {
    echo "Não consegui extrair @lid dos 2 números. Verifique resposta acima.\n";
}
