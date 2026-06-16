<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== TODOS os grupos do canal 21 (eh_grupo=1) ===\n";
$st = $pdo->query("SELECT co.id, co.telefone, co.nome_contato, co.eh_grupo, co.ultima_msg_em
                   FROM zapi_conversas co
                   JOIN zapi_instancias i ON i.id = co.instancia_id
                   WHERE i.ddd = '21' AND COALESCE(co.eh_grupo, 0) = 1
                   ORDER BY co.ultima_msg_em DESC");
foreach ($st as $g) {
    echo "  conv #{$g['id']} | tel='{$g['telefone']}' | nome='{$g['nome_contato']}' | ultima={$g['ultima_msg_em']}\n";
}

echo "\n=== Grupos do canal 24 (pra comparar formato) ===\n";
$st = $pdo->query("SELECT co.telefone, co.nome_contato
                   FROM zapi_conversas co
                   JOIN zapi_instancias i ON i.id = co.instancia_id
                   WHERE i.ddd = '24' AND COALESCE(co.eh_grupo, 0) = 1
                   ORDER BY co.ultima_msg_em DESC LIMIT 5");
foreach ($st as $g) {
    echo "  tel='{$g['telefone']}' | nome='{$g['nome_contato']}'\n";
}

echo "\n=== Mensagens enviadas pelo Hub pra esse grupo (qualquer status) ===\n";
$grupoTel = '120363382460329785';
$st = $pdo->prepare("SELECT m.id, m.zapi_message_id, m.status, m.lida, m.created_at, m.conteudo
                     FROM zapi_mensagens m
                     JOIN zapi_conversas co ON co.id = m.conversa_id
                     WHERE (co.telefone = ? OR co.telefone = CONCAT(?, '@g.us'))
                       AND m.direcao = 'enviada'
                     ORDER BY m.created_at DESC LIMIT 10");
$st->execute(array($grupoTel, $grupoTel));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
    echo "  msg #{$m['id']} | {$m['created_at']} | zapi_id={$m['zapi_message_id']} | status={$m['status']} | conteudo='" . substr($m['conteudo'], 0, 60) . "...'\n";
}

echo "\n=== Listar grupos via Z-API direto (endpoint /groups) ===\n";
require_once __DIR__ . '/core/functions_zapi.php';
$inst = zapi_get_instancia('21');
if ($inst && $inst['instancia_id'] && $inst['token']) {
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/groups';
    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP $http\n";
    $data = json_decode($resp, true);
    if (is_array($data)) {
        echo "Total: " . count($data) . " grupos\n\n";
        foreach (array_slice($data, 0, 20) as $g) {
            $id = $g['phone'] ?? ($g['id'] ?? '?');
            $nome = $g['name'] ?? ($g['notify'] ?? '?');
            echo "  '$id' = '$nome'\n";
        }
    } else {
        echo "Resposta bruta (primeiros 1500 chars):\n" . substr($resp, 0, 1500) . "\n";
    }
} else {
    echo "Instancia 21 nao tem credenciais.\n";
}
