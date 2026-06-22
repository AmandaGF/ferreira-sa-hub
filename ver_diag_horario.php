<?php
/** Diag temp: testa horário comercial + bom dia. Remover. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_comercial.php';

echo "Agora: " . date('Y-m-d H:i (D)') . "\n";
echo "comercial_em_horario(): " . (comercial_em_horario() ? 'SIM (9-18 dia útil)' : 'NÃO (fora)') . "\n\n";

echo "Testes fora_horario_ts:\n";
foreach (array('2026-06-22 08:30:00','2026-06-22 12:00:00','2026-06-22 19:30:00','2026-06-21 11:00:00') as $t) {
    echo "  $t -> " . (comercial_fora_horario_ts($t) ? 'FORA' : 'dentro') . "\n";
}

echo "\nExemplo de mensagem de BOM DIA:\n";
$leads = array(
    array('lead_name'=>'Wellington','client_name'=>null,'nome_contato'=>null,'telefone'=>'21999','atendente_id'=>null,'ultimo_resp_id'=>null,'assigned_to'=>8),
    array('lead_name'=>'Laura Souza','client_name'=>null,'nome_contato'=>null,'telefone'=>'21888','atendente_id'=>7,'ultimo_resp_id'=>null,'assigned_to'=>null),
);
$umap = array(7=>'Nativânia Gama Dourado', 8=>'Maria Vitória Caetano');
echo comercial_msg_bomdia($leads, $umap) . "\n";
