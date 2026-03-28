<?php
/**
 * Ferreira & Sá Hub — Deploy automático
 * Acesse: ferreiraesa.com.br/conecta/deploy.php?key=SUA_CHAVE
 *
 * Este script faz git pull e corrige permissões.
 * Protegido por chave secreta na URL.
 */

// ─── Chave de segurança (mude para algo único!) ────────
$SECRET_KEY = 'fsa-hub-deploy-2026';

// Verificar chave
if (($_GET['key'] ?? '') !== $SECRET_KEY) {
    http_response_code(403);
    die('Acesso negado.');
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== Ferreira & Sá Hub — Deploy ===\n\n";

$repoDir = __DIR__;

// 1. Git pull
echo "1. Atualizando do GitHub...\n";
$output = [];
$returnCode = 0;
exec("cd " . escapeshellarg($repoDir) . " && git pull origin main 2>&1", $output, $returnCode);
echo implode("\n", $output) . "\n";
echo "Código de retorno: " . $returnCode . "\n\n";

// 2. Corrigir permissões
echo "2. Corrigindo permissões...\n";
$dirs = ['core', 'assets', 'assets/css', 'assets/js', 'assets/img', 'templates', 'auth', 'modules',
         'modules/dashboard', 'modules/portal', 'modules/helpdesk', 'modules/crm',
         'modules/pipeline', 'modules/operacional', 'modules/formularios',
         'modules/usuarios', 'modules/relatorios'];

foreach ($dirs as $dir) {
    $fullPath = $repoDir . '/' . $dir;
    if (is_dir($fullPath)) {
        chmod($fullPath, 0755);
        echo "  DIR  755: $dir\n";

        // Corrigir arquivos dentro do diretório
        $files = scandir($fullPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filePath = $fullPath . '/' . $file;
            if (is_file($filePath)) {
                chmod($filePath, 0644);
                echo "  FILE 644: $dir/$file\n";
            } elseif (is_dir($filePath)) {
                chmod($filePath, 0755);
                echo "  DIR  755: $dir/$file\n";
            }
        }
    }
}

// Arquivos na raiz
$rootFiles = ['.htaccess', 'schema.sql', 'deploy.php'];
foreach ($rootFiles as $rf) {
    if (is_file($repoDir . '/' . $rf)) {
        chmod($repoDir . '/' . $rf, 0644);
        echo "  FILE 644: $rf\n";
    }
}

// A pasta raiz
chmod($repoDir, 0755);
echo "  DIR  755: (raiz conecta)\n";

echo "\n=== Deploy concluído! ===\n";
echo "Acesse: /conecta/auth/login.php\n";
