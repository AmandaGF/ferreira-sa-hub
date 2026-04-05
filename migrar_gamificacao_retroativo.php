<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Gamificação: Cálculo Retroativo ===\n\n";

// Limpar pontos existentes para recalcular
$pdo->exec("DELETE FROM gamificacao_pontos");
$pdo->exec("UPDATE gamificacao_totais SET pontos_mes_comercial=0, pontos_mes_operacional=0, pontos_total_comercial=0, pontos_total_operacional=0, contratos_mes=0, contratos_total=0, nivel='Estagiário', nivel_num=1");
echo "[OK] Dados limpos para recálculo\n\n";

$mesAtual = (int)date('n');
$anoAtual = (int)date('Y');
$totalPontos = 0;
$totalEventos = 0;

// ═══ 1. LEADS CADASTRADOS (+5 pts cada) ═══
echo "--- Leads cadastrados ---\n";
$leads = $pdo->query("SELECT id, assigned_to, created_at FROM pipeline_leads WHERE assigned_to IS NOT NULL ORDER BY created_at")->fetchAll();
foreach ($leads as $l) {
    $userId = (int)$l['assigned_to'];
    if ($userId <= 0) continue;
    $mes = (int)date('n', strtotime($l['created_at']));
    $ano = (int)date('Y', strtotime($l['created_at']));

    $pdo->prepare("INSERT INTO gamificacao_pontos (user_id, evento, area, pontos, descricao, referencia_id, referencia_tipo, mes, ano, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute(array($userId, 'lead_cadastrado', 'comercial', 5, 'Lead cadastrado', $l['id'], 'pipeline_leads', $mes, $ano, $l['created_at']));
    $totalEventos++;
    $totalPontos += 5;
}
echo "Leads: " . count($leads) . " → " . (count($leads) * 5) . " pts\n";

// ═══ 2. CONTRATOS FECHADOS (+50 pts cada) ═══
echo "\n--- Contratos fechados ---\n";
$contratos = $pdo->query("SELECT id, assigned_to, converted_at, estimated_value_cents, honorarios_cents FROM pipeline_leads WHERE converted_at IS NOT NULL AND assigned_to IS NOT NULL AND stage NOT IN ('cancelado','perdido') ORDER BY converted_at")->fetchAll();
foreach ($contratos as $c) {
    $userId = (int)$c['assigned_to'];
    if ($userId <= 0) continue;
    $mes = (int)date('n', strtotime($c['converted_at']));
    $ano = (int)date('Y', strtotime($c['converted_at']));

    // Contrato fechado +50
    $pdo->prepare("INSERT INTO gamificacao_pontos (user_id, evento, area, pontos, descricao, referencia_id, referencia_tipo, mes, ano, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute(array($userId, 'contrato_fechado', 'comercial', 50, 'Contrato fechado', $c['id'], 'pipeline_leads', $mes, $ano, $c['converted_at']));
    $totalEventos++;
    $totalPontos += 50;

    // Bônus alto valor (+30 se > R$2k)
    $valor = (int)($c['honorarios_cents'] ?: $c['estimated_value_cents']);
    if ($valor > 200000) {
        $pdo->prepare("INSERT INTO gamificacao_pontos (user_id, evento, area, pontos, descricao, referencia_id, referencia_tipo, mes, ano, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute(array($userId, 'contrato_bonus_alto', 'comercial', 30, 'Bônus contrato alto valor', $c['id'], 'pipeline_leads', $mes, $ano, $c['converted_at']));
        $totalEventos++;
        $totalPontos += 30;
    }
}
echo "Contratos: " . count($contratos) . "\n";

// ═══ 3. ONBOARDING REALIZADO (+20 pts) ═══
echo "\n--- Onboarding realizados ---\n";
$onboards = $pdo->query("SELECT id, assigned_to, updated_at FROM pipeline_leads WHERE onboard_realizado = 1 AND assigned_to IS NOT NULL")->fetchAll();
foreach ($onboards as $o) {
    $userId = (int)$o['assigned_to'];
    if ($userId <= 0) continue;
    $mes = (int)date('n', strtotime($o['updated_at']));
    $ano = (int)date('Y', strtotime($o['updated_at']));

    $pdo->prepare("INSERT INTO gamificacao_pontos (user_id, evento, area, pontos, descricao, referencia_id, referencia_tipo, mes, ano, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute(array($userId, 'onboarding_realizado', 'comercial', 20, 'Onboarding realizado', $o['id'], 'pipeline_leads', $mes, $ano, $o['updated_at']));
    $totalEventos++;
    $totalPontos += 20;
}
echo "Onboardings: " . count($onboards) . "\n";

// ═══ 4. PROCESSOS DISTRIBUÍDOS (+30 pts) ═══
echo "\n--- Processos distribuídos ---\n";
$distribuidos = $pdo->query("SELECT id, responsible_user_id, distribution_date, updated_at FROM cases WHERE status = 'distribuido' AND responsible_user_id IS NOT NULL AND distribution_date IS NOT NULL")->fetchAll();
foreach ($distribuidos as $d) {
    $userId = (int)$d['responsible_user_id'];
    if ($userId <= 0) continue;
    $dataRef = $d['distribution_date'] ?: $d['updated_at'];
    $mes = (int)date('n', strtotime($dataRef));
    $ano = (int)date('Y', strtotime($dataRef));

    $pdo->prepare("INSERT INTO gamificacao_pontos (user_id, evento, area, pontos, descricao, referencia_id, referencia_tipo, mes, ano, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute(array($userId, 'processo_distribuido', 'operacional', 30, 'Processo distribuído', $d['id'], 'cases', $mes, $ano, $dataRef));
    $totalEventos++;
    $totalPontos += 30;
}
echo "Distribuídos: " . count($distribuidos) . "\n";

// ═══ 5. RECALCULAR TOTAIS POR USUÁRIO ═══
echo "\n--- Recalculando totais ---\n";
$usuarios = $pdo->query("SELECT id, name FROM users WHERE is_active = 1")->fetchAll();

foreach ($usuarios as $u) {
    $uid = (int)$u['id'];

    // Total comercial
    $totalCom = (int)$pdo->prepare("SELECT IFNULL(SUM(pontos),0) FROM gamificacao_pontos WHERE user_id = ? AND area = 'comercial'")->execute(array($uid));
    $stmtTC = $pdo->prepare("SELECT IFNULL(SUM(pontos),0) FROM gamificacao_pontos WHERE user_id = ? AND area = 'comercial'");
    $stmtTC->execute(array($uid));
    $totalCom = (int)$stmtTC->fetchColumn();

    // Total operacional
    $stmtTO = $pdo->prepare("SELECT IFNULL(SUM(pontos),0) FROM gamificacao_pontos WHERE user_id = ? AND area = 'operacional'");
    $stmtTO->execute(array($uid));
    $totalOp = (int)$stmtTO->fetchColumn();

    // Mês atual comercial
    $stmtMC = $pdo->prepare("SELECT IFNULL(SUM(pontos),0) FROM gamificacao_pontos WHERE user_id = ? AND area = 'comercial' AND mes = ? AND ano = ?");
    $stmtMC->execute(array($uid, $mesAtual, $anoAtual));
    $mesCom = (int)$stmtMC->fetchColumn();

    // Mês atual operacional
    $stmtMO = $pdo->prepare("SELECT IFNULL(SUM(pontos),0) FROM gamificacao_pontos WHERE user_id = ? AND area = 'operacional' AND mes = ? AND ano = ?");
    $stmtMO->execute(array($uid, $mesAtual, $anoAtual));
    $mesOp = (int)$stmtMO->fetchColumn();

    // Contratos mês
    $stmtCM = $pdo->prepare("SELECT COUNT(*) FROM gamificacao_pontos WHERE user_id = ? AND evento = 'contrato_fechado' AND mes = ? AND ano = ?");
    $stmtCM->execute(array($uid, $mesAtual, $anoAtual));
    $contratosMes = (int)$stmtCM->fetchColumn();

    // Contratos total
    $stmtCT = $pdo->prepare("SELECT COUNT(*) FROM gamificacao_pontos WHERE user_id = ? AND evento = 'contrato_fechado'");
    $stmtCT->execute(array($uid));
    $contratosTotal = (int)$stmtCT->fetchColumn();

    // Determinar nível
    $totalGeral = $totalCom + $totalOp;
    $stmtNivel = $pdo->prepare("SELECT nivel_num, nome FROM gamificacao_niveis WHERE pontos_minimos <= ? ORDER BY pontos_minimos DESC LIMIT 1");
    $stmtNivel->execute(array($totalGeral));
    $nivel = $stmtNivel->fetch();
    $nivelNome = $nivel ? $nivel['nome'] : 'Estagiário';
    $nivelNum = $nivel ? (int)$nivel['nivel_num'] : 1;

    // Atualizar totais
    $pdo->prepare(
        "UPDATE gamificacao_totais SET pontos_mes_comercial=?, pontos_mes_operacional=?, pontos_total_comercial=?, pontos_total_operacional=?, contratos_mes=?, contratos_total=?, nivel=?, nivel_num=?, mes_referencia=?, ano_referencia=? WHERE user_id=?"
    )->execute(array($mesCom, $mesOp, $totalCom, $totalOp, $contratosMes, $contratosTotal, $nivelNome, $nivelNum, $mesAtual, $anoAtual, $uid));

    echo "{$u['name']}: Com={$totalCom} Op={$totalOp} Total={$totalGeral} → {$nivelNome} (Lv{$nivelNum}) | Mês: {$mesCom}+{$mesOp} | Contratos: {$contratosMes}/{$contratosTotal}\n";
}

echo "\n=== RESULTADO ===\n";
echo "Total de eventos: $totalEventos\n";
echo "Total de pontos: $totalPontos\n";
echo "\n=== FIM ===\n";
