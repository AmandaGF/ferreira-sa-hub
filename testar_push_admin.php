<?php
/**
 * Dispara push de teste pra um usuário específico.
 * Uso: curl "https://ferreiraesa.com.br/conecta/testar_push_admin.php?key=fsa-hub-deploy-2026&email=amandaguedesferreira@gmail.com"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_push.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$email = $_GET['email'] ?? 'amandaguedesferreira@gmail.com';
$user = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
$user->execute(array($email));
$u = $user->fetch();
if (!$u) { echo "Usuário não encontrado: $email\n"; exit; }

$userId = (int)$u['id'];
echo "Usuário: {$u['name']} (#$userId)\n\n";

$stmt = $pdo->prepare("SELECT id, endpoint, ativo, LEFT(user_agent, 80) as ua, created_at FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC");
$stmt->execute(array($userId));
$subs = $stmt->fetchAll();

echo "Subscriptions: " . count($subs) . " (ativos: " . count(array_filter($subs, function($s){return (int)$s['ativo']===1;})) . ")\n";
foreach ($subs as $s) {
    $host = parse_url($s['endpoint'], PHP_URL_HOST);
    echo "  #" . $s['id'] . " [" . ($s['ativo'] ? 'ativo  ' : 'inativo') . "] $host | " . $s['created_at'] . "\n";
    echo "    UA: " . $s['ua'] . "\n";
}

if (empty($subs)) {
    echo "\n⚠️  Nenhuma subscription registrada pra esse usuário ainda.\n";
    echo "   Ela precisa abrir o Hub + aceitar o banner de notificações primeiro.\n";
    exit;
}

// Verifica VAPID
$kv = $pdo->query("SELECT chave, LEFT(valor, 30) as v FROM configuracoes WHERE chave IN ('vapid_public','vapid_private','vapid_subject')")->fetchAll();
echo "\nVAPID configurado:\n";
foreach ($kv as $r) echo "  {$r['chave']}: {$r['v']}...\n";

echo "\n— Enviando push de teste —\n";

// Chama a versão low-level pra capturar retorno
$ativas = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ? AND ativo = 1");
$ativas->execute(array($userId));

$payload = json_encode(array(
    'title'   => '🔔 Teste F&S Hub',
    'body'    => 'Se você recebeu esta notificação, o Web Push está funcionando! — ' . date('H:i:s'),
    'url'     => '/conecta/',
    'urgente' => false,
), JSON_UNESCAPED_UNICODE);

foreach ($ativas->fetchAll() as $sub) {
    $r = _push_send_one($sub, $payload);
    $host = parse_url($sub['endpoint'], PHP_URL_HOST);
    echo "  sub#" . $sub['id'] . " → $host : HTTP " . $r['status'] . ($r['ok'] ? ' ✅' : ' ❌');
    if ($r['error']) echo " [" . $r['error'] . "]";
    echo "\n";
    if (!$r['ok'] && in_array($r['status'], array(404,410), true)) {
        $pdo->prepare("UPDATE push_subscriptions SET ativo = 0 WHERE id = ?")->execute(array($sub['id']));
        echo "     (desativado automaticamente — expirado/inválido)\n";
    }
}

echo "\n— Fim —\n";
echo "Se HTTP 2xx: foi entregue ao push service. Celular/navegador deve receber em até 30s.\n";
echo "Se HTTP 400/401/403: erro de VAPID/JWT.\n";
echo "Se HTTP 404/410: subscription inválida/expirada (auto-desativada).\n";
