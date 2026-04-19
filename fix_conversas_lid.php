<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Mesclar conversas LID com conversas reais ===\n\n";

// Achar conversas com phone contendo números longos (prováveis LIDs)
$lidConvs = $pdo->query("
    SELECT id, canal, telefone, nome_contato, ultima_mensagem, ultima_msg_em
    FROM zapi_conversas
    WHERE telefone REGEXP '^[0-9]{15,}$' OR telefone LIKE '%@lid%'
    ORDER BY id DESC
")->fetchAll();

echo "Conversas LID encontradas: " . count($lidConvs) . "\n\n";

$mescladas = 0; $orfanas = 0;
foreach ($lidConvs as $lid) {
    // Buscar conversa real (por nome_contato, mesmo canal) que não seja LID
    if (!$lid['nome_contato']) {
        echo "  #{$lid['id']} tel={$lid['telefone']} SEM NOME — pulando\n";
        continue;
    }
    $real = $pdo->prepare("
        SELECT id, telefone FROM zapi_conversas
        WHERE canal = ? AND nome_contato = ? AND id != ?
          AND telefone NOT REGEXP '^[0-9]{15,}$' AND telefone NOT LIKE '%@lid%'
        ORDER BY ultima_msg_em DESC LIMIT 1
    ");
    $real->execute(array($lid['canal'], $lid['nome_contato'], $lid['id']));
    $convReal = $real->fetch();

    if (!$convReal) {
        echo "  #{$lid['id']} '{$lid['nome_contato']}' → ÓRFÃ (sem match real)\n";
        $orfanas++;
        continue;
    }

    // Mover mensagens da LID pra conversa real
    $upd = $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?");
    $upd->execute(array($convReal['id'], $lid['id']));
    $qtdMovidas = $upd->rowCount();

    // Atualizar última mensagem da real
    $updReal = $pdo->prepare("
        UPDATE zapi_conversas SET
            ultima_mensagem = (SELECT conteudo FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 1),
            ultima_msg_em   = (SELECT created_at FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 1)
        WHERE id = ?
    ");
    $updReal->execute(array($convReal['id'], $convReal['id'], $convReal['id']));

    // Deletar conversa LID (agora vazia)
    $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($lid['id']));

    echo "  ✓ #{$lid['id']} '{$lid['nome_contato']}' → mesclada em #{$convReal['id']} ({$convReal['telefone']}), {$qtdMovidas} msgs movidas\n";
    $mescladas++;
}

echo "\n=== RESUMO ===\n";
echo "Mescladas: {$mescladas}\n";
echo "Órfãs (sem conversa real pra mesclar): {$orfanas}\n";
