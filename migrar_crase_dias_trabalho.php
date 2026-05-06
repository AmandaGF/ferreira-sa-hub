<?php
/**
 * Atualiza colaboradores_onboarding.dias_trabalho de "Segunda a sexta"
 * para "Segunda à sexta" (com crase). Idempotente.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_crase_dias_trabalho.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Forbidden.');
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$n = $pdo->exec("UPDATE colaboradores_onboarding
                 SET dias_trabalho = 'Segunda à sexta'
                 WHERE dias_trabalho = 'Segunda a sexta'");

echo "OK — $n linha(s) atualizada(s) (Segunda a sexta -> Segunda à sexta).\n";
