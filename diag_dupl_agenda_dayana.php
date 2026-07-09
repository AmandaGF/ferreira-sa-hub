<?php
/**
 * DIAG 09/07/2026 — investigar duplicidade "Audiência AIJ Dayana" vs
 * "Audiência (Conciliação) — corresp.: Carolina" no dia 09/07/2026 14h.
 *
 * Descartar via: chave ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAG duplicidade 09/07/2026 14h ===\n\n";

// Descobrir colunas reais da agenda_eventos primeiro
echo "-- Colunas agenda_eventos --\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM agenda_eventos")->fetchAll(PDO::FETCH_COLUMN, 0);
    echo implode(', ', $cols) . "\n\n";
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; exit; }

// Query SIMPLES em agenda_eventos (sem JOIN, sem colunas dinamicas)
try {
    $sql = "SELECT * FROM agenda_eventos
            WHERE DATE(data_inicio) = '2026-07-09'
              AND HOUR(data_inicio) BETWEEN 13 AND 15
            ORDER BY id";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "ERRO query base: " . $e->getMessage() . "\n"; exit;
}

echo "-- Eventos em 09/07/2026 entre 13h e 15h (qtd=" . count($rows) . ") --\n\n";
foreach ($rows as $r) {
    echo str_repeat('-', 78) . "\n";
    // Imprime tudo o que a linha tem, sem assumir schema
    foreach ($r as $k => $v) {
        if ($v === null || $v === '') continue;
        echo str_pad($k, 22) . ": " . $v . "\n";
    }
}
echo str_repeat('-', 78) . "\n\n";

if (count($rows) < 1) { echo "Nenhum evento encontrado.\n"; exit; }

// Dados dos cases envolvidos
$caseIds = array_unique(array_filter(array_map(function($r){ return $r['case_id'] ?? null; }, $rows)));
if ($caseIds) {
    echo "-- Cases envolvidos --\n";
    $ph = implode(',', array_fill(0, count($caseIds), '?'));
    $st = $pdo->prepare("SELECT id, title, case_number, client_id, stage FROM cases WHERE id IN ($ph)");
    $st->execute(array_values($caseIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        echo "  case #$c[id]  title=$c[title]  num=$c[case_number]  client_id=$c[client_id]  stage=$c[stage]\n";
    }
    echo "\n";
}

// audit_log
$evIds = array_map(function($r){ return (int)$r['id']; }, $rows);
$ph = implode(',', array_fill(0, count($evIds), '?'));
echo "-- audit_log entidade=agenda_evento --\n";
try {
    $st = $pdo->prepare("SELECT * FROM audit_log
                         WHERE entidade='agenda_evento' AND entidade_id IN ($ph)
                         ORDER BY id");
    $st->execute($evIds);
    $al = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$al) echo "  (vazio — talvez esta tabela seja diferente)\n";
    foreach ($al as $r) {
        echo "  #$r[id] " . ($r['created_at']??'-') . " user=" . ($r['user_id']??'-')
           . " acao=" . ($r['acao']??$r['action']??'-') . " entidade_id=" . ($r['entidade_id']??'-') . "\n";
    }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
echo "\n";

echo "=== FIM ===\n";
