<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();

$updates = array(
    array('id'=>629, 'num'=>'5521969891057', 'nome'=>'Cassiane Dias de Souza'),
    array('id'=>610, 'num'=>'5524998731817', 'nome'=>'Douglas Silva'),
    array('id'=>613, 'num'=>'5524993040989', 'nome'=>'Wesley indicação Bianca'),
    array('id'=>668, 'num'=>'5521975384003', 'nome'=>'Pâmela Coelho'),
);

echo "=== Aplicando números reais em 4 convs @lid ===\n\n";

foreach ($updates as $u) {
    $conv = $pdo->query("SELECT * FROM zapi_conversas WHERE id = {$u['id']}")->fetch();
    if (!$conv) { echo "  #{$u['id']} NÃO EXISTE\n"; continue; }
    $telNovo = zapi_normaliza_telefone($u['num']);
    $telAtual = $conv['telefone'];
    $canal = $conv['canal'];

    echo "  #{$u['id']} canal={$canal} nome='{$u['nome']}' tel atual='{$telAtual}' → novo='{$telNovo}'\n";

    // Checa se já existe outra conv com esse número nesse canal (possível duplicata a mesclar)
    $outra = $pdo->prepare("SELECT id, nome_contato,
        (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = zapi_conversas.id) AS msgs
        FROM zapi_conversas
        WHERE canal = ? AND telefone = ? AND id != ? AND (eh_grupo=0 OR eh_grupo IS NULL) LIMIT 1");
    $outra->execute(array($canal, $telNovo, $u['id']));
    $dup = $outra->fetch();

    if ($dup) {
        echo "    ⚠️  Já existe conv #{$dup['id']} '{$dup['nome_contato']}' com esse tel ({$dup['msgs']} msgs) — MESCLANDO\n";
        // Mescla: move msgs da conv @lid (#id) pra conv número real (#dup) OU vice-versa
        // Preferência: principal é a com MAIS msgs OU a que tem nome de pessoa
        $msgsLid = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = {$u['id']}")->fetchColumn();
        $msgsDup = (int)$dup['msgs'];
        $origemId = ($msgsLid <= $msgsDup) ? $u['id'] : (int)$dup['id'];
        $destinoId = ($origemId === $u['id']) ? (int)$dup['id'] : $u['id'];
        echo "    → origem=#{$origemId} destino=#{$destinoId}\n";
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?")->execute(array($destinoId, $origemId));
            try {
                $pdo->prepare("UPDATE IGNORE zapi_conversa_etiquetas SET conversa_id = ? WHERE conversa_id = ?")->execute(array($destinoId, $origemId));
                $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ?")->execute(array($origemId));
            } catch (Exception $e) {}
            $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($origemId));
            // Garante telefone e nome corretos no destino
            $pdo->prepare("UPDATE zapi_conversas SET telefone = ?, nome_contato = ? WHERE id = ?")
                ->execute(array($telNovo, $u['nome'], $destinoId));
            // Atualiza ultima msg
            $pdo->prepare("UPDATE zapi_conversas SET
                ultima_msg_em = (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = ?),
                ultima_mensagem = (SELECT LEFT(conteudo, 500) FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 1)
                WHERE id = ?")->execute(array($destinoId, $destinoId, $destinoId));
            $pdo->commit();
            echo "    ✓ mesclado\n";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "    ✗ erro: " . $e->getMessage() . "\n";
        }
    } else {
        // Sem duplicata: só atualiza o telefone e nome
        try {
            $pdo->prepare("UPDATE zapi_conversas SET telefone = ?, nome_contato = ? WHERE id = ?")
                ->execute(array($telNovo, $u['nome'], $u['id']));
            echo "    ✓ telefone atualizado + nome confirmado\n";
        } catch (Exception $e) {
            echo "    ✗ erro: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

echo "=== FIM ===\n";
