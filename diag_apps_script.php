<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';

echo "=== URL do Google Apps Script configurado ===\n\n";
if (defined('GOOGLE_APPS_SCRIPT_URL') && GOOGLE_APPS_SCRIPT_URL) {
    echo "URL: " . GOOGLE_APPS_SCRIPT_URL . "\n\n";
    // Extrai o ID do script pra facilitar abrir o editor
    if (preg_match('#/macros/s/([a-zA-Z0-9_-]+)/#', GOOGLE_APPS_SCRIPT_URL, $m)) {
        echo "Para EDITAR o script, abra no navegador:\n";
        echo "https://script.google.com/home/projects/" . $m[1] . "/edit\n\n";
        echo "(ou vá em script.google.com → 'Meus projetos' e procure pelo nome que você deu)\n";
    }
} else {
    echo "NÃO CONFIGURADO (define GOOGLE_APPS_SCRIPT_URL não existe no config.php)\n";
}
