<?php
/**
 * Diag específico de UM cliente — investiga por que o detector
 * ainda vê "Sem msg WhatsApp" quando msg foi enviada.
 * Uso: ?key=fsa-hub-deploy-2026&q=livia  (busca pelo nome)
 * Ou:  ?key=fsa-hub-deploy-2026&id=NNN
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Negado.'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$q  = trim((string)($_GET['q'] ?? ''));
$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    $st = $pdo->prepare("SELECT id, name, phone, esfriando_score, esfriando_motivos, esfriando_em FROM clients WHERE id = ?");
    $st->execute(array($id));
    $cliente = $st->fetch(PDO::FETCH_ASSOC);
} else {
    $st = $pdo->prepare("SELECT id, name, phone, esfriando_score, esfriando_motivos, esfriando_em FROM clients WHERE name LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute(array('%' . $q . '%'));
    $cliente = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$cliente) { echo "Cliente não encontrado.\n"; exit; }

echo "=== Cliente #" . $cliente['id'] . ' — ' . $cliente['name'] . " ===\n";
echo "Telefone: " . ($cliente['phone'] ?: '(vazio)') . "\n";
echo "Score atual: " . $cliente['esfriando_score'] . " (calc em " . $cliente['esfriando_em'] . ")\n";
echo "Motivos: " . $cliente['esfriando_motivos'] . "\n\n";

// 1) Conversas VINCULADAS pelo client_id (é o que o detector usa hoje)
echo "1) Conversas zapi VINCULADAS ao client_id (o que o detector vê):\n";
$st = $pdo->prepare(
    "SELECT co.id, co.canal, co.telefone, co.nome_contato, co.ultima_msg_em,
            (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS qtd_msgs,
            (SELECT MAX(m.created_at) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS ultima_msg
     FROM zapi_conversas co WHERE co.client_id = ?"
);
$st->execute(array((int)$cliente['id']));
$convs = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$convs) {
    echo "   NENHUMA. O detector não consegue ver mensagem nenhuma deste cliente.\n";
} else {
    foreach ($convs as $c) {
        echo "   conv #{$c['id']} canal {$c['canal']}  tel={$c['telefone']}  msgs={$c['qtd_msgs']}  ultima={$c['ultima_msg']}\n";
    }
}

// 2) Conversas zapi cujo TELEFONE bate com o do cliente (mesmo sem client_id setado)
echo "\n2) Conversas zapi cujo TELEFONE bate (mesmo sem client_id):\n";
$tel = preg_replace('/\D/', '', (string)$cliente['phone']);
if ($tel === '') {
    echo "   (cliente sem telefone cadastrado)\n";
} else {
    // Procura por dígitos finais (últimos 10) — pega variações com/sem 55, com/sem 9
    $sufix = substr($tel, -10);
    $st = $pdo->prepare(
        "SELECT co.id, co.canal, co.telefone, co.client_id, co.nome_contato,
                (SELECT MAX(m.created_at) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS ultima_msg,
                (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS qtd_msgs
         FROM zapi_conversas co WHERE REPLACE(REPLACE(co.telefone,'+',''),' ','') LIKE ?"
    );
    $st->execute(array('%' . $sufix . '%'));
    $candidatos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$candidatos) {
        echo "   NENHUMA conversa com esse telefone no Hub.\n";
    } else {
        foreach ($candidatos as $c) {
            $marcadores = array();
            if ((int)$c['client_id'] === (int)$cliente['id']) $marcadores[] = '✓ VINCULADA';
            elseif ((int)$c['client_id'] > 0) $marcadores[] = '⚠ VINCULADA A OUTRO CLIENTE #' . $c['client_id'];
            else $marcadores[] = '✕ ÓRFÃ (sem client_id)';
            echo "   conv #{$c['id']} canal {$c['canal']}  tel={$c['telefone']}  msgs={$c['qtd_msgs']}  ultima={$c['ultima_msg']}  " . implode(' ', $marcadores) . "\n";
        }
    }
}

// 3) Mensagens recentes em QUALQUER conversa relacionada (vinculada ou candidata por telefone)
echo "\n3) Últimas 8 mensagens nas conversas acima (qualquer rota):\n";
$st = $pdo->prepare(
    "SELECT m.id, m.created_at, m.direcao, m.tipo, LEFT(m.conteudo, 80) conteudo_curto, co.telefone, co.client_id, co.id AS conv_id
     FROM zapi_mensagens m
     INNER JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE co.client_id = ?
        OR REPLACE(REPLACE(co.telefone,'+',''),' ','') LIKE ?
     ORDER BY m.created_at DESC LIMIT 8"
);
$st->execute(array((int)$cliente['id'], '%' . ($tel ? substr($tel, -10) : 'XXX_NONE') . '%'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $rota = (int)$m['client_id'] === (int)$cliente['id'] ? '✓ via client_id' : '⚠ via telefone só';
    echo "   {$m['created_at']} [{$m['direcao']}] conv#{$m['conv_id']} ({$m['telefone']})  {$m['tipo']}  {$m['conteudo_curto']}  ({$rota})\n";
}

echo "\n=== FIM ===\n";
