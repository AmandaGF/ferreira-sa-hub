<?php
/**
 * Diag: por que a trava de atendimento de uma conversa não libera.
 *
 * Mostra:
 *  - conversa (id, canal, atendente_id/name, ultima_msg_em)
 *  - últimas 20 mensagens com direcao, enviado_por_id/name, enviado_por_bot,
 *    created_at e idade — pra ver O QUE reseta o timer das 36h
 *  - o que zapi_pode_enviar_conversa() retorna pra um usuário não-admin
 *  - leitura da regra: qual msg está "segurando" a trava e por quê
 *
 * Acesso: ?key=fsa-hub-deploy-2026&q=Sandra        (nome ou telefone)
 *         &uid=4   (opcional: simula a checagem pra esse user_id)
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$q = trim($_GET['q'] ?? '');
if ($q === '') { exit("Use ?q=Sandra (nome do contato ou telefone)\n"); }

$dig = preg_replace('/\D/', '', $q);
if ($dig !== '' && strlen($dig) >= 8) {
    $st = $pdo->prepare("SELECT * FROM zapi_conversas WHERE telefone LIKE ? ORDER BY ultima_msg_em DESC LIMIT 10");
    $st->execute(array('%' . $dig . '%'));
} else {
    $st = $pdo->prepare("SELECT * FROM zapi_conversas WHERE nome_contato LIKE ? ORDER BY ultima_msg_em DESC LIMIT 10");
    $st->execute(array('%' . $q . '%'));
}
$convs = $st->fetchAll();
if (!$convs) { exit("Nenhuma conversa encontrada pra '{$q}'.\n"); }

echo "=== CONVERSAS ENCONTRADAS pra '{$q}' ===\n";
foreach ($convs as $c) {
    echo sprintf("  conv #%d | canal %s | %s | tel %s | atendente_id=%s | delegada=%s | ultima_msg_em=%s\n",
        $c['id'], $c['canal'], $c['nome_contato'], $c['telefone'],
        $c['atendente_id'] ?: '-', $c['delegada'] ?? '-', $c['ultima_msg_em'] ?? '-');
}

$conv = $convs[0];
$cid = (int)$conv['id'];
echo "\n=== ANÁLISE DA CONVERSA #{$cid} ({$conv['nome_contato']}) ===\n";

$an = $pdo->prepare("SELECT u.name FROM users u WHERE u.id = ?");
$an->execute(array((int)$conv['atendente_id']));
$atName = $an->fetchColumn() ?: '(sem atendente)';
echo "Atendente vinculado: #{$conv['atendente_id']} {$atName}\n";
echo "Canal: {$conv['canal']}  (canal 24 = sempre livre)\n";

$m = $pdo->prepare(
    "SELECT m.id, m.direcao, m.tipo, m.enviado_por_id, m.enviado_por_bot,
            m.created_at, u.name AS quem
     FROM zapi_mensagens m
     LEFT JOIN users u ON u.id = m.enviado_por_id
     WHERE m.conversa_id = ?
     ORDER BY m.id DESC LIMIT 20"
);
$m->execute(array($cid));
$msgs = $m->fetchAll();

echo "\n=== ÚLTIMAS 20 MENSAGENS (mais nova primeiro) ===\n";
$agora = time();
foreach ($msgs as $i => $mm) {
    $ts = strtotime($mm['created_at']);
    $idadeH = round(($agora - $ts) / 3600, 1);
    if ($mm['direcao'] === 'recebida') {
        $quem = 'CLIENTE';
    } elseif (!empty($mm['enviado_por_bot'])) {
        $quem = 'BOT-IA';
    } elseif (!empty($mm['enviado_por_id'])) {
        $quem = 'EQUIPE: ' . ($mm['quem'] ?: ('user#' . $mm['enviado_por_id']));
    } else {
        $quem = 'ENVIADA s/ enviado_por_id (automação/fila/cron?)';
    }
    echo sprintf("  %s#%d | %s | %s | há %sh | %s\n",
        $i === 0 ? '>> ' : '   ', $mm['id'], $mm['created_at'],
        str_pad($mm['direcao'], 8), $idadeH, $quem);
}

echo "\n=== REGRA DA TRAVA (zapi_pode_enviar_conversa) ===\n";
echo "FOLLOWUP_HORAS=" . ZAPI_TRAVA_FOLLOWUP_HORAS . "h | CLIENTE_ESPERANDO_HORAS_UTEIS=" . ZAPI_TRAVA_CLIENTE_ESPERANDO_HORAS_UTEIS . "h úteis\n";
$ult = $msgs[0] ?? null;
if ($ult) {
    $ts = strtotime($ult['created_at']);
    $idade = $agora - $ts;
    echo "Última msg: #{$ult['id']} dir={$ult['direcao']} em {$ult['created_at']} (idade " . round($idade/3600,1) . "h)\n";
    echo "→ A trava das 36h é medida a partir DESSA última msg (qualquer enviada reseta).\n";
}

$uid = (int)($_GET['uid'] ?? 0);
if ($uid > 0) {
    $r = zapi_pode_enviar_conversa($cid, $uid);
    echo "\nzapi_pode_enviar_conversa(conv={$cid}, user={$uid}):\n";
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    if (isset($r['segundos_ate_liberar'])) {
        echo "  → libera em ~" . round($r['segundos_ate_liberar']/3600,1) . "h\n";
    }
} else {
    echo "\n(passe &uid=<id de um atendente NÃO-admin> pra ver o resultado real da trava)\n";
}

echo "\n=== USUÁRIOS (pra escolher uid) ===\n";
foreach ($pdo->query("SELECT id, name, role FROM users WHERE is_active=1 ORDER BY id")->fetchAll() as $u) {
    echo "  #{$u['id']} {$u['name']} ({$u['role']})\n";
}
