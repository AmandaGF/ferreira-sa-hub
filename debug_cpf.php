<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
header('Content-Type: text/plain; charset=utf-8');

$cpf = '06366628700';
$token = '9320d4099cf4099528cce511241c48a0';

echo "=== Debug CPF API ===\n\n";

// Teste 1: cpfcnpj.com.br
echo "--- cpfcnpj.com.br ---\n";
$url = "https://api.cpfcnpj.com.br/$token/1/$cpf";
echo "URL: $url\n";
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'FES-Hub/1.0',
));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP: $httpCode\n";
echo "Erro cURL: " . ($error ?: 'nenhum') . "\n";
echo "Resposta: $response\n\n";

// Teste 2: Consultar base interna
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "--- Base interna (clients) ---\n";
$cpfFmt = substr($cpf,0,3).'.'.substr($cpf,3,3).'.'.substr($cpf,6,3).'-'.substr($cpf,9,2);
$stmt = $pdo->prepare("SELECT id, name, cpf FROM clients WHERE cpf = ? OR REPLACE(REPLACE(cpf,'.',''),'-','') = ? LIMIT 3");
$stmt->execute(array($cpfFmt, $cpf));
$rows = $stmt->fetchAll();
echo "Encontrados: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  #{$r['id']} — {$r['name']} (CPF: {$r['cpf']})\n";
}

echo "\n--- Base interna (case_partes) ---\n";
$stmt2 = $pdo->prepare("SELECT id, nome, cpf FROM case_partes WHERE cpf = ? OR cpf = ? LIMIT 3");
$stmt2->execute(array($cpf, $cpfFmt));
$rows2 = $stmt2->fetchAll();
echo "Encontrados: " . count($rows2) . "\n";
foreach ($rows2 as $r) {
    echo "  #{$r['id']} — {$r['nome']} (CPF: {$r['cpf']})\n";
}

echo "\n=== FIM ===\n";
