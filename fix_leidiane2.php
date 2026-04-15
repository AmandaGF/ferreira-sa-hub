<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Atualizar Leidiane #2311 com dados do formulário ===\n\n";

$f = $pdo->query("SELECT payload_json FROM form_submissions WHERE linked_client_id = 2311 ORDER BY created_at DESC LIMIT 1")->fetchColumn();
if (!$f) { die("Nenhum formulário encontrado.\n"); }

$d = json_decode($f, true);
$phone = $d['celular'] ?? '';
$rua = $d['rua'] ?? '';
$num = $d['numero'] ?? '';
$comp = $d['complemento'] ?? '';
$bairro = $d['bairro'] ?? '';
$street = $rua;
if ($num) $street .= ', nº ' . $num;
if ($comp) $street .= ', ' . $comp;
if ($bairro) $street .= ' - ' . $bairro;

echo "Telefone: {$phone}\n";
echo "Endereço: {$street}\n";
echo "Cidade: " . ($d['cidade'] ?? '') . "\n";
echo "UF: " . ($d['uf'] ?? '') . "\n";
echo "CEP: " . ($d['cep'] ?? '') . "\n";
echo "Profissão: " . ($d['profissao'] ?? '') . "\n";
echo "Estado civil: " . ($d['estado_civil'] ?? '') . "\n";
echo "Nascimento: " . ($d['nascimento'] ?? '') . "\n";

$pdo->prepare("UPDATE clients SET phone=?, address_street=?, address_city=?, address_state=?, address_zip=?, profession=?, marital_status=?, birth_date=?, rg=?, pix_key=? WHERE id=2311")
    ->execute(array(
        $phone ?: null,
        $street ?: null,
        $d['cidade'] ?? null,
        $d['uf'] ?? null,
        $d['cep'] ?? null,
        $d['profissao'] ?? null,
        $d['estado_civil'] ?? null,
        ($d['nascimento'] ?? null) ?: null,
        $d['rg'] ?? null,
        $d['pix'] ?? null,
    ));

echo "\n=== ATUALIZADO ===\n";
