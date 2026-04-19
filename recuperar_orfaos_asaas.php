<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
set_time_limit(300);

$pdo = db();
$rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('asaas_api_key','asaas_env')")->fetchAll();
$cfg = array();
foreach ($rows as $r) $cfg[$r['chave']] = $r['valor'];
$apiKey = $cfg['asaas_api_key'];
$base = ($cfg['asaas_env'] === 'production') ? 'https://api.asaas.com/v3' : 'https://sandbox.asaas.com/api/v3';

echo "=== Recuperar órfãos Asaas ===\n\n";

// IDs únicos de customers do Asaas em cobranças SEM client_id
$orfaos = $pdo->query(
    "SELECT DISTINCT asaas_customer_id FROM asaas_cobrancas
     WHERE client_id IS NULL AND asaas_customer_id IS NOT NULL AND asaas_customer_id != ''"
)->fetchAll(PDO::FETCH_COLUMN);

echo "Encontrados " . count($orfaos) . " customer_ids órfãos únicos\n\n";

$recuperados = 0; $naoEncontrados = 0; $criados = 0; $vinculadosExistente = 0;
foreach ($orfaos as $custId) {
    $ch = curl_init($base . '/customers/' . $custId);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => array('access_token: ' . $apiKey, 'Content-Type: application/json'),
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $b = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) { $naoEncontrados++; echo "  [{$code}] {$custId} não encontrado\n"; continue; }
    $cust = json_decode($b, true);
    if (!$cust || empty($cust['id'])) { $naoEncontrados++; continue; }

    $nome  = $cust['name'] ?? '';
    $cpf   = preg_replace('/\D/', '', $cust['cpfCnpj'] ?? '');
    $email = strtolower(trim($cust['email'] ?? ''));
    $phone = preg_replace('/\D/', '', $cust['mobilePhone'] ?? ($cust['phone'] ?? ''));

    // Tenta match por CPF ou email
    $match = null;
    if ($cpf) {
        $q = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cpf,''),'.',''),'-',''),'/',''),' ','') = ? LIMIT 1");
        $q->execute(array($cpf));
        $match = $q->fetchColumn() ?: null;
    }
    if (!$match && $email) {
        $q = $pdo->prepare("SELECT id FROM clients WHERE LOWER(email) = ? LIMIT 1");
        $q->execute(array($email));
        $match = $q->fetchColumn() ?: null;
    }

    if ($match) {
        $pdo->prepare("UPDATE clients SET asaas_customer_id = ?, asaas_sincronizado = 1 WHERE id = ?")
            ->execute(array($custId, $match));
        $clientId = $match;
        $vinculadosExistente++;
    } else {
        $pdo->prepare(
            "INSERT INTO clients (name, cpf, email, phone, source, notes, asaas_customer_id, asaas_sincronizado, created_at)
             VALUES (?, ?, ?, ?, 'asaas_import', 'Recuperado de Asaas — cliente órfão em " . date('d/m/Y') . "', ?, 1, NOW())"
        )->execute(array($nome, $cpf ?: null, $email ?: null, $phone ?: null, $custId));
        $clientId = (int)$pdo->lastInsertId();
        $criados++;
    }

    // Vincular todas as cobranças órfãs desse customer
    $upd = $pdo->prepare("UPDATE asaas_cobrancas SET client_id = ? WHERE asaas_customer_id = ? AND client_id IS NULL");
    $upd->execute(array($clientId, $custId));
    $recuperados += $upd->rowCount();
}

echo "\n========= RESUMO =========\n";
echo "Customer IDs órfãos encontrados no Asaas: " . (count($orfaos) - $naoEncontrados) . "\n";
echo "  Vinculados a cliente existente: {$vinculadosExistente}\n";
echo "  Novos clientes criados:         {$criados}\n";
echo "  Customer IDs não encontrados:   {$naoEncontrados}\n";
echo "  Cobranças órfãs recuperadas:    {$recuperados}\n";

$finalOrfas = $pdo->query("SELECT COUNT(*) FROM asaas_cobrancas WHERE client_id IS NULL")->fetchColumn();
echo "\nCobranças AINDA SEM cliente (permanentemente órfãs): {$finalOrfas}\n";
