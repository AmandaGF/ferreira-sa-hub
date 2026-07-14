<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$parteId = 902; $clientId = 2417;

echo "=== ANTES ===\n";
$p = $pdo->query("SELECT id, nome, cpf, client_id, eh_nosso_cliente FROM case_partes WHERE id = $parteId")->fetch(PDO::FETCH_ASSOC);
print_r($p);
$c = $pdo->query("SELECT id, name, cpf, birth_date, address_city, address_state FROM clients WHERE id = $clientId")->fetch(PDO::FETCH_ASSOC);
print_r($c);

// 1) Vincular parte ao cliente
$pdo->prepare("UPDATE case_partes SET client_id = ?, eh_nosso_cliente = 1 WHERE id = ?")
    ->execute(array($clientId, $parteId));
echo "\n✓ Parte #$parteId vinculada ao cliente #$clientId\n";

// 2) Sincronizar dados (só campos vazios)
$parteData = $pdo->query("SELECT nome, cpf, rg, nascimento, profissao, estado_civil, razao_social, cnpj, email, telefone, endereco, cidade, uf, cep FROM case_partes WHERE id = $parteId")->fetch(PDO::FETCH_ASSOC);
$campos = sincronizar_parte_com_cliente($pdo, $parteData, $clientId);
echo "✓ Sync retroativo: $campos campo(s) preenchido(s) no cliente\n";

audit_log('parte_vincular_manual', 'case_parte', $parteId, "vinculada a client#$clientId + sync");

echo "\n=== DEPOIS ===\n";
$c2 = $pdo->query("SELECT id, name, cpf, birth_date, profession, marital_status, phone, address_street, address_city, address_state FROM clients WHERE id = $clientId")->fetch(PDO::FETCH_ASSOC);
print_r($c2);
