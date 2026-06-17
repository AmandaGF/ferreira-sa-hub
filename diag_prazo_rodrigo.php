<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
echo "=== HOJE: " . date('Y-m-d H:i:s') . " ===\n\n";

echo "--- 1. agenda_eventos tipo='prazo' nos proximos 5 dias ---\n";
$rs = $pdo->query("
    SELECT id, tipo, titulo, data_inicio, status, case_id, client_id, created_at
    FROM agenda_eventos
    WHERE tipo = 'prazo'
      AND DATE(data_inicio) BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY data_inicio ASC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rs as $r) {
    echo "id={$r['id']} | data={$r['data_inicio']} | status='{$r['status']}' | case_id={$r['case_id']} | titulo: {$r['titulo']}\n";
}
echo "TOTAL: " . count($rs) . "\n\n";

echo "--- 2. Eventos do case Rodrigo Penha (busca pelo titulo) ---\n";
$rs = $pdo->query("
    SELECT cs.id, cs.title, cs.case_number
    FROM cases cs
    WHERE cs.title LIKE '%Rodrigo Penha%' OR cs.title LIKE '%rodrigo penha%'
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rs as $c) {
    echo "case_id={$c['id']} | titulo: {$c['title']} | cnj: {$c['case_number']}\n";
    $evts = $pdo->prepare("SELECT id, tipo, titulo, data_inicio, status FROM agenda_eventos WHERE case_id=? ORDER BY data_inicio DESC LIMIT 10");
    $evts->execute(array($c['id']));
    foreach ($evts->fetchAll(PDO::FETCH_ASSOC) as $e) {
        echo "  >> evt id={$e['id']} tipo={$e['tipo']} data={$e['data_inicio']} status='{$e['status']}' titulo: {$e['titulo']}\n";
    }
}
echo "\n";

echo "--- 3. TODOS status distintos em agenda_eventos tipo='prazo' ---\n";
$rs = $pdo->query("SELECT DISTINCT status, COUNT(*) c FROM agenda_eventos WHERE tipo='prazo' GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rs as $r) echo "status='{$r['status']}' -> {$r['c']}\n";
echo "\n";

echo "--- 4. EXATA query do banner (urg-banner) ---\n";
$stmt = $pdo->prepare("SELECT * FROM (
    SELECT p.id, p.descricao_acao, p.prazo_fatal, p.numero_processo, p.case_id,
           cs.title AS case_title, cs.case_number AS case_cnj, cs.comarca, cs.comarca_uf, cs.court AS vara,
           cl.name AS client_name,
           u.name AS responsavel_name,
           'prazo' AS __origem
    FROM prazos_processuais p
    LEFT JOIN cases cs ON cs.id = p.case_id
    LEFT JOIN clients cl ON cl.id = p.client_id
    LEFT JOIN users u ON u.id = cs.responsible_user_id
    WHERE p.concluido = 0 AND p.prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    UNION ALL
    SELECT ae.id, ae.titulo AS descricao_acao,
           DATE(ae.data_inicio) AS prazo_fatal,
           cs.case_number AS numero_processo,
           ae.case_id,
           cs.title AS case_title, cs.case_number AS case_cnj, cs.comarca, cs.comarca_uf, cs.court AS vara,
           cl.name AS client_name,
           u.name AS responsavel_name,
           'agenda' AS __origem
    FROM agenda_eventos ae
    LEFT JOIN cases cs ON cs.id = ae.case_id
    LEFT JOIN clients cl ON cl.id = ae.client_id
    LEFT JOIN users u ON u.id = cs.responsible_user_id
    WHERE ae.tipo = 'prazo'
      AND ae.status NOT IN ('cancelado','realizado','concluido')
      AND DATE(ae.data_inicio) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
) un
ORDER BY prazo_fatal ASC
LIMIT 15");
$stmt->execute();
$rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "QUERY RETORNOU " . count($rs) . " linhas:\n";
foreach ($rs as $r) {
    echo "  origem={$r['__origem']} | id={$r['id']} | prazo_fatal={$r['prazo_fatal']} | case={$r['case_title']} | titulo: {$r['descricao_acao']}\n";
}
echo "\n";

echo "--- 5. Versao do arquivo layout_start.php no servidor ---\n";
$path = __DIR__ . '/templates/layout_start.php';
echo "mtime: " . date('Y-m-d H:i:s', filemtime($path)) . "\n";
$haystack = file_get_contents($path);
$hasUnion = strpos($haystack, 'UNION ALL') !== false ? 'SIM' : 'NAO';
$hasAgenda = strpos($haystack, 'FROM agenda_eventos ae') !== false ? 'SIM' : 'NAO';
echo "Tem UNION ALL: $hasUnion\n";
echo "Tem FROM agenda_eventos ae: $hasAgenda\n";

echo "\n--- 6. Versao do CACHE_NAME no sw.js ---\n";
$sw = file_get_contents(__DIR__ . '/sw.js');
if (preg_match('/CACHE_NAME\s*=\s*[\'"]([^\'"]+)[\'"]/', $sw, $m)) {
    echo "CACHE_NAME = {$m[1]}\n";
}
