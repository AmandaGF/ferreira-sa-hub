<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();
$dry = ($_GET['dry'] ?? '1') !== '0';

echo "=== Descobrir número real das conversas @lid — " . date('Y-m-d H:i:s') . " ===\n";
echo "Modo: " . ($dry ? 'DRY-RUN' : 'EXECUTAR') . "\n\n";

$cfg = zapi_get_config();

function buscar_via_zapi_metadata($ddd, $lid) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id']) return null;
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token']
         . '/contacts/' . urlencode($lid);
    $headers = array('Accept: application/json');
    if (!empty($cfg['client_token'])) $headers[] = 'Client-Token: ' . $cfg['client_token'];
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    $data = json_decode($resp, true);
    if (!is_array($data)) return null;
    // Procura número real na resposta
    foreach (array('phone', 'number', 'wa_phone', 'senderPhoneNumber') as $k) {
        if (!empty($data[$k]) && is_string($data[$k]) && preg_match('/^\d{10,15}$/', preg_replace('/\D/', '', $data[$k]))) {
            return preg_replace('/\D/', '', $data[$k]);
        }
    }
    return null;
}

// Pega conversas @lid
$convs = $pdo->query("SELECT co.id, co.canal, co.telefone, co.chat_lid, co.nome_contato, i.ddd,
    (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = co.id) AS msgs
    FROM zapi_conversas co JOIN zapi_instancias i ON i.id = co.instancia_id
    WHERE (co.eh_grupo = 0 OR co.eh_grupo IS NULL)
      AND (co.telefone LIKE '%@lid' OR (co.telefone NOT LIKE '55%' AND LENGTH(co.telefone) >= 10 AND co.telefone REGEXP '^[0-9]+$'))
    ORDER BY msgs DESC")->fetchAll();

$encontrados = 0;
$semNum = 0;

foreach ($convs as $c) {
    echo sprintf("  #%d %s tel=%s nome='%s' msgs=%d\n", $c['id'], $c['canal'], $c['telefone'], $c['nome_contato'] ?: '?', $c['msgs']);

    $numReal = null;
    $fonte = '';

    // 1) Match na base de clientes por nome (se nome parece de pessoa)
    $nome = trim($c['nome_contato'] ?: '');
    if ($nome && strpos($nome, '@lid') === false && !preg_match('/^\d+$/', $nome) && mb_strlen($nome) >= 4) {
        $cli = $pdo->prepare("SELECT phone FROM clients
            WHERE LOWER(name) LIKE LOWER(?) AND phone IS NOT NULL AND phone != ''
              AND LENGTH(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-','')) >= 10
            ORDER BY LENGTH(name) ASC LIMIT 1");
        $cli->execute(array('%' . $nome . '%'));
        $ph = $cli->fetchColumn();
        if ($ph) {
            $phNorm = preg_replace('/\D/', '', $ph);
            if (strlen($phNorm) >= 10 && strlen($phNorm) <= 13) {
                $numReal = $phNorm;
                $fonte = 'clients.name';
            }
        }
    }

    // 2) Endpoint Z-API (se ainda não achou)
    if (!$numReal) {
        // Só tenta se ddd conhecido
        $lidParam = (strpos($c['telefone'], '@lid') === false) ? $c['telefone'] . '@lid' : $c['telefone'];
        $n = buscar_via_zapi_metadata($c['ddd'], $lidParam);
        if ($n) { $numReal = $n; $fonte = 'zapi-metadata'; }
    }

    if ($numReal) {
        echo "    → NÚMERO: {$numReal} (via {$fonte})\n";
        $encontrados++;
        if (!$dry) {
            $telNorm = zapi_normaliza_telefone($numReal);
            $existe = $pdo->prepare("SELECT id FROM zapi_conversas WHERE canal = ? AND telefone = ? AND id != ? LIMIT 1");
            $existe->execute(array($c['canal'], $telNorm, $c['id']));
            if ($existe->fetchColumn()) {
                echo "    ⚠ já existe outra conv com esse tel — não sobrescreve\n";
            } else {
                $pdo->prepare("UPDATE zapi_conversas SET telefone = ? WHERE id = ?")->execute(array($telNorm, $c['id']));
                echo "    ✓ atualizado\n";
            }
        }
    } else {
        $semNum++;
    }
}

echo "\n══ RESUMO ══\n";
echo "Total: " . count($convs) . "\n";
echo "Com número encontrado: {$encontrados}\n";
echo "Sem número: {$semNum}\n";
if ($dry && $encontrados) echo "\nPara atualizar: &dry=0\n";
