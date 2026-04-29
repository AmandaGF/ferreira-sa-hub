<?php
/**
 * One-shot: libera CRM e Agenda de Contatos pra Simone (user_id=5).
 */
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$simoneId = 5;
$liberar = ['crm', 'clientes']; // CRM completo + Agenda de Contatos

foreach ($liberar as $mod) {
    $st = $pdo->prepare("INSERT INTO user_permissions (user_id, module, allowed) VALUES (?, ?, 1)
                         ON DUPLICATE KEY UPDATE allowed = 1");
    $st->execute([$simoneId, $mod]);
    echo "  $mod → allowed=1\n";
}

// Audit
try {
    $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, 'user_permissions', ?, ?)")
        ->execute([1, 'simone_libera_contatos', $simoneId, 'Liberado crm e clientes pra Simone (user_id=5)']);
} catch (Exception $e) {}

// Confirma
echo "\n--- Estado atual da Simone (allowed=1) ---\n";
$st = $pdo->prepare("SELECT module FROM user_permissions WHERE user_id = ? AND allowed = 1 ORDER BY module");
$st->execute([$simoneId]);
foreach ($st->fetchAll() as $r) {
    echo "  ✅ {$r['module']}\n";
}
