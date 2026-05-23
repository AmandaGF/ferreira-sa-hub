<?php
/**
 * cron/cliente_esfriando.php — Detector de cliente esfriando (SEM IA).
 *
 * Calcula um score numérico (0-100) pra cada cliente ATIVO com base em
 * sinais de desengajamento. Atualiza clients.esfriando_score + motivos +
 * timestamp. UI usa o score pra mostrar badge laranja/vermelho.
 *
 * Sinais (pontos somam — quanto maior, pior):
 *   +30  — Última mensagem WhatsApp há > 14 dias (cliente parou de falar)
 *   +20  — Último andamento no processo há > 30 dias (caso parado)
 *   +20  — Cobrança em aberto vencida há > 5 dias (inadimplência inicial)
 *   +15  — Tarefa do responsável vencida há > 7 dias (operacional travado)
 *   +10  — Nenhum contato registrado nos últimos 30 dias (silêncio total)
 *
 * Faixas:
 *   < 30 — OK (não destaca)
 *   30-59 — "Atenção" (badge amarelo)
 *   60+  — "Esfriando" (badge laranja/vermelho)
 *
 * Uso (cPanel cron, 1x ao dia, ex: 6h):
 *   curl -s "https://ferreiraesa.com.br/conecta/cron/cliente_esfriando.php?key=fsa-hub-deploy-2026"
 *
 * Não usa IA — custo zero. Cálculo puro em SQL/PHP.
 */

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); exit('Negado.');
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_ia.php';

@set_time_limit(180);
header('Content-Type: text/plain; charset=utf-8');
echo "=== Detector de cliente esfriando ===\n";
echo date('d/m/Y H:i:s') . "\n\n";

if (!ia_feature_ativa('cliente_esfriando')) {
    echo "Feature desligada. Saindo.\n";
    exit;
}

$pdo = db();

// Universo: clientes que têm pelo menos 1 case ativo (não arquivado/renunciado/finalizado).
// Pega só os ativos pra não inflar a tabela com clientes antigos sem caso aberto.
$lim = isset($_GET['lim']) ? max(1, (int)$_GET['lim']) : 9999;
$stClientes = $pdo->query(
    "SELECT DISTINCT c.id, c.name
       FROM clients c
       INNER JOIN cases cs ON cs.client_id = c.id
      WHERE cs.status NOT IN ('arquivado','renunciamos','finalizado') AND cs.kanban_oculto = 0
      LIMIT $lim"
);
$clientes = $stClientes->fetchAll(PDO::FETCH_ASSOC);
$stClientes->closeCursor();
echo "Clientes ativos analisados: " . count($clientes) . "\n";
echo "(use ?lim=N pra limitar)\n";
@ob_flush(); flush();

$stUpd  = $pdo->prepare("UPDATE clients SET esfriando_score = ?, esfriando_motivos = ?, esfriando_em = NOW() WHERE id = ?");

// Prepared statements reutilizados
$stMsg  = $pdo->prepare("SELECT MAX(m.created_at) FROM zapi_mensagens m INNER JOIN zapi_conversas co ON co.id = m.conversa_id WHERE co.client_id = ?");
$stAnd  = $pdo->prepare("SELECT MAX(ca.created_at) FROM case_andamentos ca INNER JOIN cases cs ON cs.id = ca.case_id WHERE cs.client_id = ? AND cs.status NOT IN ('arquivado','renunciamos','finalizado')");
$stCob  = $pdo->prepare("SELECT COUNT(*) FROM honorarios_cobranca h WHERE h.client_id = ? AND h.status NOT IN ('pago','cancelado') AND h.vencimento < DATE_SUB(CURDATE(), INTERVAL 5 DAY)");
$stTar  = $pdo->prepare("SELECT COUNT(*) FROM case_tasks t INNER JOIN cases cs ON cs.id = t.case_id WHERE cs.client_id = ? AND t.tipo IS NOT NULL AND t.status != 'concluido' AND t.due_date IS NOT NULL AND t.due_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)");

$contagem = array('esfriando' => 0, 'atencao' => 0, 'ok' => 0);
$topAlerta = array();
$cnt = 0;

foreach ($clientes as $c) {
    $cnt++;
    if ($cnt % 10 === 0) { echo "  [progresso] $cnt/" . count($clientes) . "\n"; @ob_flush(); flush(); }
    $score = 0;
    $motivos = array();

    // 1) Última msg WhatsApp
    $stMsg->execute(array((int)$c['id']));
    $ultMsg = $stMsg->fetchColumn(); $stMsg->closeCursor();
    if (!$ultMsg) {
        $score += 10; $motivos[] = 'Sem conversa WhatsApp registrada';
    } else {
        $diasMsg = (int)((time() - strtotime($ultMsg)) / 86400);
        if ($diasMsg > 14) { $score += 30; $motivos[] = "Sem msg WhatsApp há {$diasMsg}d"; }
        elseif ($diasMsg > 7) { $score += 10; $motivos[] = "Sem msg WhatsApp há {$diasMsg}d"; }
    }

    // 2) Último andamento no processo
    $stAnd->execute(array((int)$c['id']));
    $ultAnd = $stAnd->fetchColumn(); $stAnd->closeCursor();
    if ($ultAnd) {
        $diasAnd = (int)((time() - strtotime($ultAnd)) / 86400);
        if ($diasAnd > 60) { $score += 30; $motivos[] = "Processo parado há {$diasAnd}d"; }
        elseif ($diasAnd > 30) { $score += 20; $motivos[] = "Processo parado há {$diasAnd}d"; }
    }

    // 3) Cobrança vencida > 5 dias
    $stCob->execute(array((int)$c['id']));
    $qtdCob = (int)$stCob->fetchColumn(); $stCob->closeCursor();
    if ($qtdCob > 0) { $score += 20; $motivos[] = "{$qtdCob} cobrança(s) vencida(s)"; }

    // 4) Tarefa atrasada > 7 dias
    $stTar->execute(array((int)$c['id']));
    $qtdTar = (int)$stTar->fetchColumn(); $stTar->closeCursor();
    if ($qtdTar > 0) { $score += 15; $motivos[] = "{$qtdTar} tarefa(s) atrasada(s)"; }

    // Cap em 100
    if ($score > 100) $score = 100;

    $motivoStr = $score > 0 ? implode(' · ', $motivos) : '';
    $stUpd->execute(array($score, $motivoStr, (int)$c['id']));

    if ($score >= 60) {
        $contagem['esfriando']++;
        $topAlerta[] = array('id' => $c['id'], 'name' => $c['name'], 'score' => $score, 'motivos' => $motivoStr);
    } elseif ($score >= 30) {
        $contagem['atencao']++;
    } else {
        $contagem['ok']++;
    }
}

// Zera score de quem saiu do universo ativo (caso arquivado/renunciado entre execuções)
$pdo->exec("UPDATE clients c
            LEFT JOIN cases cs ON cs.client_id = c.id AND cs.status NOT IN ('arquivado','renunciamos','finalizado') AND cs.kanban_oculto = 0
            SET c.esfriando_score = 0, c.esfriando_motivos = NULL, c.esfriando_em = NOW()
            WHERE c.esfriando_score > 0 AND cs.id IS NULL");

echo "Resultado:\n";
echo "  🔴 esfriando (≥60):  {$contagem['esfriando']}\n";
echo "  🟡 atenção  (30-59): {$contagem['atencao']}\n";
echo "  ✅ ok        (<30):  {$contagem['ok']}\n\n";

// Top 20 alertas no log
if ($topAlerta) {
    usort($topAlerta, function($a, $b) { return $b['score'] - $a['score']; });
    echo "TOP " . min(20, count($topAlerta)) . " ALERTAS:\n";
    foreach (array_slice($topAlerta, 0, 20) as $a) {
        echo "  #{$a['id']} ({$a['score']}) {$a['name']} — {$a['motivos']}\n";
    }
}
echo "\n=== FIM ===\n";
