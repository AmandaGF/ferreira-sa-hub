<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "-- zapi_conversas com 'defensoria' no nome --\n";
$st = $pdo->query("SELECT id, canal, telefone, nome_contato, ultima_mensagem_texto, ultima_mensagem_em, ultima_mensagem_tipo
                    FROM zapi_conversas
                    WHERE nome_contato LIKE '%efensoria%' OR telefone LIKE '%efensoria%'
                    LIMIT 20");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    print_r($r);
}

echo "\n-- Ultimas mensagens dessa(s) conversa(s) --\n";
$st2 = $pdo->query(
    "SELECT m.id, m.conversa_id, m.tipo, m.direcao, m.texto, m.midia_url, m.location_lat, m.location_lng,
            m.location_titulo, m.location_endereco, m.criado_em
     FROM zapi_mensagens m
     JOIN zapi_conversas c ON c.id = m.conversa_id
     WHERE c.nome_contato LIKE '%efensoria%'
     ORDER BY m.id DESC LIMIT 15"
);
foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    foreach ($r as $k => $v) if ($v !== null && $v !== '') echo str_pad($k,20) . ": $v\n";
    echo "---\n";
}

echo "\n-- Colunas de zapi_mensagens (pra saber quais fields de localizacao existem) --\n";
$cols = $pdo->query("SHOW COLUMNS FROM zapi_mensagens")->fetchAll(PDO::FETCH_COLUMN,0);
echo implode(', ', $cols) . "\n";
