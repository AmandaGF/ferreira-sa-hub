<?php
/**
 * Diag VIP — descobre por que cliente vê só X processos enquanto tem Y cadastrados.
 * ?key=fsa-hub-deploy-2026&cliente=NOME_OU_CPF
 */
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$busca = trim($_GET['cliente'] ?? '');

// Visão GERAL: quantos casos cada cliente tem com salavip_ativo<>1
// (sem filtrar por clients.salavip_acesso_ativo — coluna pode não existir)
echo "=== Visão geral: clientes com casos OCULTOS no VIP (salavip_ativo != 1) ===\n";
try {
    $st = $pdo->query("SELECT cl.id, cl.name, cl.cpf,
                              COUNT(c.id) AS total,
                              SUM(CASE WHEN c.salavip_ativo = 1 THEN 1 ELSE 0 END) AS ativos,
                              SUM(CASE WHEN COALESCE(c.salavip_ativo,0) <> 1 THEN 1 ELSE 0 END) AS ocultos
                       FROM clients cl
                       JOIN cases c ON c.client_id = cl.id
                       GROUP BY cl.id
                       HAVING ocultos > 0
                       ORDER BY ocultos DESC LIMIT 30");
    foreach ($st->fetchAll() as $r) {
        echo "  cliente {$r['id']} | {$r['name']} | CPF {$r['cpf']} | total={$r['total']} | ativos no VIP={$r['ativos']} | OCULTOS={$r['ocultos']}\n";
    }
} catch (Exception $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

if (!$busca) {
    echo "\nUse ?cliente=NOME_OU_CPF pra investigar um cliente específico.\n";
    exit;
}

// Investigar cliente específico
$cpfBusca = preg_replace('/\D/', '', $busca);
$st = $pdo->prepare("SELECT * FROM clients WHERE name LIKE ? OR cpf = ? OR REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','') = ? LIMIT 5");
$st->execute(array('%' . $busca . '%', $busca, $cpfBusca));
$clientes = $st->fetchAll();

echo "\n=== Clientes encontrados pra '{$busca}' ===\n";
foreach ($clientes as $cli) {
    echo "\n--- Cliente {$cli['id']}: {$cli['name']} ---\n";
    echo "  CPF: {$cli['cpf']} · Email: {$cli['email']} · VIP ativo: " . ($cli['salavip_acesso_ativo'] ?? '?') . "\n";

    // Casos onde ele é client_id PRINCIPAL
    $stC = $pdo->prepare("SELECT id, title, case_number, status, salavip_ativo, opened_at FROM cases WHERE client_id = ? ORDER BY id DESC");
    $stC->execute(array($cli['id']));
    $casos = $stC->fetchAll();
    echo "  Casos como CLIENTE PRINCIPAL: " . count($casos) . "\n";
    foreach ($casos as $c) {
        $vipFlag = $c['salavip_ativo'] === '1' || $c['salavip_ativo'] === 1 ? '✓ VIP_ATIVO' : '✕ VIP_INATIVO (oculto)';
        echo "    case {$c['id']} | {$c['title']} | {$c['case_number']} | status={$c['status']} | {$vipFlag}\n";
    }

    // Casos onde ele aparece como PARTE SECUNDÁRIA (case_partes)
    try {
        $stP = $pdo->prepare("SELECT cp.case_id, cp.papel, c.title, c.case_number, c.client_id, c.salavip_ativo
                              FROM case_partes cp
                              JOIN cases c ON c.id = cp.case_id
                              WHERE cp.client_id_vinculado = ? OR cp.cpf = ?");
        $stP->execute(array($cli['id'], $cli['cpf']));
        $partes = $stP->fetchAll();
        if (!empty($partes)) {
            echo "  Casos como PARTE secundária (case_partes): " . count($partes) . "\n";
            foreach ($partes as $p) {
                $vipFlag = $p['salavip_ativo'] === '1' || $p['salavip_ativo'] === 1 ? '✓ VIP_ATIVO' : '✕ VIP_INATIVO';
                echo "    case {$p['case_id']} | papel={$p['papel']} | {$p['title']} | {$vipFlag} | client_principal_id={$p['client_id']}\n";
            }
        } else {
            echo "  Casos como PARTE secundária: 0\n";
        }
    } catch (Exception $e) { echo "  (erro consultando case_partes: " . $e->getMessage() . ")\n"; }

    // O que VIP MOSTRARIA pra ele
    $stV = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE client_id = ? AND salavip_ativo = 1 AND status NOT IN ('cancelado','arquivado')");
    $stV->execute(array($cli['id']));
    $verNoVip = (int)$stV->fetchColumn();
    echo "  ⚠️ Total que aparece pra ele no VIP HOJE: {$verNoVip}\n";
}
