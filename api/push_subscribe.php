<?php
/**
 * Salva (ou atualiza) uma subscription push pro usuário logado.
 *
 * POST JSON:
 *   { endpoint, p256dh, auth, user_agent? }
 *
 * Idempotente: se já existe (user_id+endpoint), atualiza p256dh/auth/reativa.
 */

require_once __DIR__ . '/../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$userId = current_user_id();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input) || empty($input['endpoint']) || empty($input['p256dh']) || empty($input['auth'])) {
    echo json_encode(array('error' => 'Payload inválido (endpoint/p256dh/auth obrigatórios)'));
    exit;
}

$endpoint = $input['endpoint'];
$p256dh   = $input['p256dh'];
$auth     = $input['auth'];
$ua       = substr($input['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);

try {
    // Upsert por (user_id + endpoint)
    $exists = $pdo->prepare("SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
    $exists->execute(array($userId, $endpoint));
    $existingId = (int)$exists->fetchColumn();

    if ($existingId) {
        $pdo->prepare("UPDATE push_subscriptions SET p256dh = ?, auth = ?, user_agent = ?, ativo = 1 WHERE id = ?")
            ->execute(array($p256dh, $auth, $ua, $existingId));
        echo json_encode(array('ok' => true, 'status' => 'atualizado'));
    } else {
        $pdo->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, ativo) VALUES (?, ?, ?, ?, ?, 1)")
            ->execute(array($userId, $endpoint, $p256dh, $auth, $ua));
        echo json_encode(array('ok' => true, 'status' => 'criado'));
    }
} catch (Exception $e) {
    echo json_encode(array('error' => 'Falha ao salvar: ' . $e->getMessage()));
}
