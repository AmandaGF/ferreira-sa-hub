<?php
/**
 * Diagnóstico de lentidão no canal 24.
 * Verifica:
 *  - Status atual da instância 24 (Z-API)
 *  - Últimas 20 mensagens enviadas — calcula tempo entre envio e ACK do webhook
 *  - Fila de envio pendente (zapi_fila_envio), se existir
 *  - Últimos erros de envio no log
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/diag_wa24_lentidao.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Forbidden.');
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAGNÓSTICO WhatsApp Canal 24 ===\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Status atual via Z-API
echo "--- 1. Status da instância 24 ---\n";
$inst = zapi_get_instancia('24');
if (!$inst) {
    echo "✗ Instância 24 não configurada no DB.\n";
    exit;
}
echo "Instance ID:  " . substr($inst['instancia_id'], 0, 12) . "...\n";
echo "Token:        " . substr($inst['token'], 0, 8) . "...\n";
echo "DB conectado: " . (!empty($inst['conectado']) ? 'sim' : 'não') . "\n";

$cfg = zapi_get_config();
$urlStatus = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/status';
$headers = array();
if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

$ch = curl_init($urlStatus);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
));
$t0 = microtime(true);
$resp = curl_exec($ch);
$t1 = microtime(true);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

$tempoMs = round(($t1 - $t0) * 1000);
echo "Z-API ping:   HTTP $code em $tempoMs ms" . ($err ? " · $err" : '') . "\n";
if ($resp) {
    $j = json_decode($resp, true);
    if (is_array($j)) {
        echo "  connected:  " . (!empty($j['connected']) ? 'true' : 'false') . "\n";
        echo "  session:    " . (!empty($j['session']) ? json_encode($j['session']) : '—') . "\n";
        echo "  smartphone: " . (!empty($j['smartphoneConnected']) ? 'true' : 'false') . "\n";
        if (isset($j['error']))   echo "  error:      " . $j['error'] . "\n";
        if (isset($j['message'])) echo "  message:    " . $j['message'] . "\n";
    }
}
echo "\n";

// 2. Últimas 20 mensagens enviadas — analisa timestamps de envio vs ACK
echo "--- 2. Latência das últimas mensagens enviadas (canal 24) ---\n";
try {
    $st = $pdo->prepare(
        "SELECT m.id, m.zapi_message_id, m.created_at, m.recebido_em, m.lido_em, m.status,
                LENGTH(m.conteudo) AS tam_msg
         FROM zapi_mensagens m
         JOIN zapi_conversas c ON c.id = m.conversa_id
         WHERE c.canal = '24'
           AND m.direcao = 'enviada'
           AND m.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
         ORDER BY m.id DESC LIMIT 20"
    );
    $st->execute();
    $msgs = $st->fetchAll();
    if (empty($msgs)) {
        echo "Nenhuma mensagem enviada nas últimas 6h.\n";
    } else {
        echo sprintf("%-5s %-8s %-19s %-19s %-19s %-8s\n", 'id', 'tam', 'enviou', 'recebeu_ack', 'lido', 'status');
        $maxLatencia = 0;
        $somaLatencia = 0;
        $comAck = 0;
        foreach ($msgs as $m) {
            $envio = $m['created_at'];
            $ack = $m['recebido_em'];
            $latStr = '—';
            if ($envio && $ack) {
                $diff = strtotime($ack) - strtotime($envio);
                $latStr = $diff . 's';
                $somaLatencia += $diff;
                $comAck++;
                if ($diff > $maxLatencia) $maxLatencia = $diff;
            }
            echo sprintf("%-5d %-8d %-19s %-19s %-19s %-8s · %s\n",
                $m['id'], $m['tam_msg'],
                substr($envio, 11, 8),
                $ack ? substr($ack, 11, 8) : 'sem ack',
                $m['lido_em'] ? substr($m['lido_em'], 11, 8) : '—',
                $m['status'],
                $latStr
            );
        }
        echo "\n";
        if ($comAck > 0) {
            $media = round($somaLatencia / $comAck, 1);
            echo "Média de latência (envio → ACK):  " . $media . "s · com $comAck/" . count($msgs) . " mensagens com ACK\n";
            echo "Pior latência:                     " . $maxLatencia . "s\n";
            $semAck = count($msgs) - $comAck;
            if ($semAck > 0) echo "ATENÇÃO: $semAck mensagem(ns) sem ACK ainda — sintoma de webhook falhando ou Z-API atrasando\n";
            if ($maxLatencia > 60) echo "⚠ Latência > 60s detectada — Z-API ou WhatsApp atrasando entrega\n";
        } else {
            echo "ATENÇÃO: Nenhuma das mensagens recentes recebeu ACK do webhook.\n";
            echo "Provável causa: webhook desconfigurado OU Z-API com problema.\n";
        }
    }
} catch (Exception $e) {
    echo "Erro consultando mensagens: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Fila pendente (zapi_fila_envio) se existir
echo "--- 3. Fila de envio pendente (zapi_fila_envio) ---\n";
try {
    $pendentes = $pdo->query("SELECT COUNT(*) FROM zapi_fila_envio WHERE status = 'pendente'")->fetchColumn();
    $enviadas = $pdo->query("SELECT COUNT(*) FROM zapi_fila_envio WHERE status = 'enviada'
                             AND enviada_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    echo "Pendentes:           $pendentes\n";
    echo "Enviadas em 24h:     $enviadas\n";
    if ($pendentes > 50) echo "⚠ Muitas pendentes — cron de envio pode estar lento\n";
} catch (Exception $e) {
    echo "Tabela não existe ou erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Recomendações
echo "--- 4. Recomendações ---\n";
echo "Se a média de latência > 30s OU muitas mensagens sem ACK:\n";
echo "  → Acessar painel Z-API (https://app.z-api.io)\n";
echo "  → Encontrar instância do DDD 24\n";
echo "  → Clicar em 'Reiniciar' (geralmente resolve sessão zumbi)\n";
echo "  → Aguardar 30s, fazer login (QR code se necessário)\n";
echo "\n";
echo "Se o problema persistir:\n";
echo "  → Verificar antispam do WhatsApp (números muito novos ou padrões suspeitos)\n";
echo "  → Conferir se a colaboradora está usando muitos templates idênticos seguidos\n";
echo "  → Z-API pode estar com degradação de serviço — checar https://status.z-api.io\n";
