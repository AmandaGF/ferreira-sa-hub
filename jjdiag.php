<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Cases distribuídos hoje (DATE=CURDATE) ===\n";
$st = $pdo->query("SELECT cs.id, cs.title, cs.status, cs.case_number, cs.updated_at, cs.jorjao_distribuicao_tocado, u.name AS resp
                   FROM cases cs LEFT JOIN users u ON u.id = cs.responsible_user_id
                   WHERE DATE(cs.updated_at) = CURDATE()
                     AND (cs.status = 'distribuido' OR cs.status = 'em_andamento')
                   ORDER BY cs.updated_at DESC LIMIT 20");
foreach ($st as $r) {
    printf("  #%d %s status=%s tocado=%s upd=%s\n     '%s' resp=%s\n",
        $r['id'], $r['case_number']?:'sem CNJ', $r['status'], $r['jorjao_distribuicao_tocado'],
        $r['updated_at'], substr($r['title'],0,60), $r['resp']);
}

echo "\n=== Audit log processo_distribuido hoje ===\n";
$st = $pdo->query("SELECT al.created_at, al.entity_id, al.details, u.name
                   FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
                   WHERE al.action = 'processo_distribuido' AND DATE(al.created_at) = CURDATE()
                   ORDER BY al.created_at DESC LIMIT 20");
foreach ($st as $r) {
    printf("  %s user=%s case#%d %s\n", $r['created_at'], $r['name'], $r['entity_id'], substr($r['details']??'',0,80));
}

echo "\n=== Audit log Jorjão hoje (falhou/erro/tocado) ===\n";
$st = $pdo->query("SELECT al.created_at, al.action, al.entity_id, al.details, u.name
                   FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
                   WHERE al.action LIKE '%jorjao%' AND DATE(al.created_at) = CURDATE()
                   ORDER BY al.created_at DESC LIMIT 20");
foreach ($st as $r) {
    printf("  %s %s case#%d user=%s %s\n", $r['created_at'], $r['action'], $r['entity_id'], $r['name'], substr($r['details']??'',0,150));
}

echo "\n=== Killswitch da tocada peticao_distribuida ===\n";
$k = $pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_peticao_distribuida_ativo'")->fetchColumn();
echo "  jorjao_peticao_distribuida_ativo = " . var_export($k, true) . "\n";

echo "\n=== Últimas msgs enviadas pelo Jorjão no canal 24 grupo hoje ===\n";
$st = $pdo->query("SELECT m.id, m.status, m.created_at, LEFT(m.conteudo, 100) AS preview, co.telefone, co.nome_contato
                   FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                   WHERE co.canal='24' AND m.direcao='enviada'
                     AND DATE(m.created_at) = CURDATE()
                     AND (m.conteudo LIKE '%Jorjão%' OR m.conteudo LIKE '%PETIÇÃO NO MUNDO%' OR m.conteudo LIKE '%GOLAÇO%' OR m.conteudo LIKE '%processo distribu%' OR m.conteudo LIKE '%🎯%')
                   ORDER BY m.created_at DESC LIMIT 10");
$rows = $st->fetchAll();
if (empty($rows)) echo "  (nenhuma msg do estilo Jorjão hoje)\n";
foreach ($rows as $r) printf("  #%d %s status=%s tel=%s (%s)\n     %s\n", $r['id'], $r['created_at'], $r['status'], $r['telefone'], substr($r['nome_contato']??'',0,25), $r['preview']);

echo "\n=== Config do grupo Jorjão ===\n";
foreach ($pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'jorjao_%' AND chave NOT LIKE '%modo_ia%' AND chave NOT LIKE '%templates%'") as $r) {
    printf("  %s = %s\n", $r['chave'], $r['valor']);
}
