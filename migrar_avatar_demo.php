<?php
/**
 * Atualiza foto_path da demo Malu pra apontar pro avatar SVG placeholder.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_avatar_demo.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Forbidden.');
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$n = $pdo->exec(
    "UPDATE colaboradores_onboarding
     SET foto_path = '/conecta/assets/img/onboarding_demo_avatar.svg'
     WHERE email_institucional = 'malu.demo@ferreiraesa.com.br'"
);

echo "OK — $n linha(s) atualizada(s).\n";
echo "Avatar da Malu: /conecta/assets/img/onboarding_demo_avatar.svg\n";
