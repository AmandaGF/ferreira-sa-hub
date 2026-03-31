<?php
/**
 * Ferreira & Sá Hub — Cron: Alertas de Inatividade + Suspensão Prolongada
 * Executar diariamente via cPanel Cron Jobs:
 * php /home/ferre315/public_html/conecta/cron/alertas_inatividade.php
 *
 * Também pode ser chamado via HTTP com chave:
 * https://ferreiraesa.com.br/conecta/cron/alertas_inatividade.php?key=fsa-hub-deploy-2026
 */

// Aceitar execução via CLI ou HTTP com chave
$isCli = php_sapi_name() === 'cli';
if (!$isCli && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';

$pdo = db();
$hoje = date('Y-m-d');
$agora = date('Y-m-d H:i:s');
$totalAlertas = 0;

echo "=== Alertas de Inatividade — $agora ===\n\n";

// ─── Função auxiliar: verificar se alerta já foi enviado hoje ──
function alerta_ja_enviado($pdo, $tipo, $refTipo, $refId) {
    $stmt = $pdo->prepare(
        "SELECT id FROM alertas_enviados WHERE tipo=? AND referencia_tipo=? AND referencia_id=? AND (proximo_alerta IS NULL OR proximo_alerta <= CURDATE()) ORDER BY enviado_em DESC LIMIT 1"
    );
    $stmt->execute(array($tipo, $refTipo, $refId));
    $row = $stmt->fetch();
    if (!$row) return false;
    // Verificar se já enviou hoje
    $stmt2 = $pdo->prepare("SELECT id FROM alertas_enviados WHERE tipo=? AND referencia_tipo=? AND referencia_id=? AND DATE(enviado_em) = CURDATE()");
    $stmt2->execute(array($tipo, $refTipo, $refId));
    return (bool)$stmt2->fetch();
}

function registrar_alerta($pdo, $tipo, $refTipo, $refId, $proximoAlerta = null) {
    $pdo->prepare("INSERT INTO alertas_enviados (tipo, referencia_tipo, referencia_id, proximo_alerta) VALUES (?,?,?,?)")
        ->execute(array($tipo, $refTipo, $refId, $proximoAlerta));
}

// Buscar usuários por papel para notificar
function get_users_by_role($pdo, $role) {
    if ($role === 'gestao') {
        return $pdo->query("SELECT id FROM users WHERE role IN ('admin','gestao') AND is_active = 1")->fetchAll();
    }
    return $pdo->query("SELECT id FROM users WHERE role = '" . $role . "' AND is_active = 1")->fetchAll();
}

// ═══════════════════════════════════════════════════════
// ALERTA 1: Elaboração Procuração parada > 3 dias
// ═══════════════════════════════════════════════════════
echo "--- Alerta 1: Elaboração Procuração > 3 dias ---\n";
$stmt = $pdo->query(
    "SELECT pl.id, pl.name, pl.assigned_to, pl.updated_at,
            DATEDIFF(NOW(), pl.updated_at) as dias_parado
     FROM pipeline_leads pl
     WHERE pl.stage = 'elaboracao_docs'
       AND pl.updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
       AND pl.stage NOT IN ('cancelado','suspenso','finalizado','perdido')"
);
$leads = $stmt->fetchAll();
foreach ($leads as $lead) {
    if (alerta_ja_enviado($pdo, 'inatividade_elaboracao', 'lead', (int)$lead['id'])) continue;

    $msg = $lead['name'] . ' está há ' . $lead['dias_parado'] . ' dias em Elaboração de Procuração sem movimentação';

    // Notificar responsável
    if ($lead['assigned_to']) {
        notify((int)$lead['assigned_to'], 'Inatividade: Elaboração Procuração', $msg, 'alerta', url('modules/pipeline/'), '⏰');
    }
    // Notificar gestão
    notify_gestao('Inatividade: Elaboração Procuração', $msg, 'alerta', url('modules/pipeline/'), '⏰');

    registrar_alerta($pdo, 'inatividade_elaboracao', 'lead', (int)$lead['id']);
    $totalAlertas++;
    echo "  Lead #{$lead['id']}: {$lead['name']} ({$lead['dias_parado']} dias)\n";
}
if (!$leads) echo "  Nenhum.\n";

// ═══════════════════════════════════════════════════════
// ALERTA 2: Aguardando Docs sem cobrança > 7 dias
// ═══════════════════════════════════════════════════════
echo "\n--- Alerta 2: Aguardando Docs > 7 dias ---\n";
$stmt = $pdo->query(
    "SELECT c.id, c.title, c.responsible_user_id, c.updated_at,
            DATEDIFF(NOW(), c.updated_at) as dias_parado
     FROM cases c
     WHERE c.status = 'aguardando_docs'
       AND c.updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$cases = $stmt->fetchAll();
foreach ($cases as $caso) {
    if (alerta_ja_enviado($pdo, 'inatividade_aguardando_docs', 'case', (int)$caso['id'])) continue;

    $msg = $caso['title'] . ' está há ' . $caso['dias_parado'] . ' dias aguardando documentação sem contato do CX';

    // Notificar responsável do caso
    if ($caso['responsible_user_id']) {
        notify((int)$caso['responsible_user_id'], 'Inatividade: Aguardando Docs', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '⏰');
    }
    // Notificar CX (buscar pelo lead vinculado)
    $leadStmt = $pdo->prepare("SELECT assigned_to FROM pipeline_leads WHERE linked_case_id = ?");
    $leadStmt->execute(array($caso['id']));
    $leadRow = $leadStmt->fetch();
    if ($leadRow && $leadRow['assigned_to']) {
        notify((int)$leadRow['assigned_to'], 'Inatividade: Aguardando Docs', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '⏰');
    }
    notify_gestao('Inatividade: Aguardando Docs', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '⏰');

    registrar_alerta($pdo, 'inatividade_aguardando_docs', 'case', (int)$caso['id']);
    $totalAlertas++;
    echo "  Caso #{$caso['id']}: {$caso['title']} ({$caso['dias_parado']} dias)\n";
}
if (!$cases) echo "  Nenhum.\n";

// ═══════════════════════════════════════════════════════
// ALERTA 3: Processo Distribuído sem nº > 2 dias
// ═══════════════════════════════════════════════════════
echo "\n--- Alerta 3: Processo Distribuído sem nº cadastrado > 2 dias ---\n";
$stmt = $pdo->query(
    "SELECT c.id, c.title, c.responsible_user_id, c.updated_at,
            DATEDIFF(NOW(), c.updated_at) as dias_parado
     FROM cases c
     WHERE c.status = 'distribuido'
       AND (c.case_number IS NULL OR c.case_number = '')
       AND c.updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY)"
);
$cases = $stmt->fetchAll();
foreach ($cases as $caso) {
    if (alerta_ja_enviado($pdo, 'distribuido_sem_numero', 'case', (int)$caso['id'])) continue;

    $msg = $caso['title'] . ' foi distribuído há ' . $caso['dias_parado'] . ' dias mas o nº do processo não foi cadastrado';

    if ($caso['responsible_user_id']) {
        notify((int)$caso['responsible_user_id'], 'Cadastro pendente: nº do processo', $msg, 'urgencia', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '🔴');
    }
    notify_gestao('Cadastro pendente: nº do processo', $msg, 'urgencia', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '🔴');

    registrar_alerta($pdo, 'distribuido_sem_numero', 'case', (int)$caso['id']);
    $totalAlertas++;
    echo "  Caso #{$caso['id']}: {$caso['title']} ({$caso['dias_parado']} dias)\n";
}
if (!$cases) echo "  Nenhum.\n";

// ═══════════════════════════════════════════════════════
// ALERTA 4: Pasta Apta sem início da execução > 5 dias
// ═══════════════════════════════════════════════════════
echo "\n--- Alerta 4: Pasta Apta > 5 dias sem execução ---\n";
$stmt = $pdo->query(
    "SELECT c.id, c.title, c.responsible_user_id, c.updated_at,
            DATEDIFF(NOW(), c.updated_at) as dias_parado
     FROM cases c
     WHERE c.status = 'em_elaboracao'
       AND c.updated_at < DATE_SUB(NOW(), INTERVAL 5 DAY)"
);
$cases = $stmt->fetchAll();
foreach ($cases as $caso) {
    if (alerta_ja_enviado($pdo, 'pasta_apta_sem_execucao', 'case', (int)$caso['id'])) continue;

    $msg = $caso['title'] . ' está com pasta apta há ' . $caso['dias_parado'] . ' dias sem iniciar execução';

    if ($caso['responsible_user_id']) {
        notify((int)$caso['responsible_user_id'], 'Pasta Apta parada', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '📋');
    }
    notify_gestao('Pasta Apta parada', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '📋');

    registrar_alerta($pdo, 'pasta_apta_sem_execucao', 'case', (int)$caso['id']);
    $totalAlertas++;
    echo "  Caso #{$caso['id']}: {$caso['title']} ({$caso['dias_parado']} dias)\n";
}
if (!$cases) echo "  Nenhum.\n";

// ═══════════════════════════════════════════════════════
// ALERTA 5: Documento Faltante > 3 dias (repete a cada 3 dias)
// ═══════════════════════════════════════════════════════
echo "\n--- Alerta 5: Documento Faltante > 3 dias ---\n";
$stmt = $pdo->query(
    "SELECT c.id, c.title, c.responsible_user_id, c.updated_at,
            DATEDIFF(NOW(), c.updated_at) as dias_parado,
            dp.descricao as doc_descricao
     FROM cases c
     LEFT JOIN documentos_pendentes dp ON dp.case_id = c.id AND dp.status = 'pendente'
     WHERE c.status = 'doc_faltante'
       AND c.updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
     GROUP BY c.id"
);
$cases = $stmt->fetchAll();
foreach ($cases as $caso) {
    // Verificar se já enviou recentemente (respeitar intervalo de 3 dias)
    $lastAlerta = $pdo->prepare(
        "SELECT enviado_em FROM alertas_enviados WHERE tipo='doc_faltante_inatividade' AND referencia_tipo='case' AND referencia_id=? ORDER BY enviado_em DESC LIMIT 1"
    );
    $lastAlerta->execute(array($caso['id']));
    $lastRow = $lastAlerta->fetch();
    if ($lastRow) {
        $diasDesdeAlerta = (int)((time() - strtotime($lastRow['enviado_em'])) / 86400);
        if ($diasDesdeAlerta < 3) continue; // Repetir apenas a cada 3 dias
    }

    $docDesc = $caso['doc_descricao'] ? $caso['doc_descricao'] : 'documento não especificado';
    $msg = $caso['title'] . ' está há ' . $caso['dias_parado'] . ' dias com documento pendente: ' . $docDesc;

    // Notificar CX (via lead vinculado)
    $leadStmt = $pdo->prepare("SELECT assigned_to FROM pipeline_leads WHERE linked_case_id = ?");
    $leadStmt->execute(array($caso['id']));
    $leadRow = $leadStmt->fetch();
    if ($leadRow && $leadRow['assigned_to']) {
        notify((int)$leadRow['assigned_to'], 'Doc faltante parado', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '📄');
    }
    notify_gestao('Doc faltante parado', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '📄');

    $proximo = date('Y-m-d', strtotime('+3 days'));
    registrar_alerta($pdo, 'doc_faltante_inatividade', 'case', (int)$caso['id'], $proximo);
    $totalAlertas++;
    echo "  Caso #{$caso['id']}: {$caso['title']} ({$caso['dias_parado']} dias) Doc: $docDesc\n";
}
if (!$cases) echo "  Nenhum.\n";

// ═══════════════════════════════════════════════════════
// ALERTA SUSPENSÃO PROLONGADA (Bloco 2)
// ═══════════════════════════════════════════════════════
echo "\n--- Alerta Suspensão Prolongada ---\n";

// Pipeline leads suspensos
$stmt = $pdo->query(
    "SELECT pl.id, pl.name, pl.assigned_to, pl.data_suspensao, pl.prazo_suspensao,
            DATEDIFF(NOW(), pl.data_suspensao) as dias_suspenso
     FROM pipeline_leads pl
     WHERE pl.stage = 'suspenso'
       AND pl.data_suspensao IS NOT NULL"
);
$leads = $stmt->fetchAll();
foreach ($leads as $lead) {
    $deveria_alertar = false;

    if ($lead['prazo_suspensao'] && $hoje >= $lead['prazo_suspensao']) {
        $deveria_alertar = true; // Prazo definido e atingido
    } elseif (!$lead['prazo_suspensao'] && (int)$lead['dias_suspenso'] >= 30) {
        $deveria_alertar = true; // Sem prazo e 30+ dias
    }

    if (!$deveria_alertar) continue;

    // Repetir a cada 7 dias
    $lastAlerta = $pdo->prepare(
        "SELECT enviado_em FROM alertas_enviados WHERE tipo='suspensao_prolongada' AND referencia_tipo='lead' AND referencia_id=? ORDER BY enviado_em DESC LIMIT 1"
    );
    $lastAlerta->execute(array($lead['id']));
    $lastRow = $lastAlerta->fetch();
    if ($lastRow) {
        $diasDesdeAlerta = (int)((time() - strtotime($lastRow['enviado_em'])) / 86400);
        if ($diasDesdeAlerta < 7) continue;
    }

    $msg = $lead['name'] . ' está suspenso há ' . $lead['dias_suspenso'] . ' dias sem movimentação';
    if ($lead['prazo_suspensao']) { $msg .= '. Prazo era ' . date('d/m/Y', strtotime($lead['prazo_suspensao'])); }

    if ($lead['assigned_to']) {
        notify((int)$lead['assigned_to'], 'Suspensão prolongada', $msg, 'alerta', url('modules/pipeline/'), '⏸️');
    }
    notify_gestao('Suspensão prolongada', $msg, 'alerta', url('modules/pipeline/'), '⏸️');

    $proximo = date('Y-m-d', strtotime('+7 days'));
    registrar_alerta($pdo, 'suspensao_prolongada', 'lead', (int)$lead['id'], $proximo);
    $totalAlertas++;
    echo "  Lead #{$lead['id']}: {$lead['name']} ({$lead['dias_suspenso']} dias)\n";
}

// Cases suspensos (mesmo padrão)
$stmt = $pdo->query(
    "SELECT c.id, c.title, c.responsible_user_id, c.data_suspensao, c.prazo_suspensao,
            DATEDIFF(NOW(), c.data_suspensao) as dias_suspenso
     FROM cases c
     WHERE c.status = 'suspenso'
       AND c.data_suspensao IS NOT NULL"
);
$cases = $stmt->fetchAll();
foreach ($cases as $caso) {
    $deveria_alertar = false;
    if ($caso['prazo_suspensao'] && $hoje >= $caso['prazo_suspensao']) {
        $deveria_alertar = true;
    } elseif (!$caso['prazo_suspensao'] && (int)$caso['dias_suspenso'] >= 30) {
        $deveria_alertar = true;
    }
    if (!$deveria_alertar) continue;

    $lastAlerta = $pdo->prepare(
        "SELECT enviado_em FROM alertas_enviados WHERE tipo='suspensao_prolongada' AND referencia_tipo='case' AND referencia_id=? ORDER BY enviado_em DESC LIMIT 1"
    );
    $lastAlerta->execute(array($caso['id']));
    $lastRow = $lastAlerta->fetch();
    if ($lastRow) {
        $diasDesdeAlerta = (int)((time() - strtotime($lastRow['enviado_em'])) / 86400);
        if ($diasDesdeAlerta < 7) continue;
    }

    $msg = $caso['title'] . ' está suspenso há ' . $caso['dias_suspenso'] . ' dias sem movimentação';
    if ($caso['prazo_suspensao']) { $msg .= '. Prazo era ' . date('d/m/Y', strtotime($caso['prazo_suspensao'])); }

    if ($caso['responsible_user_id']) {
        notify((int)$caso['responsible_user_id'], 'Suspensão prolongada', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '⏸️');
    }
    notify_gestao('Suspensão prolongada', $msg, 'alerta', url('modules/operacional/caso_ver.php?id=' . $caso['id']), '⏸️');

    $proximo = date('Y-m-d', strtotime('+7 days'));
    registrar_alerta($pdo, 'suspensao_prolongada', 'case', (int)$caso['id'], $proximo);
    $totalAlertas++;
    echo "  Caso #{$caso['id']}: {$caso['title']} ({$caso['dias_suspenso']} dias)\n";
}

echo "\n=== Total de alertas enviados: $totalAlertas ===\n";
echo "Concluído em " . date('H:i:s') . "\n";
