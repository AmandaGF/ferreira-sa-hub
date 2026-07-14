<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== CASE Alane Santos Oliveira ===\n";
$c = $pdo->query("SELECT id, title, status, case_number, updated_at, jorjao_distribuicao_tocado FROM cases WHERE case_number = '3002672-07.2026.8.19.0068' OR title LIKE '%Alane Santos%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($c as $r) print_r($r);

echo "\n=== Audit_log processo_distribuido do case dela hoje ===\n";
$caseIds = array_map(function($x){return $x['id'];}, $c);
if ($caseIds) {
    $in = implode(',', array_map('intval', $caseIds));
    foreach ($pdo->query("SELECT al.created_at, al.action, al.entity_id, al.details, u.name
                          FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
                          WHERE al.entity_id IN ($in) AND al.entity_type='case'
                            AND DATE(al.created_at) = CURDATE()
                          ORDER BY al.created_at DESC") as $r) {
        printf("  %s %s user=%s %s\n", $r['created_at'], $r['action'], $r['name'], substr($r['details']??'',0,120));
    }
}

echo "\n=== Log jorjao_log_peticao_distribuida COMPLETO hoje ===\n";
$log = $pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_log_peticao_distribuida'")->fetchColumn();
$logJs = json_decode($log, true) ?: array();
foreach ($logJs as $e) {
    if (strpos($e['em']??'', date('Y-m-d')) === 0) {
        $ctx = $e['ctx'] ?? array();
        printf("  %s cliente=%s tipo=%s via_ia=%s\n", $e['em'], ($ctx['cliente']??''), ($ctx['tipo_caso']??''), ($e['via_ia']?'sim':'nao'));
    }
}

echo "\n=== Msgs enviadas no grupo canal 24 entre 12:50 e 13:15 hoje ===\n";
foreach ($pdo->query("SELECT m.id, m.direcao, m.created_at, LEFT(m.conteudo, 150) preview
                      FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                      WHERE co.telefone LIKE '%120363382%'
                        AND m.direcao='enviada'
                        AND m.created_at BETWEEN CONCAT(CURDATE(),' 12:50:00') AND CONCAT(CURDATE(),' 13:15:00')
                      ORDER BY m.created_at ASC") as $r) {
    printf("  #%d %s\n     %s\n", $r['id'], $r['created_at'], $r['preview']);
}
