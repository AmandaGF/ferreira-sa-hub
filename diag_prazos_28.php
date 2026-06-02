<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

// Amanda user_id
$amanda = $pdo->query("SELECT id, name FROM users WHERE email='amandaguedesferreira@gmail.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Amanda = id={$amanda['id']} nome={$amanda['name']}\n\n";
$uid = (int)$amanda['id'];

echo "== 1) PRAZOS_PROCESSUAIS com prazo_fatal=2026-05-28 e concluido=0 ==\n";
$st = $pdo->prepare("SELECT p.id, p.descricao_acao, p.prazo_fatal, p.concluido, p.case_id, cs.title, cs.responsible_user_id
                     FROM prazos_processuais p LEFT JOIN cases cs ON cs.id = p.case_id
                     WHERE p.prazo_fatal = '2026-05-28' AND p.concluido = 0");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  pz#{$r['id']} fatal={$r['prazo_fatal']} concl={$r['concluido']} caso#{$r['case_id']} ({$r['title']}) resp={$r['responsible_user_id']}\n    descr: {$r['descricao_acao']}\n";
}

echo "\n== 2) CASE_TASKS atrasadas (due_date=2026-05-28) da Amanda ==\n";
$st2 = $pdo->prepare("SELECT t.id, t.title, t.due_date, t.status, t.tipo, t.case_id, t.assigned_to, t.assigned_extra_ids, cs.title AS case_title
                     FROM case_tasks t LEFT JOIN cases cs ON cs.id = t.case_id
                     WHERE t.due_date = '2026-05-28' AND t.status != 'concluido' AND t.tipo IS NOT NULL
                       AND (t.assigned_to = ? OR FIND_IN_SET(?, t.assigned_extra_ids))");
$st2->execute(array($uid, $uid));
foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  tk#{$r['id']} tipo={$r['tipo']} status={$r['status']} caso#{$r['case_id']} ({$r['case_title']}) assigned={$r['assigned_to']}\n    title: {$r['title']}\n";
}

echo "\n== 3) PRAZOS_PROCESSUAIS vencidos NAO concluidos (busca por nome Paula / Querino / Eduardo) ==\n";
$st3 = $pdo->prepare("SELECT p.id, p.descricao_acao, p.prazo_fatal, p.concluido, p.case_id, cs.title, cs.responsible_user_id, c.name as client
                      FROM prazos_processuais p
                      LEFT JOIN cases cs ON cs.id = p.case_id
                      LEFT JOIN clients c ON c.id = cs.client_id
                      WHERE p.concluido = 0 AND p.prazo_fatal < CURDATE()
                        AND (cs.title LIKE '%Querino%' OR cs.title LIKE '%Paula%' OR cs.title LIKE '%Eduardo%' OR cs.title LIKE '%Alimentos%' OR c.name LIKE '%Querino%' OR c.name LIKE '%Eduardo%')
                      ORDER BY p.prazo_fatal DESC LIMIT 20");
$st3->execute();
foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  pz#{$r['id']} fatal={$r['prazo_fatal']} concl={$r['concluido']} caso#{$r['case_id']} '{$r['title']}' cliente={$r['client']}\n    descr: {$r['descricao_acao']}\n";
}

echo "\n== 4) CASE_TASKS vencidas (due_date<hoje, !=concluido) com Paula/Querino/Eduardo ==\n";
$st4 = $pdo->prepare("SELECT t.id, t.title, t.due_date, t.status, t.tipo, t.case_id, cs.title AS case_title, c.name as client
                      FROM case_tasks t
                      LEFT JOIN cases cs ON cs.id = t.case_id
                      LEFT JOIN clients c ON c.id = cs.client_id
                      WHERE t.due_date < CURDATE() AND t.status != 'concluido' AND t.tipo IS NOT NULL
                        AND (cs.title LIKE '%Querino%' OR cs.title LIKE '%Paula%' OR cs.title LIKE '%Eduardo%' OR cs.title LIKE '%Alimentos%' OR c.name LIKE '%Querino%' OR c.name LIKE '%Eduardo%')
                        AND (t.assigned_to = ? OR FIND_IN_SET(?, t.assigned_extra_ids))
                      ORDER BY t.due_date DESC LIMIT 20");
$st4->execute(array($uid, $uid));
foreach ($st4->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  tk#{$r['id']} due={$r['due_date']} status={$r['status']} tipo={$r['tipo']} caso#{$r['case_id']} '{$r['case_title']}' cliente={$r['client']}\n    title: {$r['title']}\n";
}

echo "\n== 5) Briefing de HOJE da Amanda (conteudo gravado) ==\n";
$st5 = $pdo->prepare("SELECT conteudo, gerado_em FROM ia_briefings WHERE user_id = ? AND data = CURDATE()");
$st5->execute(array($uid));
$br = $st5->fetch(PDO::FETCH_ASSOC);
if ($br) {
    echo "  gerado em: {$br['gerado_em']}\n";
    echo "  ---\n" . $br['conteudo'] . "\n  ---\n";
}
