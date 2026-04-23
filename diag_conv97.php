<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG conv Luiz pessoal (5524999816600) — " . date('Y-m-d H:i:s') . " ===\n\n";

// Acha conversa(s) com esse número em ambos canais
$r = $pdo->prepare("SELECT id, canal, nome_contato, telefone, atendente_id, chat_lid, status
    FROM zapi_conversas
    WHERE telefone LIKE '%99981%6600%' OR telefone LIKE '%5524999816600%' OR nome_contato LIKE '%Luiz Eduardo%'
    ORDER BY ultima_msg_em DESC");
$r->execute();
$convs = $r->fetchAll();
echo "--- Conversas que match Luiz pessoal ---\n";
foreach ($convs as $c) {
    echo "  #{$c['id']} canal={$c['canal']} tel={$c['telefone']} chat_lid=" . ($c['chat_lid'] ?: 'NULL') . " nome={$c['nome_contato']} atendente={$c['atendente_id']} [{$c['status']}]\n";
}

if (!$convs) { echo "\nNenhuma conversa encontrada.\n"; exit; }

// Pra cada conversa, listar as últimas 20 mensagens
foreach ($convs as $c) {
    echo "\n--- Mensagens da conv #{$c['id']} (últimas 20) ---\n";
    $q = $pdo->prepare("SELECT id, created_at, direcao, enviado_por_id, tipo, status, zapi_message_id, LEFT(conteudo, 80) AS previa
        FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 20");
    $q->execute(array($c['id']));
    foreach (array_reverse($q->fetchAll()) as $m) {
        $min = (int)((time() - strtotime($m['created_at'])) / 60);
        echo sprintf("  #%d %s (há %dmin) [%s] %s user=%s zid=%s — %s\n",
            $m['id'], $m['created_at'], $min, $m['tipo'], $m['direcao'],
            $m['enviado_por_id'] ?: '-', substr($m['zapi_message_id'] ?: '-', 0, 12), trim($m['previa']));
    }
}

// Log webhook grep pelo chat_lid da conv
foreach ($convs as $c) {
    if (!$c['chat_lid']) continue;
    echo "\n--- Log webhook com chat_lid={$c['chat_lid']} (últimas 15) ---\n";
    $log = __DIR__ . '/files/zapi_webhook.log';
    if (file_exists($log)) {
        $lines = file($log, FILE_IGNORE_NEW_LINES);
        $tail = array_slice($lines, -5000);
        $filtradas = array();
        foreach ($tail as $l) {
            if (strpos($l, $c['chat_lid']) !== false) $filtradas[] = $l;
        }
        $filtradas = array_slice($filtradas, -15);
        foreach ($filtradas as $l) echo "  " . mb_substr($l, 0, 220, 'UTF-8') . "\n";
    }
}
