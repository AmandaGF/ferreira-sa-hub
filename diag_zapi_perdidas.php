<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
set_time_limit(120);

$pdo = db();
$ddd = $_GET['ddd'] ?? '24';
echo "=== Mensagens recebidas no Z-API vs Hub (DDD {$ddd}) ===\n\n";

// Busca últimos chats (conversas) do Z-API — isso geralmente retorna quem interagiu nas últimas horas
$inst = zapi_get_instancia($ddd);
$cfg = zapi_get_config();
$base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
$headers = array(); if ($cfg['client_token']) $headers[] = 'Client-Token: '.$cfg['client_token'];

echo "--- GET /chats (últimas conversas ativas) ---\n";
$ch = curl_init($base . '/chats?page=1&pageSize=30');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 20,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP {$code}\n";
$chats = json_decode($resp, true);
if (!is_array($chats)) { echo "Resposta: " . substr($resp, 0, 300) . "\n"; exit; }

// Pega os 10 primeiros chats e verifica mensagens do dia de hoje
$hoje = date('Y-m-d');
echo "\n--- Buscando mensagens de hoje em cada chat ---\n";
$faltando = 0; $conferidos = 0;

foreach (array_slice($chats, 0, 15) as $chat) {
    $tel = $chat['phone'] ?? '';
    if (!$tel || strpos($tel, '-') !== false || strpos($tel, '@g.us') !== false) continue; // pula grupo
    $conferidos++;
    $nome = $chat['name'] ?? '(sem nome)';

    // Mensagens desse chat via Z-API
    $chm = curl_init($base . '/chat-messages/' . $tel . '?size=20');
    curl_setopt_array($chm, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
    ));
    $mresp = curl_exec($chm); $mcode = curl_getinfo($chm, CURLINFO_HTTP_CODE); curl_close($chm);
    $msgs = json_decode($mresp, true);
    if (!is_array($msgs)) continue;

    // Filtra mensagens recebidas hoje
    $recHoje = array();
    foreach ($msgs as $m) {
        $from = $m['fromMe'] ?? false;
        if ($from) continue; // só queremos recebidas
        $ts = (int)($m['momment'] ?? $m['moment'] ?? 0);
        if ($ts > 0 && date('Y-m-d', $ts/1000) === $hoje) $recHoje[] = $m;
    }

    if (!$recHoje) continue;

    echo "\n  👤 {$nome} ({$tel}) — " . count($recHoje) . " recebidas hoje no Z-API\n";

    // Checa no banco quantas recebidas temos hoje desse telefone
    $ql = $pdo->prepare("
        SELECT COUNT(*) FROM zapi_mensagens m
        JOIN zapi_conversas co ON co.id = m.conversa_id
        WHERE co.canal = ? AND co.telefone LIKE ? AND m.direcao = 'recebida'
          AND DATE(m.created_at) = ?
    ");
    $ql->execute(array($ddd, '%' . substr($tel, -9) . '%', $hoje));
    $noBanco = (int)$ql->fetchColumn();

    $diff = count($recHoje) - $noBanco;
    if ($diff > 0) {
        echo "      ❌ FALTAM no Hub: {$diff} mensagem(ns) (Z-API tem " . count($recHoje) . ", banco tem {$noBanco})\n";
        $faltando += $diff;
        foreach (array_slice($recHoje, 0, 3) as $m) {
            $txt = $m['text']['message'] ?? ($m['content'] ?? '[mídia]');
            echo "         → '" . mb_substr($txt, 0, 80) . "' em " . date('H:i', ($m['momment'] ?? 0)/1000) . "\n";
        }
    } else {
        echo "      ✅ OK (Z-API tem " . count($recHoje) . ", banco tem {$noBanco})\n";
    }
}

echo "\n=== RESUMO ===\n";
echo "Chats verificados: {$conferidos}\n";
echo "Mensagens faltando no Hub: {$faltando}\n";
if ($faltando > 0) {
    echo "\n⚠️ Confirmado: webhook não está entregando mensagens recebidas.\n";
    echo "Solução: desconectar e reconectar a instância no painel Z-API (app.z-api.io).\n";
} else {
    echo "\n✅ Nenhuma mensagem faltando. O problema pode ter sido só os webhooks desconfigurados antes.\n";
}
