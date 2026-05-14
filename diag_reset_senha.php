<?php
/**
 * Diag — verifica token de reset de senha (esqueci_senha.php).
 * Token armazenado em audit_log.action='password_reset_token', details='TOKEN|EXPIRES'.
 *
 * Uso: https://ferreiraesa.com.br/conecta/diag_reset_senha.php?key=fsa-hub-deploy-2026&token=XXXX
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

$token = trim($_GET['token'] ?? '');
$agora = date('Y-m-d H:i:s');

echo "Agora: {$agora}\n\n";

// Lista todos os tokens recentes de reset (últimos 24h)
echo "=== Tokens password_reset_token nas últimas 24h ===\n\n";
$st = $pdo->prepare(
    "SELECT al.id, al.user_id, al.details, al.ip_address, al.created_at, u.name, u.email
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.action = 'password_reset_token'
       AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     ORDER BY al.created_at DESC"
);
$st->execute();
$rows = $st->fetchAll();

if (!$rows) {
    echo "Nenhum token nas últimas 24h.\n\n";
} else {
    foreach ($rows as $r) {
        $parts = explode('|', $r['details']);
        $tok = $parts[0] ?? '';
        $exp = $parts[1] ?? '';
        $expirado = $exp <= $agora;
        echo "audit_log #{$r['id']} — user #{$r['user_id']} ({$r['name']} / {$r['email']})\n";
        echo "  token: {$tok}\n";
        echo "  expira: {$exp}" . ($expirado ? " ⚠ EXPIRADO" : " ✓ válido") . "\n";
        echo "  criado: {$r['created_at']} (IP {$r['ip_address']})\n";
        echo "  link: https://ferreiraesa.com.br/conecta/auth/esqueci_senha.php?step=reset&token={$tok}\n\n";
    }
}

// Se passou um token específico, valida
if ($token) {
    echo "=== Validação do token informado ===\n";
    echo "Token: {$token}\n\n";

    $st2 = $pdo->prepare(
        "SELECT al.user_id, al.details, al.created_at, u.name, u.email FROM audit_log al
         JOIN users u ON u.id = al.user_id
         WHERE al.action = 'password_reset_token' AND al.details LIKE ?
         ORDER BY al.created_at DESC LIMIT 1"
    );
    $st2->execute(array($token . '%'));
    $rec = $st2->fetch();

    if (!$rec) {
        echo "✗ Token NÃO ENCONTRADO em audit_log.\n";
        echo "  Possíveis causas: já foi usado (apagado após reset), ou nunca foi gerado, ou audit_log foi truncado.\n";
    } else {
        $parts = explode('|', $rec['details']);
        $storedToken = $parts[0] ?? '';
        $exp = $parts[1] ?? '';
        echo "Usuário: {$rec['name']} (#{$rec['user_id']}, {$rec['email']})\n";
        echo "Criado em: {$rec['created_at']}\n";
        echo "Token armazenado: {$storedToken}\n";
        echo "Bate com o informado? " . ($storedToken === $token ? "✓ SIM" : "✗ NÃO") . "\n";
        echo "Expira em: {$exp}\n";
        echo "Status: " . ($exp > $agora ? "✓ VÁLIDO" : "⚠ EXPIRADO há " . round((strtotime($agora) - strtotime($exp)) / 60) . " min") . "\n";
    }
}
