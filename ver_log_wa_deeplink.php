<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
$log = APP_ROOT . '/files/wa_deeplink_err.log';
if (!file_exists($log)) { echo "Sem erros registrados — log vazio."; exit; }
echo substr(file_get_contents($log), -5000);
