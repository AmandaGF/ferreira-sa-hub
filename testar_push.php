<?php
/**
 * Diagnóstico do Web Push — envia uma notificação de teste pro usuário logado.
 * Uso: acessar https://ferreiraesa.com.br/conecta/testar_push.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/middleware.php';
require_login();

require_once __DIR__ . '/core/functions_push.php';

header('Content-Type: text/plain; charset=utf-8');
$userId = current_user_id();
$pdo = db();

echo "User ID: $userId\n\n";

// Verifica subscriptions
$stmt = $pdo->prepare("SELECT id, endpoint, ativo, created_at, LEFT(user_agent, 80) as ua FROM push_subscriptions WHERE user_id = ?");
$stmt->execute(array($userId));
$subs = $stmt->fetchAll();

echo "Subscriptions ativas: " . count(array_filter($subs, function($s){ return (int)$s['ativo'] === 1; })) . " / " . count($subs) . " total\n";
foreach ($subs as $s) {
    echo "  #" . $s['id'] . " [" . ($s['ativo'] ? 'ativo' : 'inativo') . "] " . substr($s['endpoint'], 0, 60) . "...\n";
    echo "    UA: " . $s['ua'] . "\n";
    echo "    Criado: " . $s['created_at'] . "\n";
}

if (empty($subs)) {
    echo "\n⚠️  Nenhuma subscription. Aceite a permissão de notificação primeiro (banner embaixo à esquerda, 30s após abrir o Hub).\n";
    exit;
}

// Verifica VAPID
$k = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('vapid_public','vapid_private','vapid_subject')")->fetchAll();
echo "\nVAPID keys: " . count($k) . " configuradas\n";

echo "\nEnviando push de teste...\n";
push_notify($userId, '🔔 Teste F&S Hub', 'Se você recebeu isso, o Web Push está funcionando.', '/conecta/', false);

echo "Enviado. Confira o dispositivo nos próximos 30s.\n";
echo "\nObs: se não chegou, veja no console do Chrome (DevTools → Application → Service Workers → Push) se há erros.\n";
