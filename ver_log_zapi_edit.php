<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
$log = APP_ROOT . '/files/zapi_edit.log';
if (!file_exists($log)) { echo "Log não existe ainda — edite uma mensagem primeiro."; exit; }
$content = file_get_contents($log);
// Últimas 5000 chars
echo substr($content, -5000);
