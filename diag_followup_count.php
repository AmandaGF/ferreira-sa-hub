<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Replica a lógica do followup, sem LIMIT
$sql = "SELECT COUNT(*) FROM zapi_conversas co
        JOIN (
            SELECT m.conversa_id, m.id, m.direcao, m.created_at
            FROM zapi_mensagens m
            JOIN (SELECT conversa_id, MAX(id) AS maxid FROM zapi_mensagens GROUP BY conversa_id) x
              ON x.conversa_id = m.conversa_id AND x.maxid = m.id
        ) lm ON lm.conversa_id = co.id
        LEFT JOIN comercial_lead_obs lo ON lo.conversa_id = co.id
        WHERE co.canal = '21'
          AND (co.eh_grupo = 0 OR co.eh_grupo IS NULL)
          AND co.status NOT IN ('resolvido','arquivado')
          AND lm.direcao = 'enviada'
          AND co.created_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)
          AND (lo.status IS NULL OR lo.status != 'resolvido')";
$total = (int)$pdo->query($sql)->fetchColumn();
echo "TOTAL follow-up REAL (sem LIMIT, sem resolvido): $total\n";

// Com aquecendo separado
$aq = (int)$pdo->query("SELECT COUNT(*) FROM comercial_lead_obs WHERE status='aquecendo'")->fetchColumn();
echo "Aquecendo fixados: $aq\n";

$res = (int)$pdo->query("SELECT COUNT(*) FROM comercial_lead_obs WHERE status='resolvido'")->fetchColumn();
echo "Marcados resolvido: $res\n";

// Status na conversa em si
$resCo = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE canal='21' AND status IN ('resolvido','arquivado')")->fetchColumn();
echo "Conversas com status resolvido/arquivado (zapi_conversas): $resCo\n";

// Quantas tem ultima_em > 7 dias atras (deveriam ter sumido naturalmente se respondem)
$st = $pdo->query("SELECT
    SUM(CASE WHEN DATEDIFF(NOW(), lm.created_at) <= 7 THEN 1 ELSE 0 END) AS ultima_semana,
    SUM(CASE WHEN DATEDIFF(NOW(), lm.created_at) BETWEEN 8 AND 30 THEN 1 ELSE 0 END) AS ate_30,
    SUM(CASE WHEN DATEDIFF(NOW(), lm.created_at) > 30 THEN 1 ELSE 0 END) AS mais_30
    FROM zapi_conversas co
    JOIN (SELECT m.conversa_id, m.id, m.direcao, m.created_at FROM zapi_mensagens m JOIN (SELECT conversa_id, MAX(id) AS maxid FROM zapi_mensagens GROUP BY conversa_id) x ON x.conversa_id = m.conversa_id AND x.maxid = m.id) lm ON lm.conversa_id = co.id
    LEFT JOIN comercial_lead_obs lo ON lo.conversa_id = co.id
    WHERE co.canal='21' AND (co.eh_grupo=0 OR co.eh_grupo IS NULL) AND co.status NOT IN ('resolvido','arquivado')
    AND lm.direcao='enviada' AND co.created_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)
    AND (lo.status IS NULL OR lo.status != 'resolvido')")->fetch(PDO::FETCH_ASSOC);
echo "\nDistribuição por tempo da última msg nossa:\n";
echo "  Última semana: " . $st['ultima_semana'] . "\n";
echo "  8-30 dias: " . $st['ate_30'] . "\n";
echo "  > 30 dias: " . $st['mais_30'] . "\n";
