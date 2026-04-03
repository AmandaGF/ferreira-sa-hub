<?php
/**
 * Webhook Brevo — recebe eventos de e-mail (aberturas, cliques, descadastros)
 * URL: /conecta/publico/brevo_webhook.php
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';

header('Content-Type: application/json');

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || !isset($data['event'])) {
    echo json_encode(array('ok' => false));
    exit;
}

$event = $data['event'];
$email = isset($data['email']) ? $data['email'] : '';
$campId = isset($data['tag']) ? $data['tag'] : '';

try {
    $pdo = db();

    // Buscar campanha pelo brevo_campaign_id se veio no tag
    if ($campId) {
        $stmt = $pdo->prepare("SELECT id FROM newsletter_campanhas WHERE brevo_campaign_id = ?");
        $stmt->execute(array($campId));
        $camp = $stmt->fetch();
        $localId = $camp ? (int)$camp['id'] : 0;

        if ($localId) {
            if ($event === 'opened' || $event === 'unique_opened') {
                $pdo->prepare("UPDATE newsletter_campanhas SET total_abertos = total_abertos + 1 WHERE id = ?")->execute(array($localId));
            }
            if ($event === 'click') {
                $pdo->prepare("UPDATE newsletter_campanhas SET total_cliques = total_cliques + 1 WHERE id = ?")->execute(array($localId));
            }
            if ($event === 'delivered') {
                $pdo->prepare("UPDATE newsletter_campanhas SET total_enviados = total_enviados + 1 WHERE id = ?")->execute(array($localId));
            }
        }
    }

    // Descadastro via Brevo
    if ($event === 'unsubscribed' && $email) {
        $clientStmt = $pdo->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
        $clientStmt->execute(array($email));
        $client = $clientStmt->fetch();
        $clientId = $client ? (int)$client['id'] : null;

        $pdo->prepare("INSERT IGNORE INTO newsletter_descadastros (client_id, email, motivo) VALUES (?, ?, ?)")
            ->execute(array($clientId, $email, 'Descadastro via Brevo'));
    }
} catch (Exception $e) {
    error_log('[brevo_webhook] ' . $e->getMessage());
}

echo json_encode(array('ok' => true));
