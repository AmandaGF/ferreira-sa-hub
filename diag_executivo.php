<?php
/**
 * Diag: testa cada query do Painel Executivo isoladamente pra identificar
 * qual coluna/tabela esta faltando em producao (HTTP 500 da Amanda).
 * Acesse: ferreiraesa.com.br/conecta/diag_executivo.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$dias = 30;
$hoje = date('Y-m-d');
$inicioAtual    = date('Y-m-d', strtotime("-{$dias} days"));
$inicioAnterior = date('Y-m-d', strtotime('-' . ($dias * 2) . ' days'));

function t($label, $fn) {
    echo "  $label ... ";
    try {
        $r = $fn();
        echo "OK (resultado=" . json_encode($r) . ")\n";
    } catch (Throwable $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}

echo "=== Diag Painel Executivo ===\n\n";

echo "[1] honorarios_cobranca_historico\n";
t('SUM(valor_pago) periodo', function() use ($pdo, $inicioAtual, $hoje) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(valor_pago),0) FROM honorarios_cobranca_historico WHERE etapa IN ('pagamento_parcial','pagamento_total') AND DATE(created_at) BETWEEN ? AND ?");
    $st->execute(array($inicioAtual, $hoje));
    return $st->fetchColumn();
});

echo "\n[2] honorarios_cobranca aberto\n";
t('SUM(valor_total - valor_pago)', function() use ($pdo) {
    return $pdo->query("SELECT COALESCE(SUM(valor_total - valor_pago),0) FROM honorarios_cobranca WHERE status NOT IN ('pago','cancelado')")->fetchColumn();
});

echo "\n[3] pipeline_leads\n";
t('COUNT created_at periodo', function() use ($pdo, $inicioAtual, $hoje) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE DATE(created_at) BETWEEN ? AND ?");
    $st->execute(array($inicioAtual, $hoje));
    return $st->fetchColumn();
});

t('COUNT converted_at + stage IN', function() use ($pdo, $inicioAtual, $hoje) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado') AND DATE(converted_at) BETWEEN ? AND ?");
    $st->execute(array($inicioAtual, $hoje));
    return $st->fetchColumn();
});

echo "\n[4] cases\n";
t('COUNT created_at periodo', function() use ($pdo, $inicioAtual, $hoje) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE DATE(created_at) BETWEEN ? AND ?");
    $st->execute(array($inicioAtual, $hoje));
    return $st->fetchColumn();
});

t('COUNT closed_at + status=concluido', function() use ($pdo, $inicioAtual, $hoje) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE status = 'concluido' AND DATE(closed_at) BETWEEN ? AND ?");
    $st->execute(array($inicioAtual, $hoje));
    return $st->fetchColumn();
});

t('COUNT ativos', function() use ($pdo) {
    return $pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('arquivado','concluido','cancelado','renunciamos') AND COALESCE(kanban_oculto,0) = 0")->fetchColumn();
});

echo "\n[5] clients.esfriando_score (existe?)\n";
t('esfriando_score >= 80', function() use ($pdo) {
    return $pdo->query("SELECT COUNT(*) FROM clients WHERE esfriando_score >= 80")->fetchColumn();
});

t('JOIN clients + cases (alerta esfriando)', function() use ($pdo) {
    return $pdo->query(
        "SELECT COUNT(DISTINCT c.id) FROM clients c
         INNER JOIN cases cs ON cs.client_id = c.id
         WHERE c.esfriando_score >= 80
           AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())
           AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
           AND COALESCE(cs.kanban_oculto,0) = 0
           AND COALESCE(cs.acompanhamento_externo,0) = 0"
    )->fetchColumn();
});

echo "\n[6] cobranca vencida\n";
t('vencimento + status', function() use ($pdo) {
    return $pdo->query("SELECT COUNT(*) FROM honorarios_cobranca WHERE status NOT IN ('pago','cancelado') AND vencimento < DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
});

echo "\n[7] doc faltante 14d\n";
t('cases status + updated_at', function() use ($pdo) {
    return $pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'doc_faltante' AND DATE(updated_at) < DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND COALESCE(kanban_oculto,0) = 0")->fetchColumn();
});

echo "\n[8] leads parados\n";
t('pipeline_leads stage + updated_at', function() use ($pdo) {
    return $pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage NOT IN ('finalizado','perdido','arquivado','cancelado') AND DATE(updated_at) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
});

echo "\n[9] top tipos\n";
t('cases case_type periodo', function() use ($pdo, $inicioAtual, $hoje) {
    $st = $pdo->prepare("SELECT case_type, COUNT(*) AS qtd FROM cases WHERE DATE(created_at) BETWEEN ? AND ? AND case_type IS NOT NULL AND case_type != '' GROUP BY case_type ORDER BY qtd DESC LIMIT 6");
    $st->execute(array($inicioAtual, $hoje));
    return count($st->fetchAll());
});

echo "\n[10] equipe (case_tasks.responsavel_id, case_andamentos.usuario_id)\n";
t('case_tasks colunas', function() use ($pdo) {
    return $pdo->query("SHOW COLUMNS FROM case_tasks LIKE 'responsavel_id'")->fetchAll() ? 'responsavel_id existe' : 'NAO existe';
});

t('case_tasks col completa', function() use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM case_tasks")->fetchAll(PDO::FETCH_COLUMN);
    return $cols;
});

t('case_andamentos colunas', function() use ($pdo) {
    return $pdo->query("SHOW COLUMNS FROM case_andamentos LIKE 'usuario_id'")->fetchAll() ? 'usuario_id existe' : 'NAO existe';
});

t('case_andamentos col completa', function() use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM case_andamentos")->fetchAll(PDO::FETCH_COLUMN);
    return $cols;
});

echo "\n[11] case_documents.gerado_por\n";
t('case_documents col', function() use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM case_documents")->fetchAll(PDO::FETCH_COLUMN);
    return $cols;
});

echo "\n[12] query equipe completa\n";
t('equipe full', function() use ($pdo, $inicioAtual, $hoje) {
    $st = $pdo->prepare(
        "SELECT u.id, u.name, u.role,
                (SELECT COUNT(*) FROM case_documents cd WHERE cd.gerado_por = u.id AND DATE(cd.created_at) BETWEEN ? AND ?) AS pecas,
                (SELECT COUNT(*) FROM case_tasks t WHERE t.responsavel_id = u.id AND t.status IN ('concluido','feito') AND DATE(t.updated_at) BETWEEN ? AND ?) AS tarefas,
                (SELECT COUNT(*) FROM case_andamentos a WHERE a.usuario_id = u.id AND DATE(a.created_at) BETWEEN ? AND ?) AS andamentos
         FROM users u
         WHERE u.is_active = 1
         ORDER BY (pecas + tarefas + andamentos) DESC LIMIT 8"
    );
    $st->execute(array($inicioAtual, $hoje, $inicioAtual, $hoje, $inicioAtual, $hoje));
    return count($st->fetchAll());
});

echo "\n[FIM]\n";
