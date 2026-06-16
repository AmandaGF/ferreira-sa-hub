<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();

$grupoLid = '120363382460329785';
$inst = zapi_get_instancia('24');
$cfg = zapi_get_config();
$base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
$headers = array('Content-Type: application/json');
if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];
$ts = date('H:i:s');

echo "=== TESTE 1: via zapi_send_text (como a tela admin faz) ===\n";
$r1 = zapi_send_text('24', $grupoLid . '@g.us', "🅰️ TESTE-A $ts — Jorjão via zapi_send_text");
echo "ok = " . (!empty($r1['ok']) ? 'SIM' : 'NAO') . " | data = " . substr(json_encode($r1['data'] ?? null), 0, 150) . "\n";

sleep(1);

echo "\n=== TESTE 2: via curl direto + phone='ID-group' (como V5-A) ===\n";
$ch = curl_init($base . '/send-text');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('phone' => $grupoLid . '-group', 'message' => "🅱️ TESTE-B $ts — Jorjão via curl direto -group")),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false,
));
$resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP $http | resp = " . substr($resp, 0, 200) . "\n";

sleep(3);

echo "\n=== AGORA OS STATUS NO BANCO ===\n";
$st = $pdo->query("SELECT m.id, m.status, m.created_at, LEFT(m.conteudo,80) AS preview
                   FROM zapi_mensagens m
                   JOIN zapi_conversas co ON co.id = m.conversa_id
                   WHERE co.telefone IN ('$grupoLid', '$grupoLid@g.us')
                     AND m.direcao = 'enviada'
                   ORDER BY m.created_at DESC LIMIT 6");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $stat = $m['status'] ?: '⚠️ (vazio — não chegou)';
    echo "  #{$m['id']} {$m['created_at']} | $stat\n    \"" . substr($m['preview'], 0, 70) . "\"\n";
}
echo "\nAguarde uns segundos e olhe no grupo Controladoria QUAL CHEGOU: 🅰️ (zapi_send_text) ou 🅱️ (curl direto) ou os 2 ou nenhum.\n";
