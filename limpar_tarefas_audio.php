<?php
/**
 * limpar_tarefas_audio.php — remove pasta órfã do módulo tarefas_audio
 * Rodar 1x após deploy e apagar.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');

$pasta = __DIR__ . '/modules/tarefas_audio';
echo "Alvo: $pasta\n";

if (!is_dir($pasta)) {
    echo "Pasta não existe — nada a fazer.\n";
    exit;
}

$arquivos = glob($pasta . '/*');
foreach ($arquivos as $a) {
    if (is_file($a)) {
        $ok = @unlink($a);
        echo ($ok ? 'OK' : 'FALHOU') . "  $a\n";
    }
}
$rmdir = @rmdir($pasta);
echo ($rmdir ? 'OK' : 'FALHOU') . "  (rmdir) $pasta\n";
echo "\nFim.\n";
