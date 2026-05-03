<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Suspensoes SUSPEITAS (motivo generico, sem ato/legislacao) ===\n";
echo "Criterios: motivo LIKE 'Suspensao de prazos%' OU ato_legislacao vazio E tipo='outros'\n\n";

$st = $pdo->query("
    SELECT s.id, s.data_inicio, s.data_fim, s.tipo, s.motivo, s.ato_legislacao,
           s.publicacao, s.abrangencia, s.comarca, s.created_at, u.name AS criador
    FROM prazos_suspensoes s
    LEFT JOIN users u ON u.id = s.criado_por
    WHERE (
        s.motivo LIKE 'Suspens%o de prazos%'
        OR (
            (s.ato_legislacao IS NULL OR s.ato_legislacao = '')
            AND s.tipo = 'outros'
        )
    )
    AND s.data_inicio >= '2026-01-01'
    ORDER BY s.created_at DESC, s.data_inicio
");

$rows = $st->fetchAll();
if (!$rows) { echo "  (nenhuma)\n"; exit; }

// Agrupa por timestamp de criação (lotes)
$porLote = array();
foreach ($rows as $r) {
    $lote = $r['created_at'] ?? 'desconhecido';
    if (!isset($porLote[$lote])) $porLote[$lote] = array();
    $porLote[$lote][] = $r;
}

foreach ($porLote as $lote => $items) {
    echo "─── Lote criado em $lote (" . count($items) . " entradas) ───\n";
    echo "    por " . ($items[0]['criador'] ?? 'desconhecido') . "\n";
    foreach ($items as $r) {
        $periodo = ($r['data_inicio'] === $r['data_fim'])
            ? $r['data_inicio']
            : $r['data_inicio'] . '..' . $r['data_fim'];
        $dow = array('dom','seg','ter','qua','qui','sex','sab')[(int)(new DateTime($r['data_inicio']))->format('w')];
        $ato = $r['ato_legislacao'] ?: '— sem ato —';
        $abr = $r['abrangencia'] . ($r['comarca'] ? '/' . $r['comarca'] : '');
        echo "    #" . str_pad($r['id'], 4) . "  $periodo ($dow)  [$abr]  motivo='{$r['motivo']}'  ato='$ato'\n";
    }
    echo "\n";
}

echo "Total: " . count($rows) . " suspensoes suspeitas em " . count($porLote) . " lote(s).\n";
echo "\nPra apagar todas de UM lote, anote o timestamp e use o migrar.\n";
