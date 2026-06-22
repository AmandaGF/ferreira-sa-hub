<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
@session_start(); $_SESSION['user']=array('id'=>1,'name'=>'A','role'=>'admin');
$_GET=array('gran'=>'dia');
ob_start(); require __DIR__.'/modules/whatsapp/conversas_novas.php'; $h=ob_get_clean();
header('Content-Type: text/plain; charset=utf-8');
// extrai as primeiras celulas <td> de período da tabela
if (preg_match_all('/<td>(\d{2}\/\d{2}\/\d{4}[^<]*)<\/td>/u', $h, $m)) {
    echo "Rótulos de período encontrados (amostra):\n";
    foreach (array_slice($m[1],0,6) as $x) echo "  [$x]\n";
} else { echo "Nenhum rótulo dd/mm/aaaa achado na tabela.\n"; }
