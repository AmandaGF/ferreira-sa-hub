<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== CONV #2 (canal 21) sharlon moura ===\n";
$r = $pdo->query("SELECT id, created_at, direcao, enviado_por_id, tipo, LEFT(conteudo, 80) AS previa FROM zapi_mensagens WHERE conversa_id = 2 ORDER BY id ASC")->fetchAll();
foreach ($r as $m) {
    echo sprintf("  #%d %s [%s] user=%s [%s] %s\n", $m['id'], $m['created_at'], $m['direcao'], $m['enviado_por_id'] ?: '-', $m['tipo'], trim($m['previa']));
}

echo "\n=== CONV #55 (canal 24) ? ===\n";
$r = $pdo->query("SELECT id, created_at, direcao, enviado_por_id, tipo, LEFT(conteudo, 80) AS previa FROM zapi_mensagens WHERE conversa_id = 55 ORDER BY id ASC")->fetchAll();
foreach ($r as $m) {
    echo sprintf("  #%d %s [%s] user=%s [%s] %s\n", $m['id'], $m['created_at'], $m['direcao'], $m['enviado_por_id'] ?: '-', $m['tipo'], trim($m['previa']));
}
