<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$s = $pdo->prepare("SELECT * FROM (
    SELECT p.id, p.descricao_acao COLLATE utf8mb4_unicode_ci AS descricao_acao, p.prazo_fatal,
           CAST('prazo' AS CHAR) COLLATE utf8mb4_unicode_ci AS __origem
    FROM prazos_processuais p
    WHERE p.concluido = 0 AND p.prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    UNION ALL
    SELECT ae.id, ae.titulo COLLATE utf8mb4_unicode_ci AS descricao_acao,
           DATE(ae.data_inicio) AS prazo_fatal,
           CAST('agenda' AS CHAR) COLLATE utf8mb4_unicode_ci AS __origem
    FROM agenda_eventos ae
    WHERE ae.tipo='prazo' AND ae.status NOT IN ('cancelado','realizado','concluido')
      AND DATE(ae.data_inicio) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
) un ORDER BY prazo_fatal ASC LIMIT 15");
try {
    $s->execute();
    $r = $s->fetchAll(PDO::FETCH_ASSOC);
    echo "OK: " . count($r) . " linhas\n";
    foreach ($r as $row) echo "  origem={$row['__origem']} | id={$row['id']} | {$row['prazo_fatal']} | {$row['descricao_acao']}\n";
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage(); }
