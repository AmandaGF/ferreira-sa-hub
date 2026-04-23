<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

$pdo = db();
echo "=== DIAG Z-API RAPIDO — " . date('Y-m-d H:i:s') . " ===\n\n";

// 1) Últimas mensagens recebidas nos dois canais
echo "--- 1) Ultimas mensagens recebidas (webhook) ---\n";
foreach (array('21','24') as $canal) {
    $r = $pdo->prepare("SELECT m.id, m.created_at, m.tipo, co.nome_contato, co.telefone
        FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
        WHERE co.canal = ? AND m.direcao = 'recebida'
        ORDER BY m.id DESC LIMIT 3");
    $r->execute(array($canal));
    echo "\n  Canal {$canal}:\n";
    $linhas = $r->fetchAll();
    if (!$linhas) { echo "    (nenhuma recebida)\n"; continue; }
    foreach ($linhas as $l) {
        $min = (time() - strtotime($l['created_at'])) / 60;
        echo sprintf("    #%d %s (há %d min) [%s] %s - %s\n",
            $l['id'], $l['created_at'], $min, $l['tipo'],
            $l['nome_contato'] ?: '?', $l['telefone']);
    }
}

// 2) Log do webhook: últimas 10 linhas
echo "\n--- 2) Log webhook (ultimas entradas) ---\n";
$log = __DIR__ . '/files/zapi_webhook.log';
if (file_exists($log)) {
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    $tail = array_slice($lines, -10);
    foreach ($tail as $l) echo "  " . mb_substr($l, 0, 180, 'UTF-8') . "\n";
    $mtime = filemtime($log);
    $idle = time() - $mtime;
    echo "\n  Ultima escrita no log: " . date('Y-m-d H:i:s', $mtime) . " (ha " . floor($idle/60) . "min " . ($idle%60) . "s)\n";
} else {
    echo "  (log nao existe)\n";
}

// 3) Status das instancias Z-API
echo "\n--- 3) Instancias Z-API ---\n";
$insts = $pdo->query("SELECT ddd, instancia_id, LEFT(token, 8) AS token_prefix, ativo FROM zapi_instancias ORDER BY ddd")->fetchAll();
foreach ($insts as $i) {
    echo "  DDD {$i['ddd']}: instancia={$i['instancia_id']} token={$i['token_prefix']}... ativo={$i['ativo']}\n";

    // Check status online via Z-API
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $i['instancia_id'] . '/token/' .
        $pdo->query("SELECT token FROM zapi_instancias WHERE ddd = '{$i['ddd']}'")->fetchColumn() . '/status';
    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "    STATUS: HTTP {$code} — " . mb_substr((string)$resp, 0, 200, 'UTF-8') . "\n";
}

// 4) Última vez que o Hub enviou mensagem (conexao reversa está funcionando?)
echo "\n--- 4) Ultimas mensagens ENVIADAS pelo Hub ---\n";
foreach (array('21','24') as $canal) {
    $r = $pdo->prepare("SELECT m.id, m.created_at, m.status, co.nome_contato
        FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
        WHERE co.canal = ? AND m.direcao = 'enviada' AND m.enviado_por_id IS NOT NULL
        ORDER BY m.id DESC LIMIT 2");
    $r->execute(array($canal));
    echo "\n  Canal {$canal}:\n";
    $linhas = $r->fetchAll();
    if (!$linhas) { echo "    (nenhuma)\n"; continue; }
    foreach ($linhas as $l) {
        $min = (time() - strtotime($l['created_at'])) / 60;
        echo sprintf("    #%d %s (há %d min) status=%s - %s\n",
            $l['id'], $l['created_at'], $min, $l['status'], $l['nome_contato'] ?: '?');
    }
}
