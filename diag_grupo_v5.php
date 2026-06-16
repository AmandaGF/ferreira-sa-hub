<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();

echo "=== Verificacao: msgs RECENTES enviadas pra DMs (nao-grupos) canal 24 ===\n";
$st = $pdo->query("SELECT m.id, m.zapi_message_id, m.status, m.lida, m.created_at, co.telefone, co.nome_contato
                   FROM zapi_mensagens m
                   JOIN zapi_conversas co ON co.id = m.conversa_id
                   JOIN zapi_instancias i ON i.id = co.instancia_id
                   WHERE i.ddd = '24' AND m.direcao = 'enviada' AND m.tipo = 'texto'
                     AND COALESCE(co.eh_grupo, 0) = 0
                     AND m.created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR)
                   ORDER BY m.created_at DESC LIMIT 10");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $prefix = substr($m['zapi_message_id'], 0, 4);
    $tipo = ($prefix === '3EB0') ? '⚠️ 3EB0' : '✓ NAO-3EB0';
    echo "  msg #{$m['id']} {$m['created_at']} | $tipo | id={$m['zapi_message_id']} (" . strlen($m['zapi_message_id']) . "ch) | status='{$m['status']}' | lida={$m['lida']} | dest={$m['nome_contato']}\n";
}
echo "\n";

$inst = zapi_get_instancia('24');
$cfg = zapi_get_config();
$base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
$headers = array('Content-Type: application/json');
if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

function envia($url, $body, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch); curl_close($ch);
    $j = json_decode($resp, true) ?: array();
    $id = $j['messageId'] ?? '?';
    $sint = strpos($id, '3EB0') === 0;
    echo "  phone='{$body['phone']}' → msgId=$id " . ($sint ? '⚠️ sintético' : '✓ NÃO-sintético') . "\n";
    return !$sint;
}

echo "=== Teste comparativo: LID moderno vs grupo antigo ===\n\n";

echo "1️⃣  LID GROUP MODERNO (120363... -group) — Controladoria:\n";
envia($base . '/send-text', array('phone' => '120363382460329785-group', 'message' => '[V5-A] LID moderno ' . date('H:i:s')), $headers);

echo "\n2️⃣  GRUPO ANTIGO (formato XXXX-YYYY) — IBDFAM Mediação:\n";
envia($base . '/send-text', array('phone' => '5521999797435-1509647042', 'message' => '[V5-B] grupo antigo ' . date('H:i:s')), $headers);

echo "\n3️⃣  GRUPO ANTIGO 2 — Leandro S. concurso F&P:\n";
envia($base . '/send-text', array('phone' => '5524992965088-1486124897', 'message' => '[V5-C] grupo antigo 2 ' . date('H:i:s')), $headers);

echo "\n4️⃣  Outro LID novo — Treinamento Dir. Civil:\n";
envia($base . '/send-text', array('phone' => '120363028538784827-group', 'message' => '[V5-D] outro LID ' . date('H:i:s')), $headers);

echo "\n=== Conclusao ===\n";
echo "Se TODOS os LID retornam sintético E os antigos retornam não-sintético,\n";
echo "→ confirmado bug Z-API com LID groups. Solução: usar grupo antigo OU\n";
echo "  atualizar conta Z-API pra plano que suporta LID.\n";
