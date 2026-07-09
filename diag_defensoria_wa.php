<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "-- Colunas zapi_conversas --\n";
echo implode(', ', $pdo->query("SHOW COLUMNS FROM zapi_conversas")->fetchAll(PDO::FETCH_COLUMN,0)) . "\n\n";

echo "-- Colunas zapi_mensagens --\n";
echo implode(', ', $pdo->query("SHOW COLUMNS FROM zapi_mensagens")->fetchAll(PDO::FETCH_COLUMN,0)) . "\n\n";

echo "-- zapi_conversas com 'defensoria' no nome --\n";
echo "-- Mensagens tipo location ou com 'localiza' no conteudo (10 mais recentes) --\n";
try {
    $st = $pdo->query("SELECT m.id, m.conversa_id, m.tipo, m.direcao, m.conteudo, m.created_at, c.nome_contato, c.telefone
                        FROM zapi_mensagens m LEFT JOIN zapi_conversas c ON c.id = m.conversa_id
                        WHERE m.tipo = 'location' OR m.conteudo LIKE '%localiza%' OR m.conteudo LIKE '%[LOCALIZA%'
                        ORDER BY m.id DESC LIMIT 10");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  msg #{$r['id']} conv #{$r['conversa_id']} ({$r['nome_contato']}) tipo={$r['tipo']} dir={$r['direcao']}\n";
        echo "     conteudo: " . mb_substr((string)$r['conteudo'], 0, 200) . "\n";
        echo "     em: {$r['created_at']}\n---\n";
    }
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

echo "\n-- Conversas cujo ultima_mensagem menciona 'localiza' ou 'defensoria' --\n";
try {
    $st = $pdo->query("SELECT id, canal, telefone, nome_contato, ultima_mensagem, ultima_msg_em
                        FROM zapi_conversas
                        WHERE ultima_mensagem LIKE '%localiza%' OR nome_contato LIKE '%efensor%' OR ultima_mensagem LIKE '%efensor%'
                        ORDER BY ultima_msg_em DESC LIMIT 15");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach ($r as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25).": $v\n";
        echo "---\n";
        // pega ultimas msgs
        $st2 = $pdo->prepare("SELECT * FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 8");
        $st2->execute(array($r['id']));
        echo "  -- ultimas mensagens conversa #{$r['id']} --\n";
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $m) {
            foreach ($m as $k=>$v) if ($v !== null && $v !== '') echo "    " . str_pad($k,22).": $v\n";
            echo "    ---\n";
        }
    }
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }
