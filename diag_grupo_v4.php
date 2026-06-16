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
    echo "── $rotulo (HTTP $http)\n";
    $j = json_decode($resp, true);
    if (is_array($j)) {
        $id = $j['messageId'] ?? ($j['id'] ?? '?');
        $sint = strpos($id, '3EB0') === 0 ? '⚠️ sintético' : '✓ não-sintético';
        echo "  body enviado: " . json_encode($body) . "\n";
        echo "  messageId: $id ($sint)\n";
        if (!empty($j['error'])) echo "  ❌ erro: " . $j['error'] . "\n";
    } else {
        echo "  resp: " . substr($resp, 0, 200) . "\n";
    }
    echo "\n";
}

// 1. Sufixo -group (formato visto no endpoint /groups)
tentar($baseUrl . '/send-text', array('phone' => $grupoId . '-group', 'message' => '[TESTE A ' . date('H:i:s') . '] phone com sufixo -group'), $headers, 'TESTE A: phone com sufixo -group');

// 2. Sufixo @g.us COM -group
tentar($baseUrl . '/send-text', array('phone' => $grupoId . '-group@g.us', 'message' => '[TESTE B ' . date('H:i:s') . '] phone com -group@g.us'), $headers, 'TESTE B: phone com -group@g.us');

// 3. Endpoint /send-text-group + phone (em vez de groupId)
tentar($baseUrl . '/send-text-group', array('phone' => $grupoId, 'message' => '[TESTE C ' . date('H:i:s') . '] /send-text-group + phone'), $headers, 'TESTE C: /send-text-group com phone');

// 4. /chats encontra o grupo? — antes tentei pelo nome, vou tentar pelo ID
echo "── BUSCA endpoint /chats COM PAGINACAO\n";
$pagina = 1;
$achou = false;
while ($pagina <= 5 && !$achou) {
    $url = $baseUrl . '/chats?page=' . $pagina . '&pageSize=50';
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data)) break;
    foreach ($data as $c) {
        $phone = $c['phone'] ?? '';
        if (strpos($phone, $grupoId) !== false || strpos($grupoId, str_replace('-group', '', $phone)) !== false) {
            echo "  ✓ ACHADO na pagina $pagina: phone='$phone' name='" . ($c['name'] ?? '?') . "' isGroup=" . (!empty($c['isGroup'])?'true':'false') . "\n";
            $achou = true;
            break;
        }
    }
    if (!$achou) echo "  pagina $pagina: " . count($data) . " chats, nenhum bate.\n";
    $pagina++;
}
if (!$achou) echo "  ⚠️ Grupo $grupoId NAO foi encontrado em ate 5 paginas (250 chats)\n";

echo "\n── /groups (endpoint especifico) canal 24\n";
$ch = curl_init($baseUrl . '/groups');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
));
$resp = curl_exec($ch);
curl_close($ch);
$data = json_decode($resp, true);
if (is_array($data)) {
    echo "  Total: " . count($data) . " grupos\n";
    foreach ($data as $g) {
        $p = $g['phone'] ?? '?';
        $n = $g['name'] ?? ($g['notify'] ?? '?');
        $marca = (strpos($p, $grupoId) !== false) ? ' ← ESTE!' : '';
        echo "    phone='$p' name='$n'$marca\n";
    }
} else {
    echo "  resp: " . substr($resp, 0, 500) . "\n";
}
