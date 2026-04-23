<?php
/**
 * reconciliar_wa_tel.php — detecta duplicatas por TELEFONE (canal + últimos 10 dígitos)
 * Útil pra casos onde a conv antiga não tem chat_lid e uma nova foi criada em paralelo.
 *
 * Uso:
 *   ?key=fsa-hub-deploy-2026         → dry-run (mostra)
 *   &dry=0                           → executa mesclagem
 *   &par=ORIGEM:DESTINO              → mescla par específico (ex: &par=603:97)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();
$dry = ($_GET['dry'] ?? '1') !== '0';
$par = $_GET['par'] ?? '';

echo "=== RECONCILIAR conversas WA duplicadas por TELEFONE ===\n";
echo "Modo: " . ($dry ? 'DRY-RUN' : 'EXECUTAR') . "\n\n";

function mesclar_conv($pdo, $origemId, $destinoId, $dry) {
    $cO = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?"); $cO->execute(array($origemId));
    $cD = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?"); $cD->execute(array($destinoId));
    $origem = $cO->fetch(); $destino = $cD->fetch();
    if (!$origem || !$destino) { echo "  ERRO: conv não existe\n"; return; }
    $n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = {$origemId}")->fetchColumn();
    echo "  Origem #{$origemId} ({$origem['nome_contato']}, tel={$origem['telefone']}, chat_lid=" . ($origem['chat_lid'] ?: 'NULL') . ") — {$n} msgs\n";
    echo "  Destino #{$destinoId} ({$destino['nome_contato']}, tel={$destino['telefone']}, atendente={$destino['atendente_id']})\n";
    if ($dry) { echo "  (dry-run, não mesclou)\n"; return; }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?")->execute(array($destinoId, $origemId));
        try {
            $pdo->prepare("UPDATE IGNORE zapi_conversa_etiquetas SET conversa_id = ? WHERE conversa_id = ?")->execute(array($destinoId, $origemId));
            $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ?")->execute(array($origemId));
        } catch (Exception $e) {}
        // Preserva chat_lid da origem no destino (se destino não tem)
        if (!empty($origem['chat_lid']) && empty($destino['chat_lid'])) {
            $pdo->prepare("UPDATE zapi_conversas SET chat_lid = ? WHERE id = ?")->execute(array($origem['chat_lid'], $destinoId));
        }
        // client_id se faltar no destino
        if (empty($destino['client_id']) && !empty($origem['client_id'])) {
            $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")->execute(array($origem['client_id'], $destinoId));
        }
        $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($origemId));
        $pdo->prepare("UPDATE zapi_conversas SET ultima_msg_em = (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = ?) WHERE id = ?")
            ->execute(array($destinoId, $destinoId));
        $pdo->commit();
        echo "  ✓ MESCLADO — {$n} mensagens migradas\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ✗ ERRO: " . $e->getMessage() . "\n";
    }
}

// Modo par específico: ?par=origem:destino
if ($par && preg_match('/^(\d+):(\d+)$/', $par, $m)) {
    echo "--- Mesclando par específico #{$m[1]} → #{$m[2]} ---\n";
    mesclar_conv($pdo, (int)$m[1], (int)$m[2], $dry);
    echo "\n=== FIM ===\n";
    exit;
}

// Modo descoberta: acha conversas canal 21/24 com mesmo telefone (últimos 10 dígitos)
echo "--- Grupos de duplicatas por TELEFONE (últimos 10 dígitos, mesmo canal) ---\n";
$r = $pdo->query("SELECT canal, RIGHT(REPLACE(REPLACE(telefone,'@lid',''),'@g.us',''), 10) AS ult10,
    GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS qt
    FROM zapi_conversas
    WHERE telefone IS NOT NULL AND telefone != ''
      AND (eh_grupo = 0 OR eh_grupo IS NULL)
      AND LENGTH(REPLACE(REPLACE(telefone,'@lid',''),'@g.us','')) >= 10
    GROUP BY canal, ult10
    HAVING qt > 1
    ORDER BY qt DESC");
$grupos = $r->fetchAll();
echo "  Encontrados: " . count($grupos) . " grupos\n\n";
foreach ($grupos as $g) {
    $ids = array_map('intval', explode(',', $g['ids']));
    echo "  canal={$g['canal']} ult10={$g['ult10']} → convs: " . implode(', ', $ids) . "\n";
    foreach ($ids as $id) {
        $c = $pdo->query("SELECT id, nome_contato, telefone, chat_lid, atendente_id, status, ultima_msg_em FROM zapi_conversas WHERE id = {$id}")->fetch();
        $n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = {$id}")->fetchColumn();
        echo "    #{$c['id']} {$c['nome_contato']} tel={$c['telefone']} chat_lid=" . ($c['chat_lid'] ?: '-') . " atend={$c['atendente_id']} [{$c['status']}] msgs={$n} ult={$c['ultima_msg_em']}\n";
    }
    echo "\n";
}

if ($dry) echo "\nPara mesclar par específico: &par=ORIGEM:DESTINO&dry=0\n";
