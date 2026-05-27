<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/asaas_helper.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$cpf = '167.237.387-56';
$cpfDg = '16723738756';

echo "=== Investigando CPF $cpf (Vanissia x Zilma) ===\n\n";

// 1) Form submission original
echo "--- 1) form_submissions vinculadas a client#2391 ---\n";
$st = $pdo->prepare("SELECT id, form_type, client_name, client_phone, payload_json, created_at FROM form_submissions WHERE linked_client_id = 2391 ORDER BY created_at DESC");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fs) {
    echo "  form#{$fs['id']} ({$fs['form_type']})  em " . $fs['created_at'] . "\n";
    echo "     client_name='{$fs['client_name']}'  phone='{$fs['client_phone']}'\n";
    $p = json_decode($fs['payload_json'], true);
    if (is_array($p)) {
        // mostra so campos importantes (nome, cpf, etc)
        foreach (array('nome','name','cpf','rg','nascimento','birth_date','email','telefone','phone','parentesco','responsavel','representante','filho','filhos') as $k) {
            if (isset($p[$k]) && $p[$k] !== '') echo "       $k = " . $p[$k] . "\n";
        }
        // procura qualquer mencao a vanissia/zilma no payload
        foreach ($p as $k => $v) {
            if (is_string($v) && (stripos($v, 'vaniss') !== false || stripos($v, 'zilma') !== false)) {
                echo "       [match] $k = $v\n";
            }
        }
    }
    echo "\n";
}

// 2) Outras tabelas com esse CPF
echo "--- 2) Esse CPF aparece em pipeline_leads, cases, partes? ---\n";
try {
    $st = $pdo->prepare("SELECT id, name, cpf FROM pipeline_leads WHERE cpf = ? OR cpf = ?");
    $st->execute(array($cpf, $cpfDg));
    foreach ($st->fetchAll() as $r) echo "  pipeline_leads#{$r['id']}  name='{$r['name']}'  cpf='{$r['cpf']}'\n";
} catch (Throwable $e) {}
try {
    $st = $pdo->prepare("SELECT id, name FROM clients WHERE REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','') = ?");
    $st->execute(array($cpfDg));
    foreach ($st->fetchAll() as $r) echo "  clients#{$r['id']}  name='{$r['name']}'\n";
} catch (Throwable $e) {}
try {
    $st = $pdo->prepare("SELECT id, case_id, papel, nome, cpf FROM case_partes WHERE REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','') = ?");
    $st->execute(array($cpfDg));
    foreach ($st->fetchAll() as $r) echo "  case_partes#{$r['id']}  case={$r['case_id']}  papel={$r['papel']}  nome='{$r['nome']}'  cpf='{$r['cpf']}'\n";
} catch (Throwable $e) {}

// 3) Asaas — busca por CPF (Asaas valida CPF na Receita)
echo "\n--- 3) Asaas: customers com este CPF ---\n";
$resp = asaas_request('GET', '/customers?cpfCnpj=' . $cpfDg);
if ($resp && !empty($resp['data'])) {
    foreach ($resp['data'] as $c) {
        echo "  asaas customer={$c['id']}  name='" . ($c['name'] ?? '') . "'  email='" . ($c['email'] ?? '') . "'  phone='" . ($c['phone'] ?? '') . "'\n";
    }
} else {
    echo "  (sem customer no Asaas com esse CPF" . (isset($resp['error']) ? ' — erro: ' . $resp['error'] : '') . ")\n";
}

// 4) Procura por nomes Vanissia em form_submissions (talvez tenha outro form dela)
echo "\n--- 4) Outras form_submissions onde aparece 'Vanissia' ---\n";
try {
    $st = $pdo->prepare("SELECT id, form_type, client_name, client_phone, payload_json, created_at, linked_client_id FROM form_submissions WHERE client_name LIKE '%Vaniss%' OR payload_json LIKE '%Vaniss%' OR payload_json LIKE '%vaniss%' ORDER BY created_at DESC LIMIT 5");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fs) {
        echo "  form#{$fs['id']} ({$fs['form_type']})  em " . $fs['created_at'] . "  -> client#" . ($fs['linked_client_id'] ?: 'NULL') . "\n";
        echo "     client_name='{$fs['client_name']}'\n";
        // Tenta achar CPF no payload
        $p = json_decode($fs['payload_json'], true);
        if (is_array($p)) {
            foreach ($p as $k => $v) {
                if (is_string($v) && preg_match('/(\d{3}[.\s]?\d{3}[.\s]?\d{3}[-.\s]?\d{2})/', $v, $m)) {
                    echo "       CPF detectado em '$k': " . $m[1] . "\n";
                }
            }
        }
    }
} catch (Throwable $e) { echo "  erro: " . $e->getMessage() . "\n"; }
