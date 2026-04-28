<?php
/**
 * One-shot: reenviar parabéns pra Rayane Joyce (id 1201) com nome correto.
 * Acesso admin: ?key=fsa-hub-deploy-2026 [&confirmar=1]
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_zapi.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$cli = $pdo->prepare("SELECT id, name, phone, whatsapp_lid FROM clients WHERE id = 1201");
$cli->execute();
$c = $cli->fetch();
if (!$c) { exit("Cliente 1201 não encontrado\n"); }

echo "Cliente: {$c['name']} (id {$c['id']})\n";
echo "Telefone: {$c['phone']}\n";
echo "LID: {$c['whatsapp_lid']}\n";

// Canal usado pelo cron de aniversário
$canal = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'zapi_auto_aniversario_canal'")->fetchColumn() ?: '24';
echo "Canal: {$canal}\n\n";

$msg = "Joyce, perdoe o engano da mensagem anterior! 🥰\n\n"
     . "Desejamos um feliz aniversário, repleto de alegria, saúde e muitas realizações! 🎂✨\n\n"
     . "Com carinho,\n"
     . "Equipe Ferreira & Sá Advocacia";

echo "TEXTO QUE SERÁ ENVIADO:\n────────────────────\n{$msg}\n────────────────────\n\n";

if (!isset($_GET['confirmar'])) {
    echo "Pra enviar, adicione &confirmar=1 à URL.\n";
    exit;
}

$r = zapi_send_text($canal, $c['phone'], $msg);
echo "Resultado:\n" . json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if (!empty($r['ok'])) {
    audit_log('zapi_parabens_corrigido', 'clients', 1201, 'Reenviado com nome "Joyce" (cadastro era "RAYANE JOYCE")');
    echo "\n✓ Enviado com sucesso.\n";
} else {
    echo "\n✕ Falha no envio.\n";
}
