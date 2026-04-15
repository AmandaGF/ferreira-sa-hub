<?php
/**
 * Cron: Cobrança de Honorários
 * Rodar diariamente às 08h00
 *
 * 1. Detecta cobranças Asaas vencidas há 90+ dias e cria entrada automática
 * 2. Verifica prazos de cada etapa e alerta responsáveis
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';

$pdo = db();

echo "=== CRON: Cobrança de Honorários — " . date('Y-m-d H:i:s') . " ===\n\n";

// ─── Carregar config ───
$config = array('dias_para_cobranca' => 90, 'prazo_notificacao_1' => 7, 'prazo_notificacao_2' => 15, 'prazo_extrajudicial' => 10);
try {
    $cfgRow = $pdo->query("SELECT * FROM honorarios_config ORDER BY id LIMIT 1")->fetch();
    if ($cfgRow) $config = array_merge($config, $cfgRow);
} catch (Exception $e) {
    echo "[ERRO] Config: " . $e->getMessage() . "\n";
}

$diasMinimo = (int)$config['dias_para_cobranca'];

// ═══ PARTE 1: Entrada automática de cobranças vencidas ═══
echo "--- Verificando cobranças vencidas há {$diasMinimo}+ dias ---\n";
try {
    $stmt = $pdo->prepare(
        "SELECT ac.id as cobranca_asaas_id, ac.client_id, ac.contrato_id, ac.valor, ac.vencimento, ac.descricao,
                cl.name as client_name
         FROM asaas_cobrancas ac
         LEFT JOIN clients cl ON cl.id = ac.client_id
         LEFT JOIN honorarios_cobranca hc ON hc.client_id = ac.client_id
             AND hc.status NOT IN ('pago','cancelado')
         WHERE ac.status = 'OVERDUE'
         AND DATEDIFF(CURDATE(), ac.vencimento) >= ?
         AND hc.id IS NULL
         GROUP BY ac.client_id"
    );
    $stmt->execute(array($diasMinimo));
    $vencidas = $stmt->fetchAll();

    echo "Encontradas: " . count($vencidas) . " cobranças elegíveis\n";

    foreach ($vencidas as $v) {
        // Calcular total vencido do cliente
        $totalVencido = (float)$pdo->prepare("SELECT IFNULL(SUM(valor),0) FROM asaas_cobrancas WHERE client_id = ? AND status = 'OVERDUE'");
        $stmtTotal = $pdo->prepare("SELECT IFNULL(SUM(valor),0) FROM asaas_cobrancas WHERE client_id = ? AND status = 'OVERDUE'");
        $stmtTotal->execute(array($v['client_id']));
        $totalVencido = (float)$stmtTotal->fetchColumn();

        // Inserir na fila de cobrança
        $stmtIns = $pdo->prepare(
            "INSERT INTO honorarios_cobranca (client_id, contrato_id, tipo_debito, valor_total, vencimento, status, entrada_automatica, observacoes, created_at)
             VALUES (?, ?, 'Honorários advocatícios', ?, ?, 'atrasado', 1, ?, NOW())"
        );
        $obs = 'Entrada automática — vencida há ' . (int)$pdo->query("SELECT DATEDIFF(CURDATE(), '{$v['vencimento']}')")->fetchColumn() . ' dias. ' . ($v['descricao'] ?: '');
        $stmtIns->execute(array($v['client_id'], $v['contrato_id'], $totalVencido, $v['vencimento'], $obs));
        $cobId = (int)$pdo->lastInsertId();

        // Histórico
        $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao) VALUES (?, 'observacao', ?)")
            ->execute(array($cobId, 'Entrada automática no fluxo de cobrança. Total vencido: R$ ' . number_format($totalVencido, 2, ',', '.')));

        // Notificar Admin e Gestão
        $msg = '⚠️ ' . ($v['client_name'] ?: 'Cliente') . ' entrou automaticamente no fluxo de cobrança — R$ ' . number_format($totalVencido, 2, ',', '.') . ' em atraso há ' . $diasMinimo . '+ dias';
        notify_admins('⚠️ Entrada Automática — Cobrança', $msg, url('modules/cobranca_honorarios/?aba=fila'), 'warning', '⚠️');

        echo "[OK] Cliente #{$v['client_id']} ({$v['client_name']}) — R$ " . number_format($totalVencido, 2, ',', '.') . "\n";
    }
} catch (Exception $e) {
    echo "[ERRO] Entrada automática: " . $e->getMessage() . "\n";
}

// ═══ PARTE 2: Alertas de prazos vencidos ═══
echo "\n--- Verificando prazos das etapas ---\n";

try {
    // Notificação 1 há X+ dias sem avançar
    $prazo1 = (int)$config['prazo_notificacao_1'];
    $alertas1 = $pdo->query(
        "SELECT hc.id, cl.name as client_name, DATEDIFF(CURDATE(), hc.updated_at) as dias_na_etapa
         FROM honorarios_cobranca hc
         LEFT JOIN clients cl ON cl.id = hc.client_id
         WHERE hc.status = 'notificado_1'
         AND DATEDIFF(CURDATE(), hc.updated_at) >= $prazo1"
    )->fetchAll();

    foreach ($alertas1 as $a) {
        notify_admins('📱 Prazo Notif. 1 vencido', ($a['client_name'] ?: 'Cliente') . ' — ' . $a['dias_na_etapa'] . ' dias na etapa. Avançar para Notif. 2?', url('modules/cobranca_honorarios/?aba=fila'), 'warning', '📱');
        echo "[ALERTA] Notif.1 vencida: #{$a['id']} — {$a['client_name']}\n";
    }

    // Notificação 2 há X+ dias sem avançar
    $prazo2 = (int)$config['prazo_notificacao_2'];
    $alertas2 = $pdo->query(
        "SELECT hc.id, cl.name as client_name, DATEDIFF(CURDATE(), hc.updated_at) as dias_na_etapa
         FROM honorarios_cobranca hc
         LEFT JOIN clients cl ON cl.id = hc.client_id
         WHERE hc.status = 'notificado_2'
         AND DATEDIFF(CURDATE(), hc.updated_at) >= $prazo2"
    )->fetchAll();

    foreach ($alertas2 as $a) {
        notify_admins('📱 Prazo Notif. 2 vencido', ($a['client_name'] ?: 'Cliente') . ' — ' . $a['dias_na_etapa'] . ' dias na etapa. Avançar para Extrajudicial?', url('modules/cobranca_honorarios/?aba=fila'), 'warning', '📱');
        echo "[ALERTA] Notif.2 vencida: #{$a['id']} — {$a['client_name']}\n";
    }

    // Extrajudicial — prazo de 10 dias
    $prazoExt = (int)$config['prazo_extrajudicial'];
    $alertasExt = $pdo->query(
        "SELECT hc.id, cl.name as client_name, DATEDIFF(CURDATE(), hc.updated_at) as dias_na_etapa
         FROM honorarios_cobranca hc
         LEFT JOIN clients cl ON cl.id = hc.client_id
         WHERE hc.status = 'notificado_extrajudicial'
         AND DATEDIFF(CURDATE(), hc.updated_at) >= $prazoExt"
    )->fetchAll();

    foreach ($alertasExt as $a) {
        notify_admins('⚠️ Prazo Extrajudicial Vencido!', ($a['client_name'] ?: 'Cliente') . ' — prazo expirado! Iniciar cobrança judicial?', url('modules/cobranca_honorarios/?aba=fila'), 'danger', '⚖️');
        echo "[URGENTE] Extrajudicial vencida: #{$a['id']} — {$a['client_name']}\n";
    }

} catch (Exception $e) {
    echo "[ERRO] Alertas: " . $e->getMessage() . "\n";
}

echo "\n=== CRON CONCLUÍDO ===\n";
