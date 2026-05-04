<?php
/**
 * Libera acesso ao chat WhatsApp da Simone (user#5).
 * - whatsapp (módulo principal)
 * - whatsapp_21 (canal Comercial DDD 21)
 * - whatsapp_24 (canal CX/Operacional DDD 24)
 *
 * Os demais sub-módulos (bot, config, dashboard, fila, templates) ficam
 * bloqueados — são admin/gestão. Amanda libera depois se precisar.
 */
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$liberar = array('whatsapp', 'whatsapp_21', 'whatsapp_24');
foreach ($liberar as $m) {
    $pdo->prepare("INSERT INTO user_permissions (user_id, module, allowed) VALUES (5, ?, 1)
                   ON DUPLICATE KEY UPDATE allowed = 1")
        ->execute(array($m));
    echo "  libero: $m\n";
}

echo "\n=== Estado atual user#5 (whatsapp*) ===\n";
$st = $pdo->query("SELECT module, allowed FROM user_permissions WHERE user_id = 5 AND module LIKE 'whatsapp%' ORDER BY module");
foreach ($st->fetchAll() as $r) {
    echo "  " . str_pad($r['module'], 22) . " " . ($r['allowed'] ? '✅ permitido' : '🔒 bloqueado') . "\n";
}
