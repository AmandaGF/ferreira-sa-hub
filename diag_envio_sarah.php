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

echo "=== Conversas com 'arah' no nome ===\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM zapi_conversas")->fetchAll(PDO::FETCH_COLUMN);
    echo "  cols zapi_conversas: " . implode(', ', $cols) . "\n\n";
    $st = $pdo->query("SELECT * FROM zapi_conversas WHERE nome_contato LIKE '%arah%' ORDER BY id DESC LIMIT 10");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  conv#" . $r['id'] . " — " . ($r['nome_contato'] ?? '?') . "  tel=" . ($r['telefone'] ?? '?') . "  canal=" . ($r['canal_numero'] ?? '?') . "\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== Mensagens enviadas pela user_id=1 (Amanda) nas ultimas 4h ===\n";
try {
    $st = $pdo->query("SELECT m.id, m.created_at, m.conversa_id, m.zapi_message_id, m.tipo, m.status,
                              SUBSTRING(m.conteudo, 1, 80) AS preview, c.nome_contato, c.telefone
                       FROM zapi_mensagens m
                       LEFT JOIN zapi_conversas c ON c.id = m.conversa_id
                       WHERE m.direcao = 'enviada' AND m.enviado_por_id = 1
                         AND m.created_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
                       ORDER BY m.id DESC LIMIT 15");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  #{$r['id']}  {$r['created_at']}  conv#{$r['conversa_id']} ({$r['nome_contato']}/{$r['telefone']})  status={$r['status']}\n";
        echo "    zapi_id: " . ($r['zapi_message_id'] ?: '(VAZIO!)') . "\n";
        echo "    " . $r['preview'] . "\n\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== Detalhe da conv 159 (Sarah que recebeu mensagem) ===\n";
try {
    $st = $pdo->query("SELECT * FROM zapi_conversas WHERE id = 159");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach ($r as $k => $v) {
            if ($v === null || $v === '') continue;
            $vs = is_string($v) ? substr($v, 0, 80) : (string)$v;
            echo "    $k: $vs\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== Detalhe COMPLETO da msg #8833 (a que Amanda mandou Sarah) ===\n";
try {
    $st = $pdo->query("SELECT * FROM zapi_mensagens WHERE id = 8833");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach ($r as $k => $v) {
            if ($v === null || $v === '') continue;
            $vs = is_string($v) ? substr($v, 0, 200) : (string)$v;
            echo "    $k: $vs\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== Status Z-API (cols reais) ===\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM zapi_instancias")->fetchAll(PDO::FETCH_COLUMN);
    echo "  cols: " . implode(', ', $cols) . "\n";
    foreach ($pdo->query("SELECT * FROM zapi_instancias")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  --\n";
        foreach ($r as $k => $v) {
            if ($k === 'token' || $k === 'client_token') { $v = substr((string)$v, 0, 8) . '...'; }
            if ($v === null || $v === '') continue;
            echo "    $k: " . (is_string($v) ? substr($v, 0, 80) : $v) . "\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== Erros recentes em /files/ logs ===\n";
foreach (glob(__DIR__ . '/files/zapi*.log') as $log) {
    echo "  arquivo: $log (" . filesize($log) . " bytes)\n";
    $tail = file($log);
    $ult = array_slice($tail, -10);
    foreach ($ult as $l) echo "    " . trim($l) . "\n";
}
