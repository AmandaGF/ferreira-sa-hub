<?php
/**
 * Diag rapido: ultimas 10 mensagens enviadas pela equipe na conv#1399 (Renata)
 * Mostra zapi_message_id, status, momment_ms, etc. Se zapi_message_id nao
 * preenchido = Z-API rejeitou. Se preenchido mas status='enviada' sem
 * confirmacao de DELIVERED no webhook = chegou no Z-API mas WhatsApp do
 * destinatario nao confirmou (numero pode nao ter conta WA ativa).
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$CONV_ID = 1399;

echo "=== ULTIMAS 10 MSGS ENVIADAS (conv#$CONV_ID) ===\n\n";
$st = $pdo->prepare(
    "SELECT id, direcao, tipo, status, zapi_message_id, entregue, lida, conteudo, created_at, enviado_por_id
     FROM zapi_mensagens
     WHERE conversa_id = ? AND direcao = 'enviada'
     ORDER BY id DESC LIMIT 10"
);
$st->execute(array($CONV_ID));
$msgs = $st->fetchAll();

foreach ($msgs as $m) {
    $conteudo = trim(preg_replace('/\s+/', ' ', (string)$m['conteudo']));
    if (mb_strlen($conteudo) > 80) $conteudo = mb_substr($conteudo, 0, 80) . '…';
    echo "  msg#" . $m['id']
       . " | " . $m['created_at']
       . " | status=" . ($m['status'] ?: '?')
       . " | zapi_id=" . ($m['zapi_message_id'] ?: '(VAZIO -- Z-API recusou ou nao salvou)')
       . " | entregue=" . (int)$m['entregue']
       . " | lida=" . (int)$m['lida']
       . "\n    conteudo=\"$conteudo\"\n\n";
}

echo "=== ESTADO CONVERSA ===\n";
$stC = $pdo->prepare("SELECT id, telefone, client_id, status, atendente_id, nao_lidas FROM zapi_conversas WHERE id = ?");
$stC->execute(array($CONV_ID));
$c = $stC->fetch();
echo "conv#" . $c['id'] . ": tel=" . $c['telefone'] . " client=" . $c['client_id']
   . " status=" . $c['status'] . " atendente=" . ($c['atendente_id'] ?: 'NULL')
   . " nao_lidas=" . $c['nao_lidas'] . "\n";

echo "\n=== INTERPRETACAO ===\n";
echo "  zapi_id PREENCHIDO + entregue=0: Z-API aceitou, mas WA do destino nao confirmou recebimento.\n";
echo "    -> Numero pode NAO ter conta WhatsApp ativa, OU numero errado.\n";
echo "  zapi_id VAZIO: Z-API recusou (numero invalido pra ela).\n";
echo "  zapi_id PREENCHIDO + entregue=1: msg chegou no WA do destino. Se cliente nao viu, problema dele.\n";
