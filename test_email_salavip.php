<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Teste envio e-mail Sala VIP ===\n\n";

// 1. Verificar configuração Brevo
$cfg = array('key' => '', 'email' => '', 'name' => '');
$rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
foreach ($rows as $r) {
    if ($r['chave'] === 'brevo_api_key') $cfg['key'] = $r['valor'];
    if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
    if ($r['chave'] === 'brevo_sender_name') $cfg['name'] = $r['valor'];
}

echo "Brevo API Key: " . ($cfg['key'] ? substr($cfg['key'], 0, 10) . '...' : 'NÃO CONFIGURADA') . "\n";
echo "Sender Email: " . ($cfg['email'] ?: 'NÃO CONFIGURADO') . "\n";
echo "Sender Name: " . ($cfg['name'] ?: 'NÃO CONFIGURADO') . "\n\n";

if (!$cfg['key']) {
    echo "ERRO: Chave Brevo não encontrada na tabela configuracoes!\n";
    echo "Chaves existentes:\n";
    $all = $pdo->query("SELECT chave FROM configuracoes ORDER BY chave")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($all as $c) echo "  - $c\n";
    exit;
}

// 2. Verificar último usuário criado na Sala VIP
echo "=== Últimos usuarios salavip ===\n";
$users = $pdo->query("SELECT su.id, su.cliente_id, su.cpf, su.email, su.ativo, su.token_ativacao, su.token_expira, c.name FROM salavip_usuarios su LEFT JOIN clients c ON c.id = su.cliente_id ORDER BY su.id DESC LIMIT 5")->fetchAll();
foreach ($users as $u) {
    echo "#" . $u['id'] . " cliente=" . $u['cliente_id'] . " " . $u['name'] . " email=" . $u['email'] . " ativo=" . $u['ativo'] . " token=" . ($u['token_ativacao'] ? substr($u['token_ativacao'], 0, 10) . '...' : 'NULL') . " expira=" . ($u['token_expira'] ?: 'NULL') . "\n";
}

// 3. Tentar enviar e-mail de teste
echo "\n=== Enviando e-mail de teste ===\n";
$testEmail = 'amandaguedesferreira@gmail.com';
$testNome = 'Amanda';
$testLink = 'https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=TESTE123';

$html = '<html><body><h2>Teste Sala VIP</h2><p>Este é um teste de envio. Se você recebeu, o Brevo está funcionando!</p><p><a href="' . $testLink . '">Link de teste</a></p></body></html>';

$data = array(
    'sender' => array('name' => $cfg['name'], 'email' => $cfg['email']),
    'to' => array(array('email' => $testEmail, 'name' => $testNome)),
    'subject' => 'Teste Sala VIP - ' . date('H:i:s'),
    'htmlContent' => $html,
);

echo "Para: $testEmail\n";
echo "De: " . $cfg['email'] . "\n";
echo "Payload: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => array('api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'),
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_SSL_VERIFYPEER => true,
));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
if ($curlError) echo "cURL Error: $curlError\n";
