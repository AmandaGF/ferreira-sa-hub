<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Estrutura zapi_mensagens (colunas) ===\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM zapi_mensagens")->fetchAll(PDO::FETCH_COLUMN);
    echo "  " . implode(', ', $cols) . "\n\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "=== Mensagens RECENTES (ultimas 60 min) ===\n";
try {
    $st = $pdo->query("SELECT * FROM zapi_mensagens WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE) ORDER BY id DESC LIMIT 15");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  #{$r['id']}  {$r['created_at']}\n";
        foreach ($r as $k => $v) {
            if ($v === null || $v === '') continue;
            $vs = is_string($v) ? substr($v, 0, 80) : (string)$v;
            echo "    $k: $vs\n";
        }
        echo "\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "=== Procurando 'sarah' / 'Sarah' nas conversas ===\n";
$st = $pdo->prepare("
    SELECT id, telefone, nome_contato, canal_numero, ultima_msg_em, status_atendimento
    FROM zapi_conversas
    WHERE nome_contato LIKE '%arah%'
    ORDER BY ultima_msg_em DESC LIMIT 10
");
$st->execute();
foreach ($st->fetchAll() as $r) {
    echo "  conv#{$r['id']}  {$r['nome_contato']}  ({$r['telefone']})  canal={$r['canal_numero']}  ultima_msg_em={$r['ultima_msg_em']}  status={$r['status_atendimento']}\n";
}

echo "\n=== Status Z-API (instâncias) ===\n";
try {
    $ins = $pdo->query("SELECT canal, instance_id, status, ultima_check FROM zapi_instancias")->fetchAll();
    foreach ($ins as $r) {
        echo "  canal={$r['canal']}  instance_id={$r['instance_id']}  status={$r['status']}  ultima_check={$r['ultima_check']}\n";
    }
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }

echo "\n=== Webhook log das últimas 30 min ===\n";
try {
    $st = $pdo->query("SELECT id, created_at, tipo_evento, sucesso, erro_msg
                       FROM zapi_webhook_log
                       WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                       ORDER BY id DESC LIMIT 10");
    foreach ($st->fetchAll() as $r) {
        echo "  #{$r['id']}  {$r['created_at']}  evento={$r['tipo_evento']}  sucesso=" . ($r['sucesso'] ? 'SIM' : 'NAO') . "  " . ($r['erro_msg'] ?: '') . "\n";
    }
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }
