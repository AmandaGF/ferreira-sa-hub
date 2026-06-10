<?php
/**
 * Diag: lista users ativos + se tem email/phone cadastrado
 * (pra Amanda saber quem vai/nao vai receber notificacao de helpdesk)
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$rows = $pdo->query("SELECT id, name, email, phone, role FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

echo "=== USERS ATIVOS · NOTIFICACOES HELPDESK ===\n\n";
echo str_pad('Nome', 32) . " | " . str_pad('Email', 30) . " | " . str_pad('Phone', 18) . " | Sino | Email | WA\n";
echo str_repeat('-', 110) . "\n";
foreach ($rows as $u) {
    $email = $u['email'] ?: '—';
    $phone = $u['phone'] ?: '—';
    $sino  = '✓';
    $eml   = $u['email'] ? '✓' : '✕';
    $wa    = $u['phone'] ? '✓' : '✕';
    echo str_pad(mb_substr($u['name'], 0, 32, 'UTF-8'), 32)
       . " | " . str_pad(mb_substr($email, 0, 30, 'UTF-8'), 30)
       . " | " . str_pad($phone, 18)
       . " | " . $sino
       . "   | " . $eml
       . "   | " . $wa
       . "\n";
}

// Brevo check
echo "\n=== BREVO ===\n";
$k = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'brevo_api_key'")->fetchColumn();
echo "Brevo API key configurada: " . ($k ? 'SIM' : 'NAO -- emails nao saem') . "\n";
