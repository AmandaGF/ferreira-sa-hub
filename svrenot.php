<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();

$threadId = (int)($_GET['thread'] ?? 8);
echo "=== Thread #$threadId ===\n";
$t = $pdo->prepare("SELECT t.*, c.name AS cliente_nome, c.phone AS cliente_phone
                    FROM salavip_threads t
                    JOIN clients c ON c.id = t.cliente_id
                    WHERE t.id = ?");
$t->execute([$threadId]);
$thread = $t->fetch(PDO::FETCH_ASSOC);
if (!$thread) { echo "Thread nao existe\n"; exit; }
print_r($thread);

if (empty($_GET['confirmar'])) {
    echo "\n*** ADICIONE &confirmar=1 na URL PRA ENVIAR ***\n";
    exit;
}

// Enviar mensagem
$primeiroNome = explode(' ', $thread['cliente_nome'])[0];
$assunto = mb_substr($thread['assunto'], 0, 80);
$link = 'https://ferreiraesa.com.br/salavip/';
$texto = "🔔 *Atualização no seu chamado*\n\n"
       . "Olá, *$primeiroNome*!\n\n"
       . "Você tem uma nova resposta sobre *\"$assunto\"*.\n\n"
       . "Acesse a *Central VIP* pra ler a mensagem completa:\n$link\n\n"
       . "_Equipe Ferreira & Sá Advocacia_";

echo "\n=== Enviando pra {$thread['cliente_phone']} ===\n";
echo "Mensagem:\n$texto\n\n";

$r = zapi_send_text('24', $thread['cliente_phone'], $texto);
print_r($r);

if (!empty($r['ok'])) {
    $mid = is_array($r['data']) ? ($r['data']['id'] ?? $r['data']['zaapId'] ?? $r['data']['messageId'] ?? '') : '';
    echo "\n✓ ENVIADO — mid: $mid\n";
    audit_log('salavip_notif_reenvio_manual', 'salavip_threads', $threadId, "cliente={$thread['cliente_nome']} tel={$thread['cliente_phone']}");
} else {
    echo "\n✗ FALHOU: " . ($r['erro'] ?? '?') . "\n";
}
