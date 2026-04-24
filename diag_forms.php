<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Últimas 20 form_submissions ===\n";
$q = $pdo->query("SELECT id, form_type, protocol, SUBSTR(COALESCE(payload_json,''),1,100) AS preview_payload, created_at FROM form_submissions ORDER BY id DESC LIMIT 20");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} [{$r['form_type']}] {$r['created_at']} proto={$r['protocol']}\n";
}

echo "\n=== CONTAGEM por form_type ===\n";
$q = $pdo->query("SELECT form_type, COUNT(*) AS n, MAX(created_at) AS ultima FROM form_submissions GROUP BY form_type ORDER BY ultima DESC");
foreach ($q->fetchAll() as $r) {
    echo "  {$r['form_type']}: {$r['n']} total, última em {$r['ultima']}\n";
}

echo "\n=== Busca por 'Sayonara' em form_submissions ===\n";
$q = $pdo->prepare("SELECT id, form_type, created_at, SUBSTR(COALESCE(payload_json,''),1,300) AS prev FROM form_submissions WHERE payload_json LIKE ? LIMIT 5");
$q->execute(array('%Sayonara%'));
$res = $q->fetchAll();
if (!$res) echo "  Nenhum registro com 'Sayonara' no payload.\n";
foreach ($res as $r) echo "  #{$r['id']} [{$r['form_type']}] {$r['created_at']}\n    " . $r['prev'] . "\n";

echo "\n=== Busca por 'Sayonara' em clients ===\n";
$q = $pdo->prepare("SELECT id, name, phone, created_at FROM clients WHERE name LIKE ?");
$q->execute(array('%Sayonara%'));
foreach ($q->fetchAll() as $r) echo "  client#{$r['id']} {$r['name']} tel={$r['phone']} created={$r['created_at']}\n";

echo "\n=== Testa se api_form.php responde ===\n";
$ch = curl_init('https://ferreiraesa.com.br/conecta/publico/api_form.php');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('form_type' => 'teste', '__ping' => 1)),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    CURLOPT_TIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP {$code}\nResp (primeiros 400 chars): " . substr((string)$resp, 0, 400) . "\n";

echo "\n=== Ver submit.php do convivencia_form ===\n";
$pubHtml = realpath(__DIR__ . '/..');
$convSubmit = $pubHtml . '/convivencia_form/submit.php';
if (!file_exists($convSubmit)) {
    echo "[ERRO] Arquivo NÃO EXISTE: $convSubmit\n";
} else {
    echo "Existe: $convSubmit\n";
    $cont = file_get_contents($convSubmit);
    echo "Tem 'api_form.php' no código? " . (strpos($cont, 'api_form.php') !== false ? 'SIM ✓' : 'NÃO — DUAL-WRITE AUSENTE ✗') . "\n";
    echo "Tem 'conecta/publico'? " . (strpos($cont, 'conecta/publico') !== false ? 'SIM ✓' : 'NÃO ✗') . "\n";
    echo "Size: " . filesize($convSubmit) . " bytes, modificado em " . date('Y-m-d H:i:s', filemtime($convSubmit)) . "\n";
}
