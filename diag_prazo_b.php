<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(120);
ob_implicit_flush(true);
ob_end_flush();

$pdo = db();
echo "HOJE: " . date('Y-m-d') . "\n\n";

function t($label, $fn) {
    $t0 = microtime(true);
    try {
        $r = $fn();
        $ms = round((microtime(true) - $t0) * 1000);
        echo "[$ms ms] $label -> " . (is_array($r) ? count($r).' linhas' : $r) . "\n";
        return $r;
    } catch (Throwable $e) {
        $ms = round((microtime(true) - $t0) * 1000);
        echo "[$ms ms] $label -> ERRO: " . $e->getMessage() . "\n";
        return null;
    }
}

echo "== só agenda_eventos, sem JOIN ==\n";
$a = t("agenda_eventos prazos <=+3d", function() use ($pdo) {
    $s = $pdo->prepare("SELECT id, titulo, DATE(data_inicio) prazo_fatal, case_id, client_id FROM agenda_eventos WHERE tipo='prazo' AND status NOT IN ('cancelado','realizado','concluido') AND DATE(data_inicio) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $s->execute();
    return $s->fetchAll(PDO::FETCH_ASSOC);
});
if ($a) foreach ($a as $r) echo "  id={$r['id']} | {$r['prazo_fatal']} | case_id={$r['case_id']} | titulo: {$r['titulo']}\n";

echo "\n== só prazos_processuais ==\n";
$b = t("prazos_processuais pendentes <=+3d", function() use ($pdo) {
    $s = $pdo->prepare("SELECT id, descricao_acao, prazo_fatal, case_id FROM prazos_processuais WHERE concluido=0 AND prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $s->execute();
    return $s->fetchAll(PDO::FETCH_ASSOC);
});
if ($b) foreach ($b as $r) echo "  id={$r['id']} | {$r['prazo_fatal']} | {$r['descricao_acao']}\n";

echo "\n== JOIN cases ==\n";
t("agenda + JOIN cases", function() use ($pdo) {
    $s = $pdo->prepare("
        SELECT ae.id, ae.titulo, cs.title AS case_title, cs.case_number AS cnj
        FROM agenda_eventos ae
        LEFT JOIN cases cs ON cs.id = ae.case_id
        WHERE ae.tipo='prazo' AND ae.status NOT IN ('cancelado','realizado','concluido')
          AND DATE(ae.data_inicio) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $s->execute(); return $s->fetchAll(PDO::FETCH_ASSOC);
});

echo "\n== JOIN cases + clients + users (igual banner) ==\n";
t("agenda + JOIN 3", function() use ($pdo) {
    $s = $pdo->prepare("
        SELECT ae.id, ae.titulo, cs.title, cl.name, u.name AS resp
        FROM agenda_eventos ae
        LEFT JOIN cases cs ON cs.id = ae.case_id
        LEFT JOIN clients cl ON cl.id = ae.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE ae.tipo='prazo' AND ae.status NOT IN ('cancelado','realizado','concluido')
          AND DATE(ae.data_inicio) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $s->execute(); return $s->fetchAll(PDO::FETCH_ASSOC);
});

echo "\n== UNION ALL completo (igual do banner) ==\n";
$c = t("UNION ALL banner completo", function() use ($pdo) {
    $s = $pdo->prepare("SELECT * FROM (
        SELECT p.id, p.descricao_acao, p.prazo_fatal, p.numero_processo, p.case_id,
               cs.title AS case_title, cs.case_number AS case_cnj, cs.comarca, cs.comarca_uf, cs.court AS vara,
               cl.name AS client_name, u.name AS responsavel_name, 'prazo' AS __origem
        FROM prazos_processuais p
        LEFT JOIN cases cs ON cs.id = p.case_id
        LEFT JOIN clients cl ON cl.id = p.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE p.concluido = 0 AND p.prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        UNION ALL
        SELECT ae.id, ae.titulo, DATE(ae.data_inicio), cs.case_number, ae.case_id,
               cs.title, cs.case_number, cs.comarca, cs.comarca_uf, cs.court,
               cl.name, u.name, 'agenda'
        FROM agenda_eventos ae
        LEFT JOIN cases cs ON cs.id = ae.case_id
        LEFT JOIN clients cl ON cl.id = ae.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE ae.tipo = 'prazo' AND ae.status NOT IN ('cancelado','realizado','concluido')
          AND DATE(ae.data_inicio) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ) un ORDER BY prazo_fatal ASC LIMIT 15");
    $s->execute(); return $s->fetchAll(PDO::FETCH_ASSOC);
});
if ($c) foreach ($c as $r) echo "  origem={$r['__origem']} | id={$r['id']} | {$r['prazo_fatal']} | {$r['descricao_acao']}\n";

echo "\n== arquivo layout_start.php ==\n";
$ls = file_get_contents(__DIR__ . '/templates/layout_start.php');
echo "mtime: " . date('Y-m-d H:i:s', filemtime(__DIR__.'/templates/layout_start.php')) . "\n";
echo "tem UNION ALL: " . (strpos($ls,'UNION ALL')!==false?'sim':'NAO') . "\n";
echo "tem FROM agenda_eventos ae: " . (strpos($ls,'FROM agenda_eventos ae')!==false?'sim':'NAO') . "\n";
$sw = file_get_contents(__DIR__ . '/sw.js');
if (preg_match("/CACHE_NAME\s*=\s*['\"]([^'\"]+)/", $sw, $m)) echo "CACHE_NAME: {$m[1]}\n";
