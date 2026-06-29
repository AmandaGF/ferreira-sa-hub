<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== Quem criou o compromisso 'Confirmar audiência presencial + audiencista' (29/06/2026 17:00) ===\n\n";

$st = $pdo->prepare("
    SELECT ae.id, ae.titulo, ae.tipo, ae.data_inicio, ae.data_fim, ae.modalidade,
           ae.created_by, ae.created_at, ae.responsavel_id, ae.updated_at,
           uc.name AS criado_por, ur.name AS responsavel,
           ae.case_id, c.title AS case_title
    FROM agenda_eventos ae
    LEFT JOIN users uc ON uc.id = ae.created_by
    LEFT JOIN users ur ON ur.id = ae.responsavel_id
    LEFT JOIN cases c  ON c.id  = ae.case_id
    WHERE (ae.titulo LIKE '%Confirmar audiência presencial%audiencista%'
        OR ae.titulo LIKE '%Confirmar audi%audiencista%')
      AND DATE(ae.data_inicio) = '2026-06-29'
    ORDER BY ae.id DESC
    LIMIT 5
");
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nenhum evento encontrado com esse título pra 29/06/2026. Busca mais ampla:\n\n";
    $st2 = $pdo->prepare("
        SELECT ae.id, ae.titulo, ae.data_inicio, uc.name AS criado_por, ae.created_at,
               ae.case_id, c.title AS case_title
        FROM agenda_eventos ae
        LEFT JOIN users uc ON uc.id = ae.created_by
        LEFT JOIN cases c ON c.id = ae.case_id
        WHERE DATE(ae.data_inicio) = '2026-06-29'
          AND ae.tipo = 'audiencia'
        ORDER BY ae.data_inicio
    ");
    $st2->execute();
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "id={$r['id']} | {$r['data_inicio']} | criado_por={$r['criado_por']} em {$r['created_at']}\n";
        echo "  titulo: {$r['titulo']}\n";
        echo "  case: {$r['case_title']} (id={$r['case_id']})\n\n";
    }
} else {
    foreach ($rows as $r) {
        echo "── Evento id={$r['id']} ──\n";
        echo "  Título:       {$r['titulo']}\n";
        echo "  Data:         {$r['data_inicio']} → {$r['data_fim']}\n";
        echo "  Tipo:         {$r['tipo']} · Modalidade: {$r['modalidade']}\n";
        echo "  Case atual:   {$r['case_title']} (id={$r['case_id']})\n";
        echo "  CRIADO POR:   {$r['criado_por']} (user_id={$r['created_by']})\n";
        echo "  CRIADO EM:    {$r['created_at']}\n";
        echo "  Responsável:  {$r['responsavel']} (user_id={$r['responsavel_id']})\n";
        echo "  Última edição (updated_at): {$r['updated_at']}\n\n";
    }
}

// Audit log de quem mexeu nesse evento (incl. alteração de case)
if (!empty($rows[0]['id'])) {
    $eid = (int)$rows[0]['id'];
    echo "── Audit log do evento (id={$eid}) ──\n";
    try {
        $stA = $pdo->prepare("
            SELECT al.created_at, al.action, al.details, u.name AS quem
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.target_id = ? AND (al.action LIKE 'AGENDA_%' OR al.target_type IN ('agenda','agenda_eventos'))
            ORDER BY al.created_at ASC LIMIT 30
        ");
        $stA->execute(array($eid));
        foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $a) {
            echo "  {$a['created_at']} · {$a['quem']} · {$a['action']} — " . mb_substr($a['details'] ?? '', 0, 100) . "\n";
        }
    } catch (Exception $e) { echo "  (audit_log indisponível: " . $e->getMessage() . ")\n"; }
}
