<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

$origem = 603;   // "Dudu Sócio" canal 24 chat_lid=83223715561634@lid — NA VERDADE é Luiz
$destino = 97;   // "Luiz Eduardo" canal 24 — conv oficial

echo "=== Corrigir: mover msgs de #{$origem} (Dudu Sócio — nome errado) → #{$destino} (Luiz Eduardo) ===\n\n";

$cO = $pdo->query("SELECT * FROM zapi_conversas WHERE id = {$origem}")->fetch();
$cD = $pdo->query("SELECT * FROM zapi_conversas WHERE id = {$destino}")->fetch();
if (!$cO || !$cD) { echo "conv faltando\n"; exit; }

// Guard: ambas precisam ser do mesmo canal
if ($cO['canal'] !== $cD['canal']) { echo "ERRO: canais diferentes (#{$origem}={$cO['canal']}, #{$destino}={$cD['canal']})\n"; exit; }

echo "Origem  #{$origem}: canal={$cO['canal']} tel={$cO['telefone']} chat_lid={$cO['chat_lid']} nome='{$cO['nome_contato']}' [{$cO['status']}]\n";
echo "Destino #{$destino}: canal={$cD['canal']} tel={$cD['telefone']} chat_lid=" . ($cD['chat_lid'] ?: '(vazio)') . " nome='{$cD['nome_contato']}' [{$cD['status']}]\n\n";

$n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = {$origem}")->fetchColumn();
echo "Mensagens na origem: {$n}\n\n";

try {
    $pdo->beginTransaction();
    // Move msgs
    $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?")->execute(array($destino, $origem));
    // Etiquetas
    try {
        $pdo->prepare("UPDATE IGNORE zapi_conversa_etiquetas SET conversa_id = ? WHERE conversa_id = ?")->execute(array($destino, $origem));
        $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ?")->execute(array($origem));
    } catch (Exception $e) {}
    // Preserva chat_lid da origem no destino (que é o LID real do Luiz)
    if (!empty($cO['chat_lid']) && empty($cD['chat_lid'])) {
        $pdo->prepare("UPDATE zapi_conversas SET chat_lid = ? WHERE id = ?")->execute(array($cO['chat_lid'], $destino));
        echo "✓ chat_lid '{$cO['chat_lid']}' movido pra conv destino\n";
    }
    // Apaga origem
    $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($origem));
    // Atualiza última msg e garante que destino está em_atendimento (não arquivado)
    $pdo->prepare("UPDATE zapi_conversas SET
        ultima_msg_em = (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = ?),
        ultima_mensagem = (SELECT LEFT(conteudo, 500) FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 1),
        status = CASE WHEN status IN ('arquivado') THEN 'em_atendimento' ELSE status END
        WHERE id = ?")
        ->execute(array($destino, $destino, $destino));
    $pdo->commit();
    echo "✓ MESCLA CONCLUÍDA — #{$origem} apagada, {$n} msgs migradas pra #{$destino}\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "✗ ERRO: " . $e->getMessage() . "\n";
}

// Mostra estado final
echo "\n--- Estado final conv #{$destino} ---\n";
$c = $pdo->query("SELECT * FROM zapi_conversas WHERE id = {$destino}")->fetch();
$n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = {$destino}")->fetchColumn();
echo "tel={$c['telefone']} chat_lid={$c['chat_lid']} nome='{$c['nome_contato']}' [{$c['status']}] msgs={$n}\n";
