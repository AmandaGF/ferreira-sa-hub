<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== BUSCA POR CINTHIA MARA ===\n";
foreach ($pdo->query("SELECT id, title, client_id, case_number, status, updated_at, distribution_date
                      FROM cases
                      WHERE title LIKE '%Cinthia%' OR title LIKE '%Cynthia%' OR title LIKE '%Cintia%'
                      ORDER BY updated_at DESC") as $c) {
    printf("\n--- case #%d '%s' status=%s cnj=%s ---\n",
        $c['id'], $c['title'], $c['status'], $c['case_number']?:'-');
    printf("  Distribuido: %s | Ultimo update: %s\n",
        $c['distribution_date']?:'-', $c['updated_at']);
    // Ultimos 5 andamentos
    $stA = $pdo->prepare("SELECT id, data_andamento, tipo, LEFT(descricao, 100) desc_curto, tipo_origem
                          FROM case_andamentos WHERE case_id = ? ORDER BY data_andamento DESC LIMIT 6");
    $stA->execute(array($c['id']));
    foreach ($stA as $a) {
        printf("    %s [%s/%s] %s\n",
            substr($a['data_andamento'], 0, 10),
            $a['tipo']?:'-', $a['tipo_origem']?:'-',
            preg_replace('/\s+/', ' ', $a['desc_curto']));
    }
    // Cliente + telefone
    if ($c['client_id']) {
        $stC = $pdo->prepare("SELECT name, phone, portal_ativado_em FROM clients WHERE id = ?");
        $stC->execute(array($c['client_id']));
        $cl = $stC->fetch();
        if ($cl) printf("  Cliente: %s | tel=%s | portal=%s\n",
            $cl['name'], $cl['phone']?:'-', $cl['portal_ativado_em']?:'nao');
    }
    // Ultimas msgs enviadas AO cliente nesse case (via wa)
    if ($c['client_id']) {
        try {
            $stM = $pdo->prepare("SELECT DATE(m.created_at) d, LEFT(m.texto, 80) t, u.name enviador
                                  FROM zapi_mensagens m
                                  JOIN zapi_conversas co ON co.id = m.conversa_id
                                  LEFT JOIN users u ON u.id = m.enviado_por_id
                                  WHERE co.client_id = ? AND m.direcao = 'enviada'
                                  ORDER BY m.created_at DESC LIMIT 4");
            $stM->execute(array($c['client_id']));
            $msgs = $stM->fetchAll();
            if ($msgs) {
                echo "  Ultimas mensagens NOSSAS pro cliente (qualquer case):\n";
                foreach ($msgs as $m) printf("    %s [%s] %s\n", $m['d'], $m['enviador']?:'sistema', preg_replace('/\s+/', ' ', $m['t']));
            } else {
                echo "  ⚠ Nenhuma mensagem enviada pro cliente\n";
            }
        } catch (Exception $e) {}
    }
}
