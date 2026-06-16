<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();

echo "=== Config atual ===\n";
$st = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'comemoracao_contrato_%' AND chave != 'comemoracao_contrato_log' AND chave != 'comemoracao_contrato_template' ORDER BY chave");
foreach ($st as $r) echo "  {$r['chave']} = '{$r['valor']}'\n";

echo "\n=== Tentando enviar mensagem do Jorjao ===\n";
$canal = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_canal'")->fetchColumn();
$grupo = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_grupo_id'")->fetchColumn();
echo "canal: '$canal' · grupo: '$grupo'\n\n";

$msg = "🔔 *Fala pessoal! Jorjão tá na área dnovo!* 🔔\n\nBora lá?! 🚀\n\n_(teste do diag às " . date('H:i:s') . ")_";
$r = zapi_send_text($canal, $grupo, $msg);
echo "ok = " . (!empty($r['ok']) ? 'SIM' : 'NAO') . "\n";
if (!empty($r['data'])) echo "data = " . json_encode($r['data']) . "\n";
if (!empty($r['erro'])) echo "erro = " . $r['erro'] . "\n";
if (!empty($r['http_code'])) echo "http_code = " . $r['http_code'] . "\n";

echo "\n=== Status dos ULTIMOS envios pelo Hub pra esse grupo ===\n";
$st = $pdo->prepare("SELECT m.id, m.zapi_message_id, m.status, m.created_at, LEFT(m.conteudo, 80) AS preview
                     FROM zapi_mensagens m
                     JOIN zapi_conversas co ON co.id = m.conversa_id
                     WHERE co.telefone IN (?, ?) AND m.direcao='enviada'
                     ORDER BY m.created_at DESC LIMIT 5");
$st->execute(array(rtrim($grupo, '@g.us'), $grupo));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $stat = $m['status'] ?: '(vazio = NÃO entregou)';
    echo "  msg #{$m['id']} {$m['created_at']} | status='$stat'\n    \"{$m['preview']}\"\n";
}
