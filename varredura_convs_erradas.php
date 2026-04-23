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
        // Extrai TODOS os "phone":"..." e filtra: número BR normal 12-13 dígitos
        // começando com 55 (DDI Brasil). Evita pegar @lid disfarçado (15+ digits).
        if (preg_match_all('/"phone":"(\d{10,15})"/', $l, $ms)) {
            foreach ($ms[1] as $candidato) {
                $len = strlen($candidato);
                // Número BR: começa com 55 e tem 12 ou 13 dígitos (55 + DDD + 8ou9 dígitos)
                // OU número internacional genérico 10-13 digitos
                if ($len >= 10 && $len <= 13) return $candidato;
            }
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
    echo "✅ Nenhuma conversa com descasamento detectado pelo log.\n";
}

// ─── 2) Conversas com telefone @lid puro (sem DDI 55) — candidatas a nome errado
echo "\n--- 2) Conversas com telefone @lid puro (sem 55 BR) — possíveis nomes errados ---\n";
$r = $pdo->query("SELECT id, canal, telefone, chat_lid, nome_contato, atendente_id, status,
    (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = zapi_conversas.id) AS msgs,
    (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = zapi_conversas.id) AS ult
    FROM zapi_conversas
    WHERE (eh_grupo = 0 OR eh_grupo IS NULL)
      AND (telefone LIKE '%@lid' OR (telefone NOT LIKE '55%' AND LENGTH(telefone) >= 10 AND telefone REGEXP '^[0-9]+$'))
    ORDER BY msgs DESC
    LIMIT 50")->fetchAll();
echo "Encontradas: " . count($r) . "\n";
foreach ($r as $c) {
    // Busca número real via log
    $m = $pdo->query("SELECT zapi_message_id FROM zapi_mensagens WHERE conversa_id = {$c['id']} AND direcao = 'enviada' AND zapi_message_id != '' ORDER BY id DESC LIMIT 5")->fetchAll();
    $numReal = null;
    foreach ($m as $mm) {
        $candidato = buscar_numero_real($mm['zapi_message_id'], $logLines);
        if ($candidato) { $numReal = $candidato; break; }
    }
    echo sprintf("  #%d canal=%s tel=%s chat_lid=%s nome='%s' msgs=%d [%s] ult=%s\n",
        $c['id'], $c['canal'], $c['telefone'], $c['chat_lid'] ?: '-', $c['nome_contato'] ?: '?', $c['msgs'], $c['status'], $c['ult'] ?: '-');
    if ($numReal) {
        echo "      ↪ número real via log: {$numReal}\n";
        // Procura conv oficial com esse número
        $outra = $pdo->prepare("SELECT id, nome_contato, status FROM zapi_conversas
            WHERE canal = ? AND (eh_grupo=0 OR eh_grupo IS NULL) AND id != ?
              AND RIGHT(REPLACE(REPLACE(telefone,'@lid',''),'@g.us',''), 10) = ?
            LIMIT 1");
        $outra->execute(array($c['canal'], $c['id'], substr($numReal, -10)));
        $oficial = $outra->fetch();
        if ($oficial) {
            echo "      ↪ ⚠️  existe conv oficial #{$oficial['id']} '{$oficial['nome_contato']}' [{$oficial['status']}] — sugerido mesclar #{$c['id']} → #{$oficial['id']}\n";
        }
    }
}
