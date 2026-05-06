<?php
/**
 * Corrige foto_path de colaboradores que estão com '/files/...' em vez de
 * '/conecta/files/...'. Sem o prefixo, o browser não acha o arquivo (o site
 * roda em /conecta/, então as URLs absolutas precisam começar com /conecta/).
 *
 * Idempotente: rodar várias vezes não faz mal.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_corrigir_foto_path.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Forbidden.');
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Atualiza só os que começam com /files/ E não com /conecta/
$n = $pdo->exec(
    "UPDATE colaboradores_onboarding
     SET foto_path = CONCAT('/conecta', foto_path)
     WHERE foto_path LIKE '/files/onboarding_fotos/%'
       AND foto_path NOT LIKE '/conecta/%'"
);

echo "OK — $n linha(s) atualizada(s).\n";
echo "(prefixo /conecta adicionado em foto_path que estavam só com /files/...)\n";
