<?php
/**
 * reconciliar_wa_lid.php — detecta e mescla conversas WhatsApp duplicadas por @lid
 *
 * Fluxo:
 *  - Pra cada conversa com telefone @lid e chat_lid vazio, preenche chat_lid = telefone
 *  - Encontra pares (convA com chat_lid=X, convB com chat_lid=X) onde uma tem número
 *    real e outra só @lid, ou duas têm só @lid idênticos → mescla na MAIS ANTIGA
 *  - Modo ?dry=1 só mostra o que faria (padrão), ?dry=0 executa
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();
$dry = ($_GET['dry'] ?? '1') !== '0';

echo "=== RECONCILIAR conversas WhatsApp duplicadas por @lid ===\n";
echo "Modo: " . ($dry ? 'DRY-RUN (só mostra)' : 'EXECUTAR (mescla de verdade)') . "\n\n";

// 1) Preenche chat_lid = telefone nas conversas que tem @lid no telefone mas chat_lid NULL
echo "--- 1) Preenchendo chat_lid faltante ---\n";
$stmt = $pdo->query(
    "SELECT id, canal, telefone, nome_contato FROM zapi_conversas
     WHERE telefone LIKE '%@lid'
       AND (chat_lid IS NULL OR chat_lid = '')
       AND (eh_grupo = 0 OR eh_grupo IS NULL)"
);
$rows = $stmt->fetchAll();
echo "  Conversas a atualizar: " . count($rows) . "\n";
if (!$dry) {
    foreach ($rows as $r) {
        $pdo->prepare("UPDATE zapi_conversas SET chat_lid = ? WHERE id = ?")
            ->execute(array($r['telefone'], $r['id']));
    }
    echo "  OK — chat_lid preenchido.\n\n";
} else {
    foreach ($rows as $r) echo "  - #{$r['id']} ({$r['canal']}) tel={$r['telefone']} nome={$r['nome_contato']}\n";
    echo "\n";
}

// 2) Acha duplicatas: conversas que compartilham chat_lid
echo "--- 2) Detectando duplicatas por chat_lid ---\n";
$dups = $pdo->query(
    "SELECT chat_lid, canal, COUNT(*) qt, GROUP_CONCAT(id ORDER BY id) ids
     FROM zapi_conversas
     WHERE chat_lid IS NOT NULL AND chat_lid != ''
       AND (eh_grupo = 0 OR eh_grupo IS NULL)
     GROUP BY canal, chat_lid
     HAVING qt > 1"
)->fetchAll();

echo "  Grupos duplicados: " . count($dups) . "\n\n";

foreach ($dups as $d) {
    $ids = array_map('intval', explode(',', $d['ids']));
    echo "  chat_lid={$d['chat_lid']} canal={$d['canal']} → conversas: " . implode(', ', $ids) . "\n";

    // Estratégia: escolher a "principal" — preferência:
    //   a) a que tem telefone com número real (não @lid)
    //   b) a mais antiga (menor ID)
    $detalhes = $pdo->prepare(
        "SELECT id, telefone, nome_contato, client_id, created_at
         FROM zapi_conversas WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"
    );
    $detalhes->execute($ids);
    $convs = $detalhes->fetchAll();

    $principal = null;
    foreach ($convs as $c) {
        $temLid = strpos($c['telefone'], '@lid') !== false;
        if (!$temLid) { $principal = $c; break; }
    }
    if (!$principal) {
        usort($convs, function($a, $b) { return (int)$a['id'] - (int)$b['id']; });
        $principal = $convs[0];
    }
    echo "    → principal: #{$principal['id']} (tel={$principal['telefone']} cli={$principal['client_id']})\n";

    foreach ($convs as $c) {
        if ((int)$c['id'] === (int)$principal['id']) continue;
        echo "    ← mesclar #{$c['id']} (tel={$c['telefone']}) na #{$principal['id']}\n";

        $nMsgs = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = " . (int)$c['id'])->fetchColumn();
        echo "       ({$nMsgs} mensagens a migrar)\n";

        if (!$dry) {
            try {
                $pdo->beginTransaction();
                // Migra mensagens
                $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?")
                    ->execute(array($principal['id'], $c['id']));
                // Migra etiquetas (ignora duplicata)
                try {
                    $pdo->prepare("UPDATE IGNORE zapi_conversa_etiquetas SET conversa_id = ? WHERE conversa_id = ?")
                        ->execute(array($principal['id'], $c['id']));
                    $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ?")
                        ->execute(array($c['id']));
                } catch (Exception $eE) {}
                // Se a principal não tem client_id e a duplicata tem, usa o da duplicata
                if (empty($principal['client_id']) && !empty($c['client_id'])) {
                    $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")
                        ->execute(array($c['client_id'], $principal['id']));
                }
                // Apaga a conversa duplicada
                $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($c['id']));
                // Atualiza última_msg_em da principal
                $pdo->prepare(
                    "UPDATE zapi_conversas SET ultima_msg_em = (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = ?) WHERE id = ?"
                )->execute(array($principal['id'], $principal['id']));
                $pdo->commit();
                echo "       ✓ mesclado\n";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "       ✗ erro: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "\n";
}

echo "=== FIM ===\n";
if ($dry) echo "\nPara executar de verdade: adicione &dry=0 na URL\n";
