<?php
/**
 * Webhook receiver da Meta (Instagram + Facebook) - Amanda 04/06/2026 - Fase A
 *
 * GET = handshake de verificacao (hub.mode/hub.challenge/hub.verify_token)
 * POST = eventos (mensagens, comentarios, status)
 *
 * Esta rota e PUBLICA (Meta chama de servidores deles, nao tem session).
 *
 * Fase A: aceita o handshake e GRAVA os eventos brutos numa tabela de log
 * (meta_webhook_log) pra Amanda poder ver o que esta chegando. Nao processa
 * mensagens ainda - isso e Fase C apos App Review aprovado.
 */

// Bootstrap minimo (rota publica - sem session)
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';

$pdo = db();

// Self-heal: tabela de log de eventos pra debug/auditoria
try { $pdo->exec("CREATE TABLE IF NOT EXISTS meta_webhook_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direcao ENUM('handshake','event') NOT NULL,
    object_type VARCHAR(40) NULL,
    payload TEXT NULL,
    ip VARCHAR(45) NULL,
    headers TEXT NULL,
    processado TINYINT(1) NOT NULL DEFAULT 0,
    erro TEXT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recv (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// Le config (verify_token salvo via setup.php)
$verifyToken = '';
$webhookActive = false;
try {
    $st = $pdo->query("SELECT chave, valor FROM meta_config WHERE chave IN ('meta_verify_token','meta_webhook_active')");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['chave'] === 'meta_verify_token') $verifyToken = (string)$r['valor'];
        if ($r['chave'] === 'meta_webhook_active') $webhookActive = ($r['valor'] === '1');
    }
} catch (Exception $e) {}

// ═══ HANDSHAKE (GET) ═══
// Meta valida URL chamando GET com ?hub.mode=subscribe&hub.verify_token=X&hub.challenge=Y
// Devolvemos o challenge se o verify_token bate.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? '');
    $token = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
    $challenge = $_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? '');

    try {
        $stIns = $pdo->prepare("INSERT INTO meta_webhook_log (direcao, object_type, payload, ip) VALUES ('handshake', ?, ?, ?)");
        $stIns->execute(array($mode ?: 'unknown', json_encode($_GET, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null));
    } catch (Exception $e) {}

    if ($mode === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'forbidden';
    exit;
}

// ═══ EVENTO (POST) ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $objectType = is_array($data) ? ($data['object'] ?? 'unknown') : 'invalid_json';

    // Log bruto SEMPRE (mesmo se vamos processar ou nao)
    try {
        $hdrs = array();
        foreach ($_SERVER as $k => $v) { if (strpos($k, 'HTTP_X_') === 0) $hdrs[$k] = $v; }
        $stIns = $pdo->prepare("INSERT INTO meta_webhook_log (direcao, object_type, payload, headers, ip) VALUES ('event', ?, ?, ?, ?)");
        $stIns->execute(array($objectType, $raw, json_encode($hdrs), $_SERVER['REMOTE_ADDR'] ?? null));
    } catch (Exception $e) {}

    // Fase A: confirma recebimento mas NAO processa (espera webhook_active=1)
    // Fase C: aqui vai a logica de roteamento - se messages->grava em
    // meta_inbox_mensagens, se feed->grava em meta_comentarios.
    if (!$webhookActive) {
        http_response_code(200);
        echo 'OK (webhook_active=0 - logado mas nao processado)';
        exit;
    }

    // Placeholder: vai virar processador real em Fase C apos App Review
    http_response_code(200);
    echo 'OK';
    exit;
}

http_response_code(405);
echo 'method not allowed';
