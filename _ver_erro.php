<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');

// Tenta ver o error log padrão
$logs = array(
    dirname(__DIR__) . '/logs/error_log',
    dirname(__DIR__) . '/error_log',
    __DIR__ . '/error_log',
    ini_get('error_log'),
);
foreach ($logs as $l) {
    if ($l && is_file($l)) {
        echo "=== $l ===\n";
        $conteudo = @file_get_contents($l);
        echo substr($conteudo, -4000) . "\n\n";
    } else {
        echo "  (nao existe: $l)\n";
    }
}

// Tenta forçar erro do painel
echo "\n=== TENTANDO INCLUDE PAINEL ===\n";
ini_set('display_errors', '1');
error_reporting(E_ALL);
try {
    // simulate a login basico
    session_start();
    require_once __DIR__ . '/core/config.php';
    require_once __DIR__ . '/core/database.php';
    require_once __DIR__ . '/core/functions.php';
    $pdo = db();
    $userId = 1; // admin

    // testa o dopaSomaDesde novo
    $sql = "SELECT COALESCE(SUM(c),0) FROM (
        SELECT COUNT(*) c FROM case_tasks WHERE status='concluido' AND assigned_to=? AND completed_at>=?
        UNION ALL SELECT COUNT(*) c FROM prazos_processuais WHERE concluido=1 AND usuario_id=? AND concluido_em>=?
        UNION ALL SELECT COUNT(*) c FROM audit_log al LEFT JOIN agenda_eventos ae ON ae.id=al.entity_id WHERE al.entity_type='agenda' AND al.user_id=? AND al.created_at>=? AND al.action='AGENDA_STATUS' AND al.details LIKE 'Status: realizado%' AND COALESCE(ae.tipo,'') NOT IN ('onboarding','balcao_virtual')
        UNION ALL SELECT COUNT(DISTINCT a.entity_id) c FROM audit_log a JOIN tickets t ON t.id=a.entity_id WHERE a.action='ticket_updated' AND a.entity_type='ticket' AND a.user_id=? AND a.created_at>=? AND t.status='resolvido'
        UNION ALL SELECT COUNT(*)*2 c FROM audit_log WHERE action='processo_distribuido' AND entity_type='case' AND user_id=? AND created_at>=?
        UNION ALL SELECT COUNT(*) c FROM audit_log WHERE action='ANDAMENTO_CRIADO' AND entity_type='case' AND user_id=? AND created_at>=?
        UNION ALL SELECT COUNT(DISTINCT m.conversa_id) c FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id WHERE m.enviado_por_id=? AND m.created_at>=? AND co.canal='21'
        UNION ALL SELECT COUNT(DISTINCT m.conversa_id) c FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id WHERE m.enviado_por_id=? AND m.created_at>=? AND co.canal='24'
        UNION ALL SELECT COUNT(*) c FROM gerid_pesquisas WHERE status='concluida' AND pesquisado_por=? AND pesquisado_em>=?
        UNION ALL SELECT COUNT(*) c FROM audit_log WHERE action='renuncia_tarefa_baixa' AND user_id=? AND created_at>=?
        UNION ALL SELECT COUNT(*) c FROM audit_log WHERE action='lead_moved' AND entity_type='lead' AND user_id=? AND created_at>=? AND details LIKE '% -> pasta_apta'
        UNION ALL SELECT COUNT(*) c FROM audit_log al INNER JOIN agenda_eventos ae ON ae.id=al.entity_id WHERE al.action='AGENDA_STATUS' AND al.entity_type='agenda' AND al.details LIKE 'Status: realizado%' AND ae.tipo='onboarding' AND al.user_id=? AND al.created_at>=?
        UNION ALL SELECT COUNT(*) c FROM audit_log WHERE action='AGENDA_BALCAO_REALIZADO' AND entity_type='agenda' AND user_id=? AND created_at>=?
        UNION ALL SELECT COUNT(DISTINCT entity_id) c FROM audit_log WHERE action='entrega_puxada' AND entity_type='case' AND user_id=? AND created_at>=?
    ) u";

    $unionCount = substr_count($sql, 'UNION ALL') + 1;
    $placeholders = substr_count($sql, '?');
    echo "UNION ALL: $unionCount (esperado 14)\n";
    echo "Placeholders ?: $placeholders (esperado 28)\n";

    $params = array_fill(0, $placeholders, '2026-01-01');
    for ($i=0;$i<$placeholders;$i+=2) { $params[$i] = 1; }
    $q = $pdo->prepare($sql);
    $q->execute($params);
    echo "Query dopaSomaDesde OK: " . $q->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "em " . $e->getFile() . ":" . $e->getLine() . "\n";
}
