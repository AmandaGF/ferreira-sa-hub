<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DESFAZER mescla errada Sharlon ===\n\n";

// A msg 3043 veio do canal 24 (era da conv #55 que deletei). Telefone: 5512997688947
// Preciso: criar nova conv canal 24 com esse tel, mover msg 3043 pra lá

// Pega ID da instância canal 24
$inst24 = $pdo->query("SELECT id FROM zapi_instancias WHERE ddd = '24' LIMIT 1")->fetchColumn();
if (!$inst24) { echo "Instância 24 não encontrada\n"; exit; }
echo "Instância 24: #{$inst24}\n";

// Verifica se msg 3043 ainda tá na conv #2
$msg = $pdo->query("SELECT * FROM zapi_mensagens WHERE id = 3043")->fetch();
if (!$msg) { echo "msg #3043 nao existe\n"; exit; }
echo "msg #3043 atualmente em conv #{$msg['conversa_id']} — conteudo: " . substr($msg['conteudo'], 0, 80) . "\n\n";

// Checa se já existe alguma outra conv canal 24 com esse tel (pode ter sido recriada)
$existe = $pdo->query("SELECT id FROM zapi_conversas WHERE canal = '24' AND telefone = '5512997688947' LIMIT 1")->fetch();
if ($existe) {
    echo "Já existe conv canal 24 #{$existe['id']} pra esse tel — movendo msg 3043 pra lá\n";
    $novaId = (int)$existe['id'];
} else {
    // Cria nova conv canal 24
    $pdo->prepare("INSERT INTO zapi_conversas (instancia_id, telefone, nome_contato, atendente_id, status, canal, ultima_mensagem, ultima_msg_em, created_at)
        VALUES (?, '5512997688947', 'sharlon moura', 1, 'em_atendimento', '24', ?, ?, NOW())")
        ->execute(array($inst24, substr($msg['conteudo'], 0, 500), $msg['created_at']));
    $novaId = (int)$pdo->lastInsertId();
    echo "Criada conv nova #{$novaId} canal 24 'sharlon moura'\n";
}

// Move msg 3043 pra nova conv
$pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE id = 3043")->execute(array($novaId));
echo "✓ Msg #3043 movida pra conv #{$novaId}\n";

// Atualiza ultima_msg_em da nova conv
$pdo->prepare("UPDATE zapi_conversas SET ultima_msg_em = (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = ?),
    ultima_mensagem = (SELECT LEFT(conteudo, 500) FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 1)
    WHERE id = ?")->execute(array($novaId, $novaId, $novaId));

// Atualiza conv #2 também (última msg)
$pdo->prepare("UPDATE zapi_conversas SET ultima_msg_em = (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = 2),
    ultima_mensagem = (SELECT LEFT(conteudo, 500) FROM zapi_mensagens WHERE conversa_id = 2 ORDER BY id DESC LIMIT 1)
    WHERE id = 2")->execute();

echo "\n✓ Convs ajustadas — conv #2 (canal 21) tem só seu histórico original, conv #{$novaId} (canal 24) tem a msg que veio do CX\n";
