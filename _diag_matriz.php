<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== TODAS as regras da matriz (raw do banco) ===\n\n";
$sql = "SELECT r.id, r.perfil_id, p.nome AS perfil, r.fase_id, f.nome AS fase,
               r.brinde_id, b.nome AS brinde, r.frase_id, r.verba_prevista, r.ativo, r.perfil_id||'_'||r.fase_id AS chave
        FROM presenca_regra r
        LEFT JOIN presenca_perfil p ON p.id = r.perfil_id
        LEFT JOIN presenca_fase f ON f.id = r.fase_id
        LEFT JOIN presenca_brinde b ON b.id = r.brinde_id
        ORDER BY r.perfil_id, r.fase_id";
foreach ($pdo->query($sql) as $r) {
    printf("id=%-3d | %-10s × %-25s | verba=R$ %-8s | brinde=%-30s | ativo=%d\n",
        $r['id'], $r['perfil'], $r['fase'], number_format((float)$r['verba_prevista'], 2, ',', '.'),
        mb_substr($r['brinde'] ?: '(nenhum)', 0, 30), $r['ativo']);
}

echo "\n=== Auditoria recente (ultimas mudancas na matriz) ===\n\n";
$logs = $pdo->query("
    SELECT created_at, user_id, action, entity_id, details
    FROM audit_log
    WHERE entity_type = 'presenca_regra'
    ORDER BY id DESC LIMIT 20
")->fetchAll();
foreach ($logs as $l) {
    printf("%s | user=%s | %s | id=%s | %s\n", $l['created_at'], $l['user_id'], $l['action'], $l['entity_id'], $l['details']);
}
