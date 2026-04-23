<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

foreach (array(2, 55) as $cid) {
    echo "=== CONV #{$cid} ===\n";
    $conv = $pdo->query("SELECT canal, telefone, nome_contato, atendente_id, status FROM zapi_conversas WHERE id = {$cid}")->fetch();
    if ($conv) echo "  canal={$conv['canal']} tel={$conv['telefone']} nome={$conv['nome_contato']} atend={$conv['atendente_id']} [{$conv['status']}]\n";
    $r = $pdo->query("SELECT id, created_at, direcao, enviado_por_id, tipo, LEFT(conteudo, 100) AS previa FROM zapi_mensagens WHERE conversa_id = {$cid} ORDER BY id ASC")->fetchAll();
    foreach ($r as $m) {
        echo sprintf("  #%d %s [%s] user=%s [%s] %s\n", $m['id'], $m['created_at'], $m['direcao'], $m['enviado_por_id'] ?: '-', $m['tipo'], trim($m['previa']));
    }
    echo "\n";
}
