<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== FIX 6 leads orfaos pasta_apta (Amanda 05/06/2026) ==\n\n";

// (a) Lead#1252 Luiz Eduardo: linked_case_id 798 (arquivado) -> 806 (distribuido, ativo)
echo "(a) Lead#1252 Luiz Eduardo: linked_case_id 798 -> 806\n";
try {
    $r = $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = 806 WHERE id = 1252 AND linked_case_id = 798");
    $r->execute();
    echo "  rowCount=" . $r->rowCount() . "\n";
    if ($r->rowCount()) audit_log('pipeline_lead_relink', 'pipeline_leads', 1252, 'linked_case_id 798 -> 806 (pasta duplicada Luiz Eduardo)');
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// (b) Lead#1246 Leidiane: linked_case_id 787 (arquivado) -> 773 (em_andamento, ativo - pastas unificadas)
echo "\n(b) Lead#1246 Leidiane: linked_case_id 787 -> 773\n";
try {
    $r = $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = 773 WHERE id = 1246 AND linked_case_id = 787");
    $r->execute();
    echo "  rowCount=" . $r->rowCount() . "\n";
    if ($r->rowCount()) audit_log('pipeline_lead_relink', 'pipeline_leads', 1246, 'linked_case_id 787 -> 773 (pastas unificadas)');
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// (c) Maria Aparecida: apaga lead#1181 (mais antigo, duplicata), atualiza #1198 -> case#1170
echo "\n(c.1) Apaga lead#1181 (duplicata Maria Aparecida)\n";
try {
    // Antes de apagar, captura snapshot pra audit
    $st = $pdo->prepare("SELECT id, name, client_id, stage, converted_at FROM pipeline_leads WHERE id = 1181");
    $st->execute();
    $snapshot = $st->fetch(PDO::FETCH_ASSOC);
    if ($snapshot) {
        $r = $pdo->prepare("DELETE FROM pipeline_leads WHERE id = 1181");
        $r->execute();
        echo "  rowCount=" . $r->rowCount() . "\n";
        audit_log('pipeline_lead_delete_duplicata', 'pipeline_leads', 1181, 'snapshot: ' . json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    } else { echo "  lead 1181 nao existe (talvez ja deletado)\n"; }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n(c.2) Lead#1198 Maria Aparecida: linked_case_id 652 -> 1170 (PM, ativo)\n";
try {
    $r = $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = 1170 WHERE id = 1198");
    $r->execute();
    echo "  rowCount=" . $r->rowCount() . "\n";
    if ($r->rowCount()) audit_log('pipeline_lead_relink', 'pipeline_leads', 1198, 'linked_case_id 652 (Inventario arquivado) -> 1170 (PM ativo)');
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// (d) Suelen: apaga lead#1189 (duplicata), atualiza #1191 -> case#748 (Alimentos distribuido)
echo "\n(d.1) Apaga lead#1189 (duplicata Suelen)\n";
try {
    $st = $pdo->prepare("SELECT id, name, client_id, stage, converted_at FROM pipeline_leads WHERE id = 1189");
    $st->execute();
    $snapshot = $st->fetch(PDO::FETCH_ASSOC);
    if ($snapshot) {
        $r = $pdo->prepare("DELETE FROM pipeline_leads WHERE id = 1189");
        $r->execute();
        echo "  rowCount=" . $r->rowCount() . "\n";
        audit_log('pipeline_lead_delete_duplicata', 'pipeline_leads', 1189, 'snapshot: ' . json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    } else { echo "  lead 1189 nao existe\n"; }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n(d.2) Lead#1191 Suelen: linked_case_id NULL -> 748 (Alimentos distribuido)\n";
try {
    $r = $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = 748 WHERE id = 1191");
    $r->execute();
    echo "  rowCount=" . $r->rowCount() . "\n";
    if ($r->rowCount()) audit_log('pipeline_lead_relink', 'pipeline_leads', 1191, 'linked_case_id NULL -> 748 (Suelen Alimentos)');
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// VERIFICACAO FINAL
echo "\n== Verificacao final ==\n";
$verifica = array(1252, 1246, 1198, 1191);
foreach ($verifica as $lid) {
    $st = $pdo->prepare("SELECT l.id, l.linked_case_id, c.status, c.title FROM pipeline_leads l LEFT JOIN cases c ON c.id = l.linked_case_id WHERE l.id = ?");
    $st->execute(array($lid));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { echo "  lead#$lid: NAO EXISTE\n"; continue; }
    echo "  lead#$lid -> case#{$r['linked_case_id']} status={$r['status']} '{$r['title']}'\n";
}
foreach (array(1181, 1189) as $lid) {
    $st = $pdo->prepare("SELECT id FROM pipeline_leads WHERE id = ?");
    $st->execute(array($lid));
    echo "  lead#$lid existe ainda? " . ($st->fetch() ? 'SIM ❌' : 'NAO ✓ (deletado)') . "\n";
}
echo "\nFIM\n";
