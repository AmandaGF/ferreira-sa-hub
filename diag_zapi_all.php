<?php
/**
 * Diagnóstico completo Z-API — checa AMBAS as instâncias (DDD 21 Comercial e
 * DDD 24 CX/Operacional): config no DB, status real na Z-API, device, atividade
 * recente de mensagens e cauda do log de webhook.
 *
 *   curl -s "https://ferreiraesa.com.br/conecta/diag_zapi_all.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(90);
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

$cfg = zapi_get_config();
echo "=== DIAGNÓSTICO Z-API (canais 21 e 24) ===\n";
echo "base_url: {$cfg['base_url']}   client_token: " . ($cfg['client_token'] ? 'OK('.strlen($cfg['client_token']).')' : '❌ VAZIO') . "\n\n";

foreach (array('21' => 'Comercial (+bot IA)', '24' => 'CX/Operacional') as $ddd => $rotulo) {
    echo "════════════════════════════════════════\n";
    echo "CANAL {$ddd} — {$rotulo}\n";
    echo "════════════════════════════════════════\n";

    $inst = zapi_get_instancia($ddd);
    if (!$inst) {
        echo "❌ NENHUMA instância ativa com ddd={$ddd}!\n\n";
        continue;
    }
    echo "DB: id={$inst['id']} nome=\"{$inst['nome']}\" conectado(DB)={$inst['conectado']} ult_verif={$inst['ultima_verificacao']}\n";
    echo "    instancia_id=" . ($inst['instancia_id'] ? substr($inst['instancia_id'],0,6).'…('.strlen($inst['instancia_id']).')' : '❌ VAZIO')
       . "  token=" . ($inst['token'] ? substr($inst['token'],0,6).'…('.strlen($inst['token']).')' : '❌ VAZIO') . "\n";

    if (!$inst['instancia_id'] || !$inst['token']) { echo "Sem credenciais — pulando checagem online.\n\n"; continue; }

    $base = rtrim($cfg['base_url'],'/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];

    $r = call_zapi($base.'/status', $cfg['client_token']);
    echo "STATUS: HTTP {$r['code']}" . ($r['err'] ? " curl_err={$r['err']}" : '') . " → {$r['resp']}\n";

    $r = call_zapi($base.'/device', $cfg['client_token']);
    $dev = json_decode($r['resp'], true);
    if (is_array($dev) && !empty($dev['phone'])) {
        echo "DEVICE: phone={$dev['phone']} name=\"" . ($dev['name'] ?? '') . "\" business=" . (($dev['isBusiness'] ?? false) ? 'sim' : 'nao') . "\n";
    } else {
        echo "DEVICE: HTTP {$r['code']} → {$r['resp']}\n";
    }

    // Atividade recente
    try {
        $row = $pdo->query("SELECT COUNT(*) tot, MAX(m.created_at) ult
            FROM zapi_mensagens m JOIN zapi_conversas c ON c.id = m.conversa_id
            WHERE c.canal = '{$ddd}'")->fetch();
        echo "MSGS: total={$row['tot']}  última={$row['ult']}\n";
        $rec = $pdo->query("SELECT direcao, COUNT(*) tot, MAX(m.created_at) ult
            FROM zapi_mensagens m JOIN zapi_conversas c ON c.id = m.conversa_id
            WHERE c.canal = '{$ddd}' GROUP BY direcao")->fetchAll();
        foreach ($rec as $x) echo "      {$x['direcao']}: {$x['tot']}  última={$x['ult']}\n";
        // Recebidas nas últimas 24h (webhook realmente entregando?)
        $r24 = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens m JOIN zapi_conversas c ON c.id=m.conversa_id
            WHERE c.canal='{$ddd}' AND m.direcao='recebida' AND m.created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
        echo "      recebidas nas últimas 24h: {$r24}\n";
    } catch (Exception $e) {
        echo "MSGS: ERRO — " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "════════════════════════════════════════\n";
echo "CAUDA DO LOG (files/zapi_webhook.log — últimas 40 linhas):\n";
echo "════════════════════════════════════════\n";
$logFile = APP_ROOT . '/files/zapi_webhook.log';
if (is_file($logFile)) {
    $linhas = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($linhas) echo implode("\n", array_slice($linhas, -40)) . "\n";
    else echo "(log vazio)\n";
} else {
    echo "(arquivo não existe: {$logFile})\n";
}

echo "\n=== FIM ===\n";
