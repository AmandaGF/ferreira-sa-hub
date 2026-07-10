<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== DIAG ranking clientes por periodo ===\n";
echo "Hoje: " . date('Y-m-d H:i:s') . "\n\n";

$periodos = array(
    'mensal (mes atual)'      => date('Y-m-01 00:00:00'),
    'anual (ano atual)'       => date('Y-01-01 00:00:00'),
    'geral (desde 2020)'      => '2020-01-01 00:00:00',
);

foreach ($periodos as $lbl => $dtRef) {
    echo "== $lbl (>= $dtRef) ==\n";

    // 1. Mensagens WA
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                              WHERE m.direcao='recebida' AND m.created_at >= ? AND co.client_id IS NOT NULL AND co.client_id > 0");
        $st->execute(array($dtRef));
        echo "  msg WA recebidas: " . $st->fetchColumn() . "\n";
    } catch (Exception $e) { echo "  msg WA: ERRO " . $e->getMessage() . "\n"; }

    // 2. Tickets
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE created_at >= ? AND client_id IS NOT NULL AND client_id > 0");
        $st->execute(array($dtRef));
        echo "  tickets: " . $st->fetchColumn() . "\n";
    } catch (Exception $e) { echo "  tickets: ERRO " . $e->getMessage() . "\n"; }

    // 3. Salavip threads
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM salavip_threads WHERE criado_em >= ? AND cliente_id IS NOT NULL AND cliente_id > 0");
        $st->execute(array($dtRef));
        echo "  salavip threads: " . $st->fetchColumn() . "\n";
    } catch (Exception $e) { echo "  salavip threads: ERRO " . $e->getMessage() . "\n"; }

    // 4. Balcões
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM agenda_eventos WHERE tipo='balcao_virtual' AND status='realizado' AND data_inicio >= ? AND client_id IS NOT NULL AND client_id > 0");
        $st->execute(array($dtRef));
        echo "  balcao virtual: " . $st->fetchColumn() . "\n";
    } catch (Exception $e) { echo "  balcao virtual: ERRO " . $e->getMessage() . "\n"; }

    // Clientes distintos com pelo menos 1 msg
    try {
        $st = $pdo->prepare("SELECT COUNT(DISTINCT co.client_id) FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                              WHERE m.direcao='recebida' AND m.created_at >= ? AND co.client_id IS NOT NULL AND co.client_id > 0");
        $st->execute(array($dtRef));
        echo "  clientes distintos c/ msg: " . $st->fetchColumn() . "\n";
    } catch (Exception $e) {}

    echo "\n";
}

// Ver o range de datas de mensagens pra confirmar quando comecou
echo "== Range de datas em zapi_mensagens (com client_id na conversa) ==\n";
$st = $pdo->query("SELECT MIN(m.created_at) mn, MAX(m.created_at) mx, COUNT(*) c FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id WHERE m.direcao='recebida' AND co.client_id > 0");
$r = $st->fetch(PDO::FETCH_ASSOC);
echo "  Min: $r[mn]  |  Max: $r[mx]  |  Total: $r[c]\n";

echo "\n== Range de datas em salavip_threads ==\n";
$st = $pdo->query("SELECT MIN(criado_em) mn, MAX(criado_em) mx, COUNT(*) c FROM salavip_threads WHERE cliente_id > 0");
$r = $st->fetch(PDO::FETCH_ASSOC);
echo "  Min: $r[mn]  |  Max: $r[mx]  |  Total: $r[c]\n";
