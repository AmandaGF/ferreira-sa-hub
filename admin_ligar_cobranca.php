<?php
/** Liga a cobrança comercial e mostra o status. ?testar=1 faz UM run real agora. Remover após uso. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_comercial.php';
$pdo = db();

comercial_set_cfg($pdo, 'comercial_cobranca_ativo', '1');
$cfg = comercial_cfg($pdo);

echo "=== Cobrança Comercial — status ===\n";
echo "ativa:        " . ($cfg['ativo'] === '1' ? 'SIM ✅' : 'não') . "\n";
echo "grupo_id:     " . ($cfg['grupo_id'] ?: '(vazio!)') . "\n";
echo "canal:        " . $cfg['grupo_canal'] . "\n";
echo "min sem resp: " . $cfg['min'] . "\n";
echo "em horário agora (9-18 seg-sex): " . (comercial_em_horario() ? 'SIM' : 'não') . "\n";

if (!empty($_GET['testar'])) {
    echo "\n--- RUN REAL (forçando horário) ---\n";
    $rep = comercial_rodar_cobranca($pdo, array('forcar_horario' => true));
    echo json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
