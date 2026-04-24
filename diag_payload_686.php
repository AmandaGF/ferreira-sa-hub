<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== COLUNAS de zapi_mensagens ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM zapi_mensagens")->fetchAll();
foreach ($cols as $c) echo "- {$c['Field']} ({$c['Type']})\n";

echo "\n=== Últimas 10 msgs conv 686 ===\n";
$q = $pdo->prepare("SELECT id, direcao, created_at, SUBSTR(COALESCE(texto,''), 1, 100) AS preview FROM zapi_mensagens WHERE conversa_id=? ORDER BY id DESC LIMIT 10");
$q->execute(array(686));
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} [{$r['direcao']}] {$r['created_at']}: {$r['preview']}\n";
}

echo "\n=== Últimas 10 msgs conv 510 (JOSE) ===\n";
$q = $pdo->prepare("SELECT id, direcao, created_at, SUBSTR(COALESCE(texto,''), 1, 100) AS preview FROM zapi_mensagens WHERE conversa_id=? ORDER BY id DESC LIMIT 10");
$q->execute(array(510));
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} [{$r['direcao']}] {$r['created_at']}: {$r['preview']}\n";
}

echo "\n=== Msg 'Feliz aniversário, JOSE' em qualquer conversa ===\n";
$q = $pdo->query("SELECT id, conversa_id, direcao, created_at, SUBSTR(COALESCE(texto,''), 1, 80) AS preview FROM zapi_mensagens WHERE texto LIKE '%Feliz aniversário, JOSE%' OR texto LIKE '%JOSE HERICKSON%' ORDER BY id DESC LIMIT 10");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} conv={$r['conversa_id']} [{$r['direcao']}] {$r['created_at']}: {$r['preview']}\n";
}

echo "\n=== Msg atualização processo hoje via Alícia ===\n";
$q = $pdo->query("SELECT id, conversa_id, direcao, created_at, SUBSTR(COALESCE(texto,''), 1, 150) AS preview FROM zapi_mensagens WHERE conversa_id = 686 AND direcao = 'enviada' ORDER BY id DESC LIMIT 10");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} conv={$r['conversa_id']} [{$r['direcao']}] {$r['created_at']}: {$r['preview']}\n";
}

echo "\n=== Campos completos da conv 686 ===\n";
$q = $pdo->query("SELECT * FROM zapi_conversas WHERE id = 686");
$r = $q->fetch();
foreach ($r as $k => $v) echo "$k: $v\n";
