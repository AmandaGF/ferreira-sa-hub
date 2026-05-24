<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/uploads/executivo_last_error.log';
if (!is_file($f)) { echo "Sem log ainda. Recarregue o Painel Executivo no browser logado primeiro.\n"; exit; }
echo file_get_contents($f);
