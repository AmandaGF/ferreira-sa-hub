<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
header('Content-Type: text/plain; charset=utf-8');

echo "=== Teste: Simulando envio de formulário gastos_pensao ===\n\n";

// Simular exatamente o que o submit.php faz
$conectaUrl = 'https://ferreiraesa.com.br/conecta/publico/api_form.php';
$testData = array(
    'form_type' => 'gastos_pensao',
    'client_name' => 'TESTE AUTOMATICO - APAGAR',
    'client_phone' => '0000000000',
    'nome_responsavel' => 'TESTE AUTOMATICO',
    'whatsapp' => '0000000000',
    'nome_filho_referente' => 'Filho Teste',
    'fonte_renda' => 'Teste',
    'renda_mensal_cents' => 100000,
    'qtd_filhos' => 1,
    'moradores' => 3,
    'totais' => array('total_geral' => 100000),
);

echo "URL: $conectaUrl\n";
echo "Payload: " . json_encode($testData, JSON_UNESCAPED_UNICODE) . "\n\n";

$ch = curl_init($conectaUrl);
curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($testData, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_VERBOSE        => false,
));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "cURL Error: " . ($curlErr ?: 'nenhum') . "\n";
echo "Response: $response\n\n";

if ($httpCode === 200) {
    $resp = json_decode($response, true);
    if (isset($resp['ok']) && $resp['ok']) {
        echo "SUCESSO! Protocolo: " . ($resp['protocol'] ?? 'N/A') . "\n";
        echo "Submission ID: " . ($resp['submission_id'] ?? 'N/A') . "\n";

        // Apagar o teste
        echo "\n--- Apagando registro de teste ---\n";
        require_once __DIR__ . '/core/config.php';
        require_once __DIR__ . '/core/database.php';
        $pdo = db();
        $del = $pdo->prepare("DELETE FROM form_submissions WHERE client_name = 'TESTE AUTOMATICO - APAGAR'");
        $del->execute();
        echo "[OK] Teste apagado (" . $del->rowCount() . " registros)\n";
    } else {
        echo "FALHA! Resposta: " . print_r($resp, true) . "\n";
    }
} else {
    echo "FALHA! HTTP $httpCode\n";
}

echo "\n=== FIM ===\n";
