<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();
$dry = ($_GET['dry'] ?? '1') !== '0';

echo "=== Descobrir número real das conversas @lid — " . date('Y-m-d H:i:s') . " ===\n";
echo "Modo: " . ($dry ? 'DRY-RUN (só lista)' : 'EXECUTAR (atualiza telefone)') . "\n\n";

$logFile = __DIR__ . '/files/zapi_webhook.log';
$logLines = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : array();
// Últimas 40000 linhas
$logLines = array_slice($logLines, -40000);

// Indexar log por messageId pra busca rápida
$logPorMsgId = array();
foreach ($logLines as $l) {
    if (preg_match('/"messageId":"([A-Z0-9]{6,})"/', $l, $m)) {
        $logPorMsgId[$m[1]] = $l;
    }
}
echo "Log indexado: " . count($logPorMsgId) . " payloads\n\n";

// Conversas com telefone @lid puro (ou telefone sem 55 que é @lid disfarçado)
$r = $pdo->query("SELECT id, canal, telefone, chat_lid, nome_contato,
    (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = zapi_conversas.id) AS msgs
    FROM zapi_conversas
    WHERE (eh_grupo = 0 OR eh_grupo IS NULL)
      AND (telefone LIKE '%@lid' OR (telefone NOT LIKE '55%' AND LENGTH(telefone) >= 10 AND telefone REGEXP '^[0-9]+$'))
    ORDER BY msgs DESC")->fetchAll();

$corrigidas = 0;
$semNumero  = 0;

foreach ($r as $c) {
    // Últimas 10 msgs RECEBIDAS com zapi_message_id
    $ms = $pdo->query("SELECT zapi_message_id FROM zapi_mensagens
        WHERE conversa_id = {$c['id']} AND direcao = 'recebida' AND zapi_message_id != ''
        ORDER BY id DESC LIMIT 10")->fetchAll();

    $numReal = null;
    foreach ($ms as $mm) {
        $zid = $mm['zapi_message_id'];
        if (!isset($logPorMsgId[$zid])) continue;
        $payload = $logPorMsgId[$zid];
        // Procura senderPhoneNumber no payload
        if (preg_match('/"senderPhoneNumber":"(\d{10,15})"/', $payload, $m)) {
            $cand = $m[1];
            // Valida: 10-13 dígitos, preferencialmente começando com 55
            if (strlen($cand) >= 10 && strlen($cand) <= 13 && strpos($cand, '55') === 0) {
                $numReal = $cand;
                break;
            }
        }
    }

    echo sprintf("  #%d canal=%s tel=%s nome='%s' msgs=%d", $c['id'], $c['canal'], $c['telefone'], $c['nome_contato'] ?: '?', $c['msgs']);
    if ($numReal) {
        echo " → NÚMERO REAL: {$numReal}\n";
        $corrigidas++;
        if (!$dry) {
            $telNorm = zapi_normaliza_telefone($numReal);
            // Se já existe conv com esse número no mesmo canal (que não seja a #id), NÃO atualiza (evitar 2 convs com mesmo número)
            $existe = $pdo->prepare("SELECT id FROM zapi_conversas WHERE canal = ? AND telefone = ? AND id != ? LIMIT 1");
            $existe->execute(array($c['canal'], $telNorm, $c['id']));
            if ($existe->fetchColumn()) {
                echo "    ⚠ já existe outra conv com esse número — não sobrescrevi\n";
            } else {
                $pdo->prepare("UPDATE zapi_conversas SET telefone = ? WHERE id = ?")->execute(array($telNorm, $c['id']));
                echo "    ✓ telefone atualizado pra {$telNorm}\n";
            }
        }
    } else {
        echo " — número real não encontrado no log\n";
        $semNumero++;
    }
}

echo "\n══ RESUMO ══\n";
echo "Total: " . count($r) . "\n";
echo "Com número real encontrado: {$corrigidas}\n";
echo "Sem número identificável: {$semNumero}\n";
if ($dry) echo "\nPara atualizar os telefones: adicione &dry=0\n";
