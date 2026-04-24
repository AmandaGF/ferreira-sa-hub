<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');

// Simula exatamente o POST que submit.php faz pro Hub
$conectaUrl = 'https://www.ferreiraesa.com.br/conecta/publico/api_form.php';
$payload = json_encode(array(
    'form_type'         => 'convivencia',
    'client_name'       => 'Sayonara TESTE DUAL-WRITE',
    'client_phone'      => '(21) 99959-1077',
    'client_email'      => 'teste@teste.com',
    'child_name'        => 'Teste criança',
    'child_age'         => 5,
    'relationship_role' => 'mae',
    'answers'           => array('teste' => 'dual-write diag'),
    'protocol_original' => 'VST-TESTE' . dechex(time()),
), JSON_UNESCAPED_UNICODE);

echo "=== POST test pra api_form.php ===\n";
echo "URL: {$conectaUrl}\n";
echo "Payload: {$payload}\n\n";

$ch = curl_init($conectaUrl);
curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_VERBOSE        => true,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP code: {$code}\n";
echo "cURL error: " . ($err ?: '(nenhum)') . "\n";
echo "Resposta (primeiros 1000 chars):\n";
echo substr((string)$resp, 0, 1000) . "\n\n";

// Ver se salvou
require_once __DIR__ . '/core/database.php';
$pdo = db();
$q = $pdo->query("SELECT id, form_type, created_at, SUBSTR(payload_json,1,200) AS prev FROM form_submissions WHERE form_type='convivencia' ORDER BY id DESC LIMIT 3");
echo "=== Últimas 3 convivencia AGORA ===\n";
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} {$r['created_at']}: " . $r['prev'] . "\n";
}
