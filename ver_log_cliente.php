<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/uploads/cliente_last_error.log';
if (!file_exists($f)) { echo "log inexistente: $f\n(handler nao gravou — o 500 pode nao ser um E_ERROR, ou uploads/ nao e gravavel)\n"; exit; }
echo file_get_contents($f);
