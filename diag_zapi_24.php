<?php
/**
 * Diagnóstico da instância Z-API DDD 24 (CX/Operacional).
 * Checa: config no DB, status real na Z-API, webhook configurado e atividade recente.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
ini_set('display_errors', '1');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();

function call_zapi($url, $clientToken) {
    $headers = array('Content-Type: application/json');
    if ($clientToken) $headers[] = 'Client-Token: ' . $clientToken;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('code' => $code, 'resp' => $resp, 'err' => $err);
}

echo "=== DIAGNÓSTICO Z-API DDD 24 ===\n\n";

$inst = zapi_get_instancia('24');
$cfg  = zapi_get_config();
echo "1. Instância no DB (zapi_instancias, ativo=1, ddd=24):\n";
if (!$inst) {
    echo "   ❌ NENHUMA instância ativa com ddd=24!\n";
    $all = $pdo->query("SELECT id, ddd, nome, ativo, conectado, ultima_verificacao, LENGTH(instancia_id) lid, LENGTH(token) ltk FROM zapi_instancias")->fetchAll();
    echo "   Todas as instâncias cadastradas:\n";
    foreach ($all as $a) {
        echo "     id={$a['id']} ddd={$a['ddd']} nome=\"{$a['nome']}\" ativo={$a['ativo']} conectado={$a['conectado']} instancia_id_len={$a['lid']} token_len={$a['ltk']} ult_verif={$a['ultima_verificacao']}\n";
    }
} else {
    echo "   id={$inst['id']} nome=\"{$inst['nome']}\"\n";
    echo "   instancia_id: " . ($inst['instancia_id'] ? substr($inst['instancia_id'],0,6).'…('.strlen($inst['instancia_id']).' chars)' : '❌ VAZIO') . "\n";
    echo "   token: " . ($inst['token'] ? substr($inst['token'],0,6).'…('.strlen($inst['token']).' chars)' : '❌ VAZIO') . "\n";
    echo "   conectado (DB): {$inst['conectado']}   ultima_verificacao: {$inst['ultima_verificacao']}\n";
}
echo "   client_token (global): " . ($cfg['client_token'] ? substr($cfg['client_token'],0,6).'…('.strlen($cfg['client_token']).' chars)' : '❌ VAZIO') . "\n";
echo "   base_url: {$cfg['base_url']}\n\n";

if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
    echo "Sem credenciais válidas — parando aqui.\n";
    exit;
}

$base = rtrim($cfg['base_url'],'/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];

echo "2. GET /status (Z-API):\n";
$r = call_zapi($base.'/status', $cfg['client_token']);
echo "   HTTP {$r['code']}" . ($r['err'] ? " curl_err={$r['err']}" : '') . "\n";
echo "   resp: {$r['resp']}\n\n";

echo "3. GET /device:\n";
$r = call_zapi($base.'/device', $cfg['client_token']);
echo "   HTTP {$r['code']}" . ($r['err'] ? " curl_err={$r['err']}" : '') . "\n";
echo "   resp: {$r['resp']}\n\n";

echo "4. Webhooks configurados na Z-API:\n";
foreach (array('webhooks','webhook-received','webhook-delivery') as $wh) {
    $r = call_zapi($base.'/'.$wh, $cfg['client_token']);
    echo "   GET /$wh → HTTP {$r['code']}: {$r['resp']}\n";
}
echo "\n";

echo "5. Atividade recente de mensagens canal 24:\n";
try {
    $row = $pdo->query("SELECT COUNT(*) tot, MAX(m.created_at) ult
        FROM zapi_mensagens m JOIN zapi_conversas c ON c.id = m.conversa_id
        WHERE c.canal = '24'")->fetch();
    echo "   total msgs canal 24: {$row['tot']}   última: {$row['ult']}\n";
    $rec = $pdo->query("SELECT direcao, COUNT(*) tot, MAX(m.created_at) ult
        FROM zapi_mensagens m JOIN zapi_conversas c ON c.id = m.conversa_id
        WHERE c.canal = '24' GROUP BY direcao")->fetchAll();
    foreach ($rec as $x) echo "   {$x['direcao']}: {$x['tot']}   última: {$x['ult']}\n";
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM zapi_mensagens")->fetchAll(PDO::FETCH_COLUMN);
        echo "   colunas zapi_mensagens: " . implode(', ', $cols) . "\n";
        $cols2 = $pdo->query("SHOW COLUMNS FROM zapi_conversas")->fetchAll(PDO::FETCH_COLUMN);
        echo "   colunas zapi_conversas: " . implode(', ', $cols2) . "\n";
    } catch (Exception $e2) {}
}

echo "\n=== FIM ===\n";
