<?php
/** Diag: reproduz o caminho de dados do R$ Cobrar (cliente.php from_lead) p/ achar o 500. Apagar depois. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
error_reporting(E_ALL); ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/asaas_helper.php';
$pdo = db();

// Lead específico (?lead=ID) ou varre os últimos leads convertidos com cliente
$leadParam = (int)($_GET['lead'] ?? 0);
if ($leadParam) {
    $leads = $pdo->query("SELECT id, client_id FROM pipeline_leads WHERE id = $leadParam")->fetchAll();
} else {
    $leads = $pdo->query("SELECT id, client_id FROM pipeline_leads WHERE client_id IS NOT NULL AND converted_at IS NOT NULL ORDER BY converted_at DESC LIMIT 8")->fetchAll();
}

foreach ($leads as $L) {
    $clientId = (int)$L['client_id'];
    echo "== lead #{$L['id']} → client #$clientId ==\n";
    if (!$clientId) { echo "  (sem cliente)\n"; continue; }
    try {
        // cobranças (branch else — from_lead não tem from_case)
        $st = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE client_id = ? ORDER BY vencimento DESC");
        $st->execute(array($clientId));
        $cobrancas = $st->fetchAll();
        echo "  cobrancas: " . count($cobrancas) . "\n";

        // processos + procNome + cobExtrasMap (bloco novo do combo)
        $sp = $pdo->prepare("SELECT id, title, case_number, status FROM cases WHERE client_id = ? ORDER BY created_at DESC");
        $sp->execute(array($clientId));
        $processosCliente = $sp->fetchAll();
        $procNome = array();
        foreach ($processosCliente as $pr) { $procNome[(int)$pr['id']] = $pr['title'] ?: ('Processo #' . $pr['id']); }
        $cobExtrasMap = array();
        if (!empty($cobrancas)) {
            $idsCob = array_map(function($c){ return (int)$c['id']; }, $cobrancas);
            $inCob = implode(',', array_fill(0, count($idsCob), '?'));
            $stEx = $pdo->prepare("SELECT cobranca_id, case_id FROM asaas_cobranca_cases WHERE cobranca_id IN ($inCob)");
            $stEx->execute($idsCob);
            foreach ($stEx->fetchAll() as $r) { $cobExtrasMap[(int)$r['cobranca_id']][] = (int)$r['case_id']; }
        }
        echo "  processos: " . count($processosCliente) . " | combos: " . count($cobExtrasMap) . "\n";
        echo "  OK\n";
    } catch (Throwable $e) {
        echo "  >>> ERRO: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
echo "\n(nota: este diag NAO chama o sync Asaas pra nao depender da API externa)\n";
echo "Se tudo OK aqui, o 500 provavelmente e o sync_cobrancas_cliente (Asaas) na linha 61 do cliente.php.\n";
