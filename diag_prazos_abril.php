<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

try {
    echo "=== Suspensoes cadastradas em abril/2026 ===\n";
    // Lista colunas pra montar SELECT *
    $cols = $pdo->query("SHOW COLUMNS FROM prazos_suspensoes")->fetchAll(PDO::FETCH_COLUMN);
    echo "  cols: " . implode(', ', $cols) . "\n";
    $st = $pdo->prepare("SELECT * FROM prazos_suspensoes
                         WHERE data_inicio <= '2026-04-30' AND data_fim >= '2026-04-01'
                         ORDER BY data_inicio");
    $st->execute();
    $rows = $st->fetchAll();
    if (!$rows) { echo "  (nenhuma)\n"; }
    foreach ($rows as $r) {
        echo '  #' . $r['id'] . '  ' . $r['data_inicio'] . '..' . $r['data_fim']
           . '  [' . ($r['abrangencia'] ?? '?') . (!empty($r['comarca']) ? '/' . $r['comarca'] : '') . ']'
           . '  req_conf=' . (int)($r['requer_confirmacao'] ?? 0)
           . '  tipo=' . ($r['tipo'] ?? '?')
           . '  motivo=' . ($r['motivo'] ?? '?')
           . '  ato=' . ($r['ato_legislacao'] ?? '?') . "\n";
    }

    echo "\n=== Analise dia 14 a 18 de abril 2026 (comarca=Resende) ===\n";
    require_once __DIR__ . '/core/functions_prazos.php';
    $dows = array('dom','seg','ter','qua','qui','sex','sab');
    foreach (array('2026-04-14','2026-04-15','2026-04-16','2026-04-17','2026-04-18') as $d) {
        $dt = new DateTime($d);
        $dow = $dows[(int)$dt->format('w')];
        $util = is_dia_util($d, 'Resende');
        $susp = is_dia_suspenso($d, 'Resende');
        echo '  ' . $d . ' (' . $dow . '): util=' . ($util ? 'SIM' : 'nao')
           . '  suspenso=' . ($susp ? 'SIM' : 'nao') . "\n";
    }

    echo "\n=== proximo_dia_util(2026-04-15, Resende) ===\n";
    echo '  ' . proximo_dia_util('2026-04-15', 'Resende') . "\n";
} catch (Throwable $e) {
    echo "\n!! ERRO: " . $e->getMessage() . " em " . $e->getFile() . ':' . $e->getLine() . "\n";
}
