<?php
/**
 * One-shot 11/05/2026 — atualiza telefone da conv #1146 (Ailanda) de
 * 25301820162246 (ID Multi-Device — Z-API aceitava mas nao entregava no
 * celular) pro numero real 5522992833288.
 *
 * Tambem registra o ID antigo como alias da #1146 pra impedir o webhook
 * de recriar conv duplicada se aparecer mensagem do Multi-Device.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/corrigir_tel_ailanda.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

$CONV_ID = 1146;
$TEL_NOVO = '5522992833288';
$TEL_ANTIGO = '25301820162246';

$conv = $pdo->prepare("SELECT id, telefone, nome_contato FROM zapi_conversas WHERE id = ?");
$conv->execute(array($CONV_ID));
$c = $conv->fetch();
if (!$c) { echo "ERRO: conv #{$CONV_ID} nao encontrada.\n"; exit; }

echo "Antes:\n";
echo "  Conv #{$c['id']} ({$c['nome_contato']}) — telefone={$c['telefone']}\n\n";

if ($c['telefone'] !== $TEL_ANTIGO) {
    echo "AVISO: telefone atual ({$c['telefone']}) nao bate com o esperado ({$TEL_ANTIGO}). Abortando pra seguranca.\n";
    exit;
}

// Checa se ja existe outra conv com o novo numero
$dup = $pdo->prepare("SELECT id FROM zapi_conversas WHERE telefone = ? AND id != ? LIMIT 1");
$dup->execute(array($TEL_NOVO, $CONV_ID));
$d = $dup->fetch();
if ($d) {
    echo "ERRO: ja existe conv #{$d['id']} com telefone={$TEL_NOVO}. Abortando — precisa mesclar manualmente.\n";
    exit;
}

try {
    $pdo->beginTransaction();

    // 1) Atualiza o telefone da conv
    $pdo->prepare("UPDATE zapi_conversas SET telefone = ? WHERE id = ?")
        ->execute(array($TEL_NOVO, $CONV_ID));

    // 2) Registra o ID antigo como alias (impede webhook de recriar conv se
    //    chegar msg do Multi-Device antigo)
    $pdo->prepare("INSERT IGNORE INTO zapi_conversa_alias (alias_telefone, conversa_id)
                   VALUES (?, ?)")
        ->execute(array($TEL_ANTIGO, $CONV_ID));

    $pdo->commit();
    echo "✓ Atualizado com sucesso.\n\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERRO: " . $e->getMessage() . "\n";
    exit;
}

$conv->execute(array($CONV_ID));
$c2 = $conv->fetch();
echo "Depois:\n";
echo "  Conv #{$c2['id']} ({$c2['nome_contato']}) — telefone={$c2['telefone']}\n";

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversa_alias WHERE conversa_id = {$CONV_ID}")->fetchColumn();
echo "  Aliases registrados pra essa conv: {$cnt}\n";

echo "\nProximo passo: avisa Naiara/Luiz pra mandar a msg de volta. Agora deve chegar.\n";
