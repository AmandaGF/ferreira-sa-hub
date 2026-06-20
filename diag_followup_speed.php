<?php
/** Teste do Speed-to-lead (item 1).
 *  DRY-RUN (padrão, NÃO envia): mostra config + o que seria enviado p/ os últimos leads.
 *    curl "https://ferreiraesa.com.br/conecta/diag_followup_speed.php?key=fsa-hub-deploy-2026"
 *  TESTE REAL p/ 1 número (envia A1 de verdade, ignora kill switch, só p/ esse número):
 *    curl "https://ferreiraesa.com.br/conecta/diag_followup_speed.php?key=fsa-hub-deploy-2026&enviar=5524999999999"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_followup.php';
$pdo = db();

echo "=== TESTE Speed-to-lead ===\n\n";

// Config / estado
echo "--- Config ---\n";
echo "followup_ativo         = " . followup_cfg('followup_ativo', '(ausente)') . "\n";
echo "followup_speed_to_lead = " . followup_cfg('followup_speed_to_lead', '(ausente)') . "\n";
$temCol = false;
try { foreach ($pdo->query("SHOW COLUMNS FROM pipeline_leads LIKE 'primeiro_contato_em'")->fetchAll() as $x) $temCol = true; } catch (Exception $e) {}
echo "coluna primeiro_contato_em: " . ($temCol ? 'OK' : 'FALTANDO') . "\n";
echo "fora_horario agora: " . (zapi_fora_horario() ? 'SIM (fora 10-18 seg-sex)' : 'não (dentro do horário)') . "\n";
foreach (array('Follow A1 - Abertura (form/anuncio)','Follow A1 - Abertura (indicacao)','Follow A1 - Fora de horario') as $t) {
    $e = $pdo->prepare("SELECT id FROM zapi_templates WHERE nome = ?"); $e->execute(array($t));
    echo "template '$t': " . ($e->fetchColumn() ? 'OK' : 'FALTANDO') . "\n";
}
echo "\n";

// Teste real p/ 1 número
$enviar = preg_replace('/\D/', '', $_GET['enviar'] ?? '');
if ($enviar !== '') {
    echo "--- ENVIO DE TESTE para $enviar (ignora kill switch) ---\n";
    $msg = zapi_get_template('Follow A1 - Abertura (form/anuncio)', array('nome' => 'Teste', 'tema' => followup_tema_legivel('pensao_alimenticia')));
    echo "Mensagem:\n$msg\n\n";
    $r = zapi_send_text('21', $enviar, $msg);
    echo !empty($r['ok']) ? "[OK] enviado!\n" : ("[FALHA] http " . ($r['http_code'] ?? '?') . " " . ($r['erro'] ?? '') . "\n");
    echo "\n=== FIM ===\n"; exit;
}

// DRY-RUN nos últimos 5 leads
echo "--- DRY-RUN (últimos 5 leads — NADA é enviado) ---\n";
$leads = $pdo->query("SELECT id FROM pipeline_leads ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
foreach ($leads as $lid) {
    $r = followup_speed_to_lead($pdo, (int)$lid, true); // dry
    echo "lead #{$r['lead_id']}: acao={$r['acao']} | {$r['motivo']}";
    if (!empty($r['template'])) echo " | tpl={$r['template']} fora_horario={$r['fora_horario']}";
    echo "\n";
    if (!empty($r['mensagem']) && in_array($r['acao'], array('dry'))) {
        echo "    └ msg: " . str_replace("\n", " / ", $r['mensagem']) . "\n";
    }
}
echo "\n=== FIM (dry-run — use &enviar=SEUNUMERO p/ testar entrega real) ===\n";
