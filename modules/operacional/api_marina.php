<?php
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('operacional')) {
    http_response_code(403);
    echo json_encode(array('error' => 'Sem permissão'));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$dias = max(1, (int)($_GET['dias'] ?? 30));

$sql = "SELECT c.id, c.case_number, c.title, c.comarca, c.responsible_user_id,
               u.name AS responsavel_nome,
               MAX(a.data_andamento) AS ultimo_andamento,
               DATEDIFF(NOW(), MAX(a.data_andamento)) AS dias_parado
        FROM cases c
        LEFT JOIN case_andamentos a ON a.case_id = c.id
        LEFT JOIN users u ON u.id = c.responsible_user_id
        WHERE c.sistema_tribunal = 'PJe'
          AND c.comarca_uf = 'RJ'
          AND c.status NOT IN ('arquivado','cancelado','concluido','renunciamos')
          AND IFNULL(c.kanban_oculto, 0) = 0
        GROUP BY c.id
        HAVING ultimo_andamento IS NULL OR DATEDIFF(NOW(), ultimo_andamento) > ?
        ORDER BY dias_parado DESC";

$st = $pdo->prepare($sql);
$st->execute(array($dias));
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
