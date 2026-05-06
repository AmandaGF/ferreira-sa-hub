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

// Busca a colaboradora demo pelo nome (mais flexível que e-mail)
$st = $pdo->query("SELECT id, nome_completo, email_institucional, foto_path
                   FROM colaboradores_onboarding
                   WHERE LOWER(nome_completo) LIKE '%malu%demo%'
                      OR LOWER(nome_completo) LIKE '%maria%demo%'
                      OR email_institucional LIKE 'malu.%'
                      OR email_institucional LIKE 'maria.%'");
$linhas = $st->fetchAll();
echo "Encontradas " . count($linhas) . " linha(s) candidata(s):\n";
foreach ($linhas as $l) {
    echo "  - id={$l['id']}, nome='{$l['nome_completo']}', email='{$l['email_institucional']}', foto='{$l['foto_path']}'\n";
}

$n = $pdo->exec(
    "UPDATE colaboradores_onboarding
     SET foto_path = '/conecta/assets/img/onboarding_demo_avatar.svg'
     WHERE (LOWER(nome_completo) LIKE '%malu%demo%' OR LOWER(nome_completo) LIKE '%maria%demo%'
            OR email_institucional LIKE 'malu.%' OR email_institucional LIKE 'maria.%')
       AND (foto_path IS NULL OR foto_path = '' OR foto_path LIKE '/conecta/assets/img/%')"
);

echo "\nOK — $n linha(s) atualizada(s).\n";
echo "Avatar: /conecta/assets/img/onboarding_demo_avatar.svg\n";
