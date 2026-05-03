<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Suspensões cadastradas em abril/2026 ===\n";
$st = $pdo->prepare("SELECT id, descricao, data_inicio, data_fim, abrangencia, comarca, requer_confirmacao
                     FROM prazos_suspensoes
                     WHERE data_inicio <= '2026-04-30' AND data_fim >= '2026-04-01'
                     ORDER BY data_inicio");
$st->execute();
foreach ($st->fetchAll() as $r) {
    printf("  #%-4d %s..%s  [%s%s]  req_conf=%d  %s\n",
        $r['id'],
        $r['data_inicio'], $r['data_fim'],
        $r['abrangencia'],
        $r['comarca'] ? '/' . $r['comarca'] : '',
        (int)$r['requer_confirmacao'],
        $r['descricao']);
}

echo "\n=== Análise dia 14 a 18 de abril 2026 (comarca=Resende) ===\n";
require_once __DIR__ . '/core/functions_prazos.php';
foreach (array('2026-04-14','2026-04-15','2026-04-16','2026-04-17','2026-04-18') as $d) {
    $dt = new DateTime($d);
    $dow = array('dom','seg','ter','qua','qui','sex','sab')[(int)$dt->format('w')];
    $util = is_dia_util($d, 'Resende');
    $susp = is_dia_suspenso($d, 'Resende');
    printf("  %s (%s): util=%s  suspenso=%s\n", $d, $dow, $util?'SIM':'nao', $susp?'SIM':'nao');
}
