<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_jorjao.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$caseId = 954;
$st = $pdo->prepare("SELECT cs.id, cs.title, cs.status, cs.case_number, cs.case_type,
    cs.client_id, cs.responsible_user_id, cs.created_at, cs.updated_at,
    c.name AS client_name FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id WHERE cs.id = ?");
$st->execute(array($caseId));
$caseData = $st->fetch(PDO::FETCH_ASSOC);
if (!$caseData) { echo "case #$caseId nao existe\n"; exit; }
print_r($caseData);

if (empty($_GET['confirmar'])) { echo "\nAdicione &confirmar=1 pra tocar\n"; exit; }

$r = jorjao_peticao_distribuida($caseData);
echo "\nResultado:\n"; print_r($r);
$pdo->prepare("UPDATE cases SET jorjao_distribuicao_tocado = 1 WHERE id = ?")->execute(array($caseId));
audit_log('jorjao_sino_retroativo', 'case', $caseId, 'Marcelo Cosme — retroativo pra Amanda');
