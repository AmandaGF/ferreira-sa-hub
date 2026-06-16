<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$grupoId = '120363382460329785';

echo "=== Atividade DESSE grupo no Hub (Controladoria) ===\n";
$st = $pdo->prepare("SELECT co.id AS conv_id, co.canal, co.telefone, co.ultima_msg_em
                     FROM zapi_conversas co
                     JOIN zapi_instancias i ON i.id = co.instancia_id
                     WHERE co.telefone IN (?, ?)");
$st->execute(array($grupoId, $grupoId . '@g.us'));
$convs = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($convs as $c) {
    echo "  Conv #{$c['conv_id']} | canal={$c['canal']} | ultima={$c['ultima_msg_em']}\n";

    // Mensagens RECEBIDAS desse grupo (se 24 esta no grupo, deveria ter)
    $stm = $pdo->prepare("SELECT direcao, COUNT(*) n, MAX(created_at) ultima
                          FROM zapi_mensagens
                          WHERE conversa_id = ?
                          GROUP BY direcao");
    $stm->execute(array($c['conv_id']));
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "    direcao={$r['direcao']} | total={$r['n']} | ultima={$r['ultima']}\n";
    }

    // Ultimas 5 enviadas — ver status
    $stm = $pdo->prepare("SELECT id, zapi_message_id, status, lida, created_at, conteudo
                          FROM zapi_mensagens
                          WHERE conversa_id = ? AND direcao='enviada'
                          ORDER BY created_at DESC LIMIT 5");
    $stm->execute(array($c['conv_id']));
    echo "    ─ Ultimas 5 enviadas:\n";
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $m) {
        echo "      msg #{$m['id']} | {$m['created_at']} | zapi_id={$m['zapi_message_id']} (" . strlen($m['zapi_message_id']) . "ch) | status='{$m['status']}' | lida={$m['lida']}\n";
    }
}

echo "\n=== Testar endpoint /chats da Z-API canal 24 (filtra Controladoria) ===\n";
require_once __DIR__ . '/core/functions_zapi.php';
$inst = zapi_get_instancia('24');
$cfg = zapi_get_config();
if ($inst && $inst['instancia_id'] && $inst['token']) {
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/chats';
    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP $http\n";
    $data = json_decode($resp, true);
    if (is_array($data)) {
        echo "Total chats: " . count($data) . "\n";
        // Filtra grupos
        $achou = false;
        foreach ($data as $chat) {
            $phone = $chat['phone'] ?? '';
            $nome = $chat['name'] ?? '';
            $isGroup = $chat['isGroup'] ?? false;
            if ($isGroup && (stripos($nome, 'controladoria') !== false || strpos($phone, $grupoId) !== false)) {
                echo "  ✓ ACHEI: phone='$phone' name='$nome' isGroup=" . ($isGroup?'true':'false') . "\n";
                $achou = true;
            }
        }
        if (!$achou) {
            echo "  ⚠️ Grupo 'Controladoria' NAO esta na lista de chats do canal 24!\n";
            echo "  Mostrando primeiros 5 grupos:\n";
            $c = 0;
            foreach ($data as $chat) {
                if (!empty($chat['isGroup']) && $c < 5) {
                    echo "    phone='{$chat['phone']}' name='{$chat['name']}'\n";
                    $c++;
                }
            }
        }
    } else {
        echo "Resposta nao-JSON (primeiros 800 chars):\n" . substr($resp, 0, 800) . "\n";
    }
} else {
    echo "Sem credenciais do canal 24\n";
}

echo "\n=== Tentar enviar pelo endpoint /send-message-group da Z-API ===\n";
// Z-API tem endpoint especifico pra grupo: /send-text-group ou phone com sufixo
$inst24 = zapi_get_instancia('24');
$url = rtrim($cfg['base_url'], '/') . '/' . $inst24['instancia_id'] . '/token/' . $inst24['token'] . '/send-text';
$headers = array('Content-Type: application/json');
if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

// Tenta com formato EXPLICITO @g.us
$body = array('phone' => $grupoId . '@g.us', 'message' => '🧪 teste explicito @g.us ' . date('H:i:s'));
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
));
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "phone='" . $body['phone'] . "' → HTTP $httpCode\n";
echo "  response: " . substr($resp, 0, 500) . "\n";

// Tenta sem @g.us (formato puro)
$body2 = array('phone' => $grupoId, 'message' => '🧪 teste sem @g.us ' . date('H:i:s'));
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body2),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
));
$resp2 = curl_exec($ch);
$httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "phone='" . $body2['phone'] . "' → HTTP $httpCode2\n";
echo "  response: " . substr($resp2, 0, 500) . "\n";
