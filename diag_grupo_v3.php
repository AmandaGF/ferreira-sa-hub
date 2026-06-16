<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

$grupoId = '120363382460329785';
$inst = zapi_get_instancia('24');
$cfg = zapi_get_config();
$baseUrl = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
$headers = array('Content-Type: application/json');
if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

function tentar($url, $body, $headers, $rotulo) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "── $rotulo\n";
    echo "  URL: $url\n";
    echo "  body: " . json_encode($body) . "\n";
    echo "  HTTP $http\n";
    $j = json_decode($resp, true);
    if (is_array($j)) {
        $id = $j['messageId'] ?? ($j['id'] ?? '?');
        $sint = strpos($id, '3EB0') === 0 ? '⚠️ sintético!' : '✓ parece real';
        echo "  messageId: $id ($sint)\n";
        if (!empty($j['error'])) echo "  ❌ erro: " . $j['error'] . "\n";
    } else {
        echo "  resp: " . substr($resp, 0, 200) . "\n";
    }
    echo "\n";
}

// 1. send-text com @g.us
tentar($baseUrl . '/send-text', array('phone' => $grupoId . '@g.us', 'message' => '[TESTE 1 ' . date('H:i:s') . '] formato @g.us'), $headers, 'TESTE 1: /send-text + @g.us');

// 2. send-text-group (endpoint especifico)
tentar($baseUrl . '/send-text-group', array('groupId' => $grupoId, 'message' => '[TESTE 2 ' . date('H:i:s') . '] endpoint /send-text-group com groupId'), $headers, 'TESTE 2: /send-text-group + groupId');

// 3. send-message-group (variante)
tentar($baseUrl . '/send-message-group', array('groupId' => $grupoId, 'message' => '[TESTE 3 ' . date('H:i:s') . '] endpoint /send-message-group'), $headers, 'TESTE 3: /send-message-group + groupId');

// 4. send-message com phone+isGroup
tentar($baseUrl . '/send-text', array('phone' => $grupoId, 'isGroup' => true, 'message' => '[TESTE 4 ' . date('H:i:s') . '] phone cru + isGroup:true'), $headers, 'TESTE 4: /send-text + isGroup:true');

// 5. send-text com 'group' em vez de 'phone'
tentar($baseUrl . '/send-text', array('group' => $grupoId, 'message' => '[TESTE 5 ' . date('H:i:s') . '] campo \'group\' em vez de \'phone\''), $headers, 'TESTE 5: /send-text + campo "group"');

// 6. Testa endpoint /send-message-text-group (outro nome possivel)
tentar($baseUrl . '/send-message-text-group', array('groupId' => $grupoId, 'message' => '[TESTE 6 ' . date('H:i:s') . '] /send-message-text-group'), $headers, 'TESTE 6: /send-message-text-group');

echo "Resultado: olhe no grupo Controladoria QUAL DOS 6 TESTES chegou.\n";
echo "Isso confirma qual formato/endpoint a Z-API espera pra LID groups novos.\n";
