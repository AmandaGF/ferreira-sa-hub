<?php
/** Estado atual das intimacoes DJen.
 *  curl "https://ferreiraesa.com.br/conecta/diag_djen_estado.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
function q($pdo,$sql){ try { return $pdo->query($sql); } catch(Exception $e){ echo "  [erro: ".$e->getMessage()."]\n"; return null; } }

echo "=== HOJE: " . date('Y-m-d H:i') . " ===\n\n";

echo "--- case_publicacoes por status_prazo ---\n";
$r = q($pdo,"SELECT status_prazo, COUNT(*) qt, MIN(data_disponibilizacao) mn, MAX(data_disponibilizacao) mx FROM case_publicacoes GROUP BY status_prazo");
if ($r) foreach ($r->fetchAll() as $x) echo sprintf("  %-12s %4d  (%s a %s)\n", $x['status_prazo'], $x['qt'], $x['mn'], $x['mx']);

echo "\n--- djen_pending (orfas) por status ---\n";
$r = q($pdo,"SELECT status, COUNT(*) qt, MIN(data_disp) mn, MAX(data_disp) mx FROM djen_pending GROUP BY status");
if ($r) foreach ($r->fetchAll() as $x) echo sprintf("  %-12s %4d  (%s a %s)\n", $x['status'], $x['qt'], $x['mn'], $x['mx']);

echo "\n--- Prazos pendentes urgentes ---\n";
$r = q($pdo,"SELECT
  SUM(status_prazo='pendente') AS pendentes,
  SUM(status_prazo='pendente' AND data_prazo_fim < CURDATE()) AS vencidas,
  SUM(status_prazo='pendente' AND data_prazo_fim = CURDATE()) AS hoje,
  SUM(status_prazo='pendente' AND data_prazo_fim = DATE_ADD(CURDATE(),INTERVAL 1 DAY)) AS amanha
  FROM case_publicacoes");
if ($r) { $x=$r->fetch(); echo "  pendentes={$x['pendentes']}  vencidas={$x['vencidas']}  hoje={$x['hoje']}  amanha={$x['amanha']}\n"; }

echo "\n--- Ultimas 10 execucoes do Claudin (claudin_runs) ---\n";
$r = q($pdo,"SELECT executado_em, data_alvo, horario, total_parsed, imported, duplicated, pending, errors, status, tempo_execucao_segundos
             FROM claudin_runs ORDER BY id DESC LIMIT 10");
if ($r) foreach ($r->fetchAll() as $x) {
    echo sprintf("  %s | alvo=%s h=%s | parsed=%d imp=%d dup=%d pend=%d err=%d | %s | %ss\n",
        $x['executado_em'], $x['data_alvo'], $x['horario'], $x['total_parsed'], $x['imported'],
        $x['duplicated'], $x['pending'], $x['errors'], $x['status'], $x['tempo_execucao_segundos']);
}

echo "\n--- Ultimas 5 publicacoes importadas ---\n";
$r = q($pdo,"SELECT cp.id, cp.data_disponibilizacao, cp.tipo_publicacao, cp.status_prazo, cp.data_prazo_fim, cs.title
             FROM case_publicacoes cp LEFT JOIN cases cs ON cs.id=cp.case_id ORDER BY cp.id DESC LIMIT 5");
if ($r) foreach ($r->fetchAll() as $x) {
    echo sprintf("  #%s %s [%s] %s fatal=%s | %s\n", $x['id'], $x['data_disponibilizacao'], $x['tipo_publicacao'], $x['status_prazo'], $x['data_prazo_fim'] ?: '-', substr($x['title'] ?? '(sem pasta)',0,45));
}

echo "\n=== FIM ===\n";
