<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== CASES Larissa do Nascimento ===\n";
foreach ($pdo->query("SELECT id, title, case_type, status, case_number, distribution_date, jorjao_distribuicao_tocado, jorjao_distribuicao_tocado_em, created_at, updated_at
                      FROM cases WHERE title LIKE '%Larissa%Nascimento%' OR title LIKE '%Nascimento%Larissa%'
                      ORDER BY updated_at DESC") as $c) {
    printf("\n--- case #%d '%s' ---\n", $c['id'], $c['title']);
    printf("  status=%s cnj=%s distr=%s\n", $c['status'], $c['case_number']?:'-', $c['distribution_date']?:'-');
    printf("  jorjao_tocado=%s em=%s\n", $c['jorjao_distribuicao_tocado'], $c['jorjao_distribuicao_tocado_em']?:'-');
    printf("  criado=%s atualizado=%s\n", $c['created_at'], $c['updated_at']);
}

echo "\n=== ULTIMAS AUDIT jorjao/processo_distribuido ===\n";
foreach ($pdo->query("SELECT id, created_at, action, entity_type, entity_id, user_id, LEFT(details,120) det
                      FROM audit_log
                      WHERE action IN ('processo_distribuido','jorjao_toque','jorjao_sino_falhou','jorjao_sino_erro','case_updated')
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      ORDER BY id DESC LIMIT 30") as $a) {
    printf("  %s user=%s %s.%s | %s\n", $a['created_at'], $a['user_id']?:'-',
        $a['entity_type'], $a['entity_id'], $a['action'] . ' — ' . preg_replace('/\s+/', ' ', $a['det']));
}

echo "\n=== LOG do jorjao_peticao_distribuida (configuracoes) ===\n";
$log = json_decode((string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_log_peticao_distribuida'")->fetchColumn(), true) ?: array();
foreach (array_slice($log, 0, 15) as $l) {
    print_r($l);
}
