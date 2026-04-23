<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== VARREDURA convs WA potencialmente erradas — " . date('Y-m-d H:i:s') . " ===\n\n";

// Carrega log pra cruzamento
$logFile = __DIR__ . '/files/zapi_webhook.log';
$logLines = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : array();
// Mantém só últimas 20000 linhas (performance)
$logLines = array_slice($logLines, -20000);

/**
 * Dado um zapi_message_id de mensagem ENVIADA, procura no log por status
 * callback que contém o NÚMERO REAL do destinatário.
 * Ex: {"ids":["MSGID"],"phone":"5521999888777","type":"MessageStatusCallback"}
 */
function buscar_numero_real($msgId, &$logLines) {
    if (!$msgId) return null;
    foreach ($logLines as $l) {
        if (strpos($l, $msgId) === false) continue;
        if (strpos($l, 'MessageStatusCallback') === false && strpos($l, 'DeliveryCallback') === false) continue;
        // Extrai phone
        if (preg_match('/"phone":"(\d{10,15})"/', $l, $m)) {
            return $m[1]; // número real puro (sem @lid)
        }
    }
    return null;
}

// 1) Candidatas: conversas com chat_lid preenchido (onde @lid matching rola),
//    arquivadas OU com nome suspeito (contém "@lid" ou é só números)
echo "--- 1) Conversas com chat_lid — verificando número real via log ---\n";
$r = $pdo->query("SELECT id, canal, telefone, chat_lid, nome_contato, atendente_id, status,
    (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = zapi_conversas.id) AS msgs
    FROM zapi_conversas
    WHERE chat_lid IS NOT NULL AND chat_lid != ''
      AND (eh_grupo = 0 OR eh_grupo IS NULL)
    ORDER BY msgs DESC
    LIMIT 100")->fetchAll();

$suspeitas = array();
foreach ($r as $c) {
    // Pega uma msg enviada recente
    $m = $pdo->query("SELECT zapi_message_id FROM zapi_mensagens WHERE conversa_id = {$c['id']} AND direcao = 'enviada' AND zapi_message_id != '' ORDER BY id DESC LIMIT 3")->fetchAll();
    if (!$m) continue;
    $numReal = null;
    foreach ($m as $mm) {
        $numReal = buscar_numero_real($mm['zapi_message_id'], $logLines);
        if ($numReal) break;
    }
    if (!$numReal) continue;

    // Normaliza tel da conversa (só dígitos)
    $telConv = preg_replace('/\D/', '', str_replace('@lid', '', $c['telefone']));
    $ultTelConv = substr($telConv, -10);
    $ultNumReal = substr($numReal, -10);

    // Se número real ≠ tel da conv (últimos 10), é suspeita
    if ($ultTelConv !== $ultNumReal) {
        $suspeitas[] = array_merge($c, array('num_real' => $numReal));
    }
}

echo "Suspeitas encontradas: " . count($suspeitas) . "\n\n";
foreach ($suspeitas as $s) {
    // Procura se existe outra conv com esse número real
    $outra = $pdo->prepare("SELECT id, nome_contato, chat_lid, atendente_id, status,
        (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = zapi_conversas.id) AS msgs
        FROM zapi_conversas
        WHERE canal = ? AND (eh_grupo=0 OR eh_grupo IS NULL)
          AND id != ?
          AND RIGHT(REPLACE(REPLACE(telefone,'@lid',''),'@g.us',''), 10) = ?
        LIMIT 1");
    $outra->execute(array($s['canal'], $s['id'], substr($s['num_real'], -10)));
    $oficial = $outra->fetch();
    echo "⚠️  #{$s['id']} canal={$s['canal']} nome='{$s['nome_contato']}' tel={$s['telefone']} chat_lid={$s['chat_lid']} msgs={$s['msgs']} [{$s['status']}]\n";
    echo "    → número real (via log): {$s['num_real']}\n";
    if ($oficial) {
        echo "    → conv 'oficial' com esse número: #{$oficial['id']} '{$oficial['nome_contato']}' msgs={$oficial['msgs']} [{$oficial['status']}]\n";
        echo "    ↪ SUGESTÃO: mesclar #{$s['id']} → #{$oficial['id']} (usar reconciliar com &par=" . $s['id'] . ":" . $oficial['id'] . ")\n";
    } else {
        echo "    → NENHUMA conv oficial com esse número existe. Renomear #{$s['id']} e mudar telefone pra '{$s['num_real']}' manualmente.\n";
    }
    echo "\n";
}

if (empty($suspeitas)) {
    echo "✅ Nenhuma conversa com descasamento detectado.\n";
}
