<?php
/** One-shot: apaga registros de TESTE de form_submissions (protocolos explícitos).
 *  NÃO toca em clientes reais. ?key=fsa-hub-deploy-2026&go=1 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$protos = array(
  'DSP-5D6B34F6CF', // TESTE DIAG (curl)
  'DSP-09FA3E6524', // Teste 17:35
  'DSP-D276BBE1DC', // Teste 23:43 (confirmação do fix)
  'SIT-C59E722B38', // ZZ TESTE SITE (apagar)
);
$ph = implode(',', array_fill(0, count($protos), '?'));
$st = $pdo->prepare("SELECT id, form_type, protocol, client_name FROM form_submissions WHERE protocol IN ($ph)");
$st->execute($protos);
$rows = $st->fetchAll();
echo "Encontrados " . count($rows) . ":\n";
foreach ($rows as $r) echo "  #{$r['id']} {$r['form_type']} {$r['protocol']} {$r['client_name']}\n";
if (!isset($_GET['go'])) { echo "\nAdicione &go=1 pra apagar.\n"; exit; }
$d = $pdo->prepare("DELETE FROM form_submissions WHERE protocol IN ($ph)");
$d->execute($protos);
echo "\n✓ Apagados: " . $d->rowCount() . " registro(s) de teste.\n";
echo "(Aline DSP-F712B2B0BB NÃO foi tocada — cliente real; precisa refazer o envio.)\n";
