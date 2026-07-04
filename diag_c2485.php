<?php
/** Diag: testa vincular+sync Asaas do cliente 2485 (lead 1357) com cronometro. Apagar depois. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
error_reporting(E_ALL); ini_set('display_errors', '1');
set_time_limit(60);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/asaas_helper.php';
$pdo = db();
$cid = (int)($_GET['client'] ?? 2485);

$c = $pdo->query("SELECT id, name, cpf, asaas_customer_id FROM clients WHERE id = $cid")->fetch();
echo "Cliente #$cid: " . ($c['name'] ?? '?') . "\n";
echo "  CPF: " . ($c['cpf'] ?: '(vazio)') . " | len=" . strlen(preg_replace('/\D/', '', (string)($c['cpf'] ?? ''))) . "\n";
echo "  asaas_customer_id: " . ($c['asaas_customer_id'] ?: '(vazio)') . "\n";
$np = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE client_id = $cid")->fetchColumn();
echo "  processos: $np\n\n";

$asaasId = $c['asaas_customer_id'] ?: null;
if (!$asaasId && $c['cpf']) {
    echo "→ Simulando vincular_cliente_asaas($cid) (cria/acha no Asaas)...\n";
    $t0 = microtime(true);
    try {
        $v = vincular_cliente_asaas($cid);
        $ms = round((microtime(true) - $t0) * 1000);
        echo "  resultado: " . json_encode($v) . " ({$ms}ms)\n";
        if (!isset($v['error'])) $asaasId = $v['id'];
    } catch (Throwable $e) {
        echo "  >>> ERRO: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
} else {
    echo "→ Já tem asaas_customer_id (ou sem CPF) — vincular seria pulado.\n";
}

if ($asaasId) {
    echo "\n→ Simulando sync_cobrancas_cliente($cid, $asaasId)...\n";
    $t0 = microtime(true);
    try {
        $s = sync_cobrancas_cliente($cid, $asaasId);
        $ms = round((microtime(true) - $t0) * 1000);
        echo "  resultado: " . json_encode($s) . " ({$ms}ms)\n";
    } catch (Throwable $e) {
        echo "  >>> ERRO: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
echo "\nFIM (se travou/estourou tempo aqui, o 500 e o Asaas na carga da pagina).\n";
