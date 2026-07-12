<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$email = $_GET['email'] ?? 'amandaguedesferreira@gmail.com';
echo "=== USER $email ===\n";
$st = $pdo->prepare("SELECT id, name, email, role, is_active, last_login_at, updated_at FROM users WHERE email = ?");
$st->execute(array($email));
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { echo "  Nao encontrado!\n"; exit; }
print_r($u);

echo "\n=== TENTATIVAS RECENTES (login_attempts se existir) ===\n";
try {
    $st = $pdo->prepare("SELECT * FROM login_attempts WHERE email = ? ORDER BY attempted_at DESC LIMIT 10");
    $st->execute(array($email));
    foreach ($st as $r) print_r($r);
} catch (Exception $e) { echo "  (sem tabela login_attempts): " . $e->getMessage() . "\n"; }

echo "\n=== IS LOCKED? ===\n";
if (function_exists('is_login_locked')) {
    $r = is_login_locked($email);
    echo "  is_login_locked: " . ($r ? 'SIM (bloqueado)' : 'NAO') . "\n";
} else {
    echo "  (function nao carregada)\n";
}

echo "\n=== AUDIT recente de login/erros ===\n";
try {
    $st = $pdo->prepare("SELECT created_at, action, details FROM audit_log
                         WHERE (action LIKE '%login%' OR action LIKE '%auth%')
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                         ORDER BY created_at DESC LIMIT 15");
    $st->execute();
    foreach ($st as $r) printf("  %s %s | %s\n", $r['created_at'], $r['action'], substr($r['details']??'',0,120));
} catch (Exception $e) {}
