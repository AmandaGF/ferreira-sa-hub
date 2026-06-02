<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$uid = 1; // Amanda
echo "Diagnostico contexto briefing - Amanda user#$uid\n\n";

// Reproduz exatamente o que functions_ia.php envia pra IA
echo "=== 1) AGENDA HOJE ===\n";
$st = $pdo->prepare("SELECT titulo, tipo, data_inicio, modalidade, local, cliente_presencial FROM agenda_eventos WHERE (responsavel_id = ? OR participantes_ids LIKE ?) AND DATE(data_inicio) = CURDATE() AND status NOT IN ('cancelado','realizado') ORDER BY data_inicio ASC LIMIT 10");
$st->execute(array($uid, '%\"'.$uid.'\"%'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== 2) PRAZOS PROXIMOS 5 DIAS ===\n";
$st = $pdo->prepare("SELECT p.descricao_acao, p.prazo_fatal, cs.title, cs.id AS case_id FROM prazos_processuais p LEFT JOIN cases cs ON cs.id = p.case_id WHERE p.concluido = 0 AND p.prazo_fatal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY) AND (cs.responsible_user_id = ? OR cs.responsible_user_id IS NULL) ORDER BY p.prazo_fatal ASC LIMIT 8");
$st->execute(array($uid));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== 3) INTIMACOES PENDENTES ===\n";
$st = $pdo->query("SELECT cp.tipo_publicacao, cp.resumo_ia, cs.title, cp.data_disponibilizacao FROM case_publicacoes cp INNER JOIN cases cs ON cs.id = cp.case_id WHERE cp.status_prazo = 'pendente' ORDER BY cp.data_disponibilizacao DESC LIMIT 6");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== 4) ANDAMENTOS URGENTES ===\n";
$st = $pdo->prepare("SELECT ca.data_andamento, ca.descricao, cs.title FROM case_andamentos ca INNER JOIN cases cs ON cs.id = ca.case_id WHERE ca.urgencia_ia = 'urgente' AND ca.created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY) AND (cs.responsible_user_id = ? OR cs.responsible_user_id IS NULL) ORDER BY ca.created_at DESC LIMIT 6");
$st->execute(array($uid));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== 5) CLIENTES ESFRIANDO ===\n";
$st = $pdo->query("SELECT name, esfriando_score, esfriando_motivos FROM clients WHERE COALESCE(esfriando_score,0) >= 60 AND (esfriando_snooze_ate IS NULL OR esfriando_snooze_ate < CURDATE()) ORDER BY esfriando_score DESC LIMIT 5");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== 6) TAREFAS ATRASADAS DA AMANDA ===\n";
$st = $pdo->prepare("SELECT t.id, t.title, t.due_date, t.case_id, cs.title AS case_title, cs.case_number, t.tipo FROM case_tasks t INNER JOIN cases cs ON cs.id = t.case_id WHERE t.tipo IS NOT NULL AND t.status != 'concluido' AND t.due_date IS NOT NULL AND t.due_date < CURDATE() AND (t.assigned_to = ? OR FIND_IN_SET(?, t.assigned_extra_ids)) ORDER BY t.due_date ASC LIMIT 8");
$st->execute(array($uid, $uid));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== 7) BUSCA PAULA QUERINO em qualquer lugar ===\n";
echo "-- clients --\n";
foreach ($pdo->query("SELECT id, name, phone FROM clients WHERE name LIKE '%Querino%' OR name LIKE '%Paula%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r) . "\n";
echo "-- cases --\n";
foreach ($pdo->query("SELECT id, title, case_number, status, responsible_user_id FROM cases WHERE title LIKE '%Querino%' OR title LIKE '%Paula%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r) . "\n";

echo "\n=== 8) BUSCA LUIZ EDUARDO ===\n";
echo "-- clients --\n";
foreach ($pdo->query("SELECT id, name, phone FROM clients WHERE name LIKE '%Luiz Eduardo%' OR name LIKE '%Eduardo de Sa%' OR name LIKE '%Marcelino%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r) . "\n";
echo "-- cases --\n";
foreach ($pdo->query("SELECT id, title, case_number, status, responsible_user_id FROM cases WHERE title LIKE '%Luiz Eduardo%' OR title LIKE '%Marcelino%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r) . "\n";

echo "\n=== 9) Briefing salvo de HOJE ===\n";
$st = $pdo->prepare("SELECT conteudo, gerado_em FROM ia_briefings WHERE user_id = ? AND data = CURDATE()");
$st->execute(array($uid));
$b = $st->fetch(PDO::FETCH_ASSOC);
if ($b) echo "  gerado: {$b['gerado_em']}\n  --\n{$b['conteudo']}\n  --\n";
