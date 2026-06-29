<?php
/**
 * Diag temporário — Sarah Cristina Ribeiro
 * Procura cliente + cobranças Asaas + registro honorarios_cobranca.
 * Uso: GET /diag_sarah.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Sarah — diag completo ===\n\n";

$sts = $pdo->prepare("SELECT id, name, cpf, phone FROM clients WHERE name LIKE ? ORDER BY name");
$sts->execute(array('%Sarah%Ribeiro%'));
$clientes = $sts->fetchAll(PDO::FETCH_ASSOC);
if (!$clientes) { echo "Nenhuma cliente com nome contendo 'Sarah' + 'Ribeiro'.\n"; exit; }

foreach ($clientes as $cl) {
    echo "▸ Cliente #{$cl['id']}: {$cl['name']} | CPF {$cl['cpf']} | Tel {$cl['phone']}\n";

    // Cobranças no Asaas
    $st = $pdo->prepare("SELECT id, asaas_payment_id, valor, vencimento, status, case_id FROM asaas_cobrancas WHERE client_id = ? ORDER BY vencimento DESC");
    $st->execute(array($cl['id']));
    $cobs = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "   📋 Cobranças Asaas: " . count($cobs) . "\n";
    foreach ($cobs as $c) {
        echo "      #{$c['id']} | R$ {$c['valor']} | venc {$c['vencimento']} | {$c['status']} | case={$c['case_id']} | Asaas={$c['asaas_payment_id']}\n";
    }

    // Já no Kanban de Cobrança de Honorários?
    $st = $pdo->prepare("SELECT id, valor_total, status, vencimento, case_id FROM honorarios_cobranca WHERE client_id = ? ORDER BY id DESC");
    $st->execute(array($cl['id']));
    $hcs = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "   ⚖️ Kanban Cobrança: " . count($hcs) . "\n";
    foreach ($hcs as $h) {
        echo "      #{$h['id']} | R$ {$h['valor_total']} | {$h['status']} | venc {$h['vencimento']} | case={$h['case_id']}\n";
    }

    // Cases (pra ver se tem processo vinculado mesmo sem cobrança)
    $st = $pdo->prepare("SELECT id, title, case_number, status FROM cases WHERE client_id = ? ORDER BY id DESC LIMIT 5");
    $st->execute(array($cl['id']));
    $cases = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "   ⚖️ Processos: " . count($cases) . "\n";
    foreach ($cases as $cs) {
        echo "      #{$cs['id']} | {$cs['title']} | {$cs['case_number']} | {$cs['status']}\n";
    }
    echo "\n";
}
