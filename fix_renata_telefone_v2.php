<?php
/**
 * Fix v2: forca telefone da conv com '+' no inicio pra Z-API reconhecer DDI internacional.
 * Sem o '+', a Z-API trata como BR e prefixa 55.
 *
 * Uso: ?key=XXX&conv_id=1399&novo_tel=+34661457631&confirm=1
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$convId = (int)($_GET['conv_id'] ?? 1399);
$novoTel = trim($_GET['novo_tel'] ?? '+34661457631');
$confirm = !empty($_GET['confirm']);

$st = $pdo->prepare("SELECT id, telefone FROM zapi_conversas WHERE id = ?");
$st->execute(array($convId));
$c = $st->fetch();
if (!$c) { echo "conv#$convId nao encontrada\n"; exit; }

echo "Antes: conv#$convId tel=" . $c['telefone'] . "\n";
echo "Depois: tel=$novoTel\n\n";

if (!$confirm) { echo "[PREVIEW] adicione &confirm=1\n"; exit; }

$pdo->prepare("UPDATE zapi_conversas SET telefone = ?, updated_at = NOW() WHERE id = ?")
    ->execute(array($novoTel, $convId));
echo "OK aplicado\n";
audit_log('fix_renata_telefone_v2', 'zapi_conversa', $convId, "antes=" . $c['telefone'] . " depois=$novoTel");
