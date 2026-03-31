<?php
/**
 * Migra dados das tabelas importadas (clientes, processos, kanban_cards)
 * para as tabelas ativas do Hub (clients, pipeline_leads, cases)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: Tabelas importadas → Hub ===\n\n";

// ─── PASSO 0: Preparar ENUMs ────────────────────────────
echo "0. Preparando ENUMs...\n";
try {
    $pdo->exec("ALTER TABLE `pipeline_leads` MODIFY `stage` VARCHAR(40) NOT NULL DEFAULT 'cadastro_preenchido'");
    echo "   pipeline_leads.stage → VARCHAR(40)\n";
} catch (Exception $e) { echo "   " . $e->getMessage() . "\n"; }

try {
    $pdo->exec("ALTER TABLE `cases` MODIFY `case_type` VARCHAR(60) NOT NULL DEFAULT 'outro'");
    echo "   cases.case_type → VARCHAR(60)\n";
} catch (Exception $e) { echo "   " . $e->getMessage() . "\n"; }

// ─── PASSO 1: Migrar clientes → clients ─────────────────
echo "\n1. Migrando clientes → clients...\n";

// Mapeamento de responsável para user_id
$userMap = array();
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1")->fetchAll();
foreach ($users as $u) {
    $first = mb_strtolower(explode(' ', $u['name'])[0]);
    $userMap[$first] = (int)$u['id'];
}

$clientes = $pdo->query("SELECT c.*, k.coluna_atual, k.kanban FROM clientes c LEFT JOIN kanban_cards k ON k.cliente_id = c.id AND k.kanban = 'comercial_cx' ORDER BY c.id")->fetchAll();

$clientMap = array(); // clientes.id → clients.id
$inserted = 0;
$skipped = 0;

foreach ($clientes as $cl) {
    $nome = trim($cl['nome_completo']);
    if (!$nome || strpos(strtoupper($nome), 'NÃO PREENCHER') !== false || strpos(strtoupper($nome), 'NAO PREENCHER') !== false || strpos(strtoupper($nome), 'NÃO ATUALIZAR') !== false) {
        $skipped++;
        continue;
    }

    // Verificar se já existe por nome exato
    $existing = $pdo->prepare("SELECT id FROM clients WHERE name = ? LIMIT 1");
    $existing->execute(array($nome));
    $existRow = $existing->fetch();

    if ($existRow) {
        $clientMap[(int)$cl['id']] = (int)$existRow['id'];
        $skipped++;
        continue;
    }

    // Inserir novo cliente
    $phone = $cl['telefone'] ?: null;
    $source = 'outro';

    $stmt = $pdo->prepare("INSERT INTO clients (name, phone, source, notes, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(array(
        $nome,
        $phone,
        $source,
        $cl['pendencias'] ? 'Pendências: ' . $cl['pendencias'] : null,
        $cl['created_at'] ?: date('Y-m-d H:i:s')
    ));
    $newId = (int)$pdo->lastInsertId();
    $clientMap[(int)$cl['id']] = $newId;
    $inserted++;
}

echo "   Inseridos: $inserted | Já existiam: $skipped\n";

// ─── PASSO 2: Migrar kanban_cards comercial → pipeline_leads ──
echo "\n2. Migrando kanban comercial → pipeline_leads...\n";

// Mapeamento de colunas kanban → stages do pipeline
$stageMap = array(
    'pasta_apta' => 'pasta_apta',
    'elaboracao_procuracao' => 'elaboracao_docs',
    'reuniao_cobrando_docs' => 'reuniao_cobranca',
    'suspenso' => 'suspenso',
    'cancelado' => 'cancelado',
    'processo_distribuido' => 'finalizado',  // já foi distribuído = finalizado no pipeline
);

$comercialCards = $pdo->query(
    "SELECT k.*, c.nome_completo, c.telefone, c.tipo_acao, c.responsavel, c.nome_pasta_drive
     FROM kanban_cards k
     JOIN clientes c ON c.id = k.cliente_id
     WHERE k.kanban = 'comercial_cx'"
)->fetchAll();

$leadsInserted = 0;
$leadsSkipped = 0;

foreach ($comercialCards as $card) {
    $clientId = isset($clientMap[(int)$card['cliente_id']]) ? $clientMap[(int)$card['cliente_id']] : null;
    if (!$clientId) { $leadsSkipped++; continue; }

    $nome = trim($card['nome_completo']);
    if (!$nome || strpos(strtoupper($nome), 'NÃO PREENCHER') !== false) { $leadsSkipped++; continue; }

    // Verificar se já existe lead para este client
    $existLead = $pdo->prepare("SELECT id FROM pipeline_leads WHERE client_id = ? LIMIT 1");
    $existLead->execute(array($clientId));
    if ($existLead->fetch()) { $leadsSkipped++; continue; }

    $stage = isset($stageMap[$card['coluna_atual']]) ? $stageMap[$card['coluna_atual']] : 'elaboracao_docs';

    // Mapear responsável
    $assignedTo = null;
    if ($card['responsavel']) {
        $resp = mb_strtolower(trim($card['responsavel']));
        if (isset($userMap[$resp])) $assignedTo = $userMap[$resp];
        elseif ($resp === 'dudu' && isset($userMap['luiz'])) $assignedTo = $userMap['luiz'];
    }

    $convertedAt = in_array($stage, array('pasta_apta', 'finalizado')) ? ($card['dt_movimentacao'] ?: date('Y-m-d H:i:s')) : null;

    $stmt = $pdo->prepare(
        "INSERT INTO pipeline_leads (client_id, name, phone, source, stage, case_type, assigned_to, converted_at, notes, created_at)
         VALUES (?, ?, ?, 'outro', ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute(array(
        $clientId,
        $nome,
        $card['telefone'] ?: null,
        $stage,
        $card['tipo_acao'] ?: null,
        $assignedTo,
        $convertedAt,
        'Importado da planilha. Pasta: ' . ($card['nome_pasta_drive'] ?: 'N/A'),
        $card['dt_movimentacao'] ?: date('Y-m-d H:i:s')
    ));
    $leadsInserted++;
}

echo "   Leads inseridos: $leadsInserted | Ignorados: $leadsSkipped\n";

// ─── PASSO 3: Migrar processos + kanban operacional → cases ──
echo "\n3. Migrando processos + kanban operacional → cases...\n";

// Mapeamento de colunas kanban operacional → status do cases
$opStatusMap = array(
    'processo_distribuido' => 'distribuido',
    'em_execucao' => 'em_andamento',
    'aguardando_inicio' => 'aguardando_docs',
    'pasta_apta' => 'em_elaboracao',
    'documento_faltante' => 'doc_faltante',
    'parceria_previdenciario' => 'parceria_previdenciario',
    'cancelado' => 'cancelado',
);

$opCards = $pdo->query(
    "SELECT k.*, c.nome_completo, c.tipo_acao, c.responsavel, c.nome_pasta_drive,
     p.numero_processo, p.vara_juizo, p.data_distribuicao, p.data_cadastro,
     p.executante, p.link_drive, p.observacoes as proc_obs, p.tipo_especial as proc_tipo_especial
     FROM kanban_cards k
     JOIN clientes c ON c.id = k.cliente_id
     LEFT JOIN processos p ON p.nome_pasta = c.nome_pasta_drive
     WHERE k.kanban = 'operacional'"
)->fetchAll();

$casesInserted = 0;
$casesSkipped = 0;

foreach ($opCards as $card) {
    $clientId = isset($clientMap[(int)$card['cliente_id']]) ? $clientMap[(int)$card['cliente_id']] : null;
    if (!$clientId) { $casesSkipped++; continue; }

    $nome = trim($card['nome_completo']);
    if (!$nome || strpos(strtoupper($nome), 'NÃO PREENCHER') !== false) { $casesSkipped++; continue; }

    // Verificar se já existe caso para este client com mesmo título
    $title = $card['nome_pasta_drive'] ?: ($card['tipo_acao'] ? $card['tipo_acao'] . ' — ' . $nome : 'Caso — ' . $nome);
    $existCase = $pdo->prepare("SELECT id FROM cases WHERE client_id = ? AND title = ? LIMIT 1");
    $existCase->execute(array($clientId, $title));
    if ($existCase->fetch()) { $casesSkipped++; continue; }

    $status = isset($opStatusMap[$card['coluna_atual']]) ? $opStatusMap[$card['coluna_atual']] : 'em_andamento';

    // Mapear responsável
    $assignedTo = null;
    $resp = $card['responsavel'] ?: $card['executante'];
    if ($resp) {
        $respLower = mb_strtolower(trim($resp));
        if (isset($userMap[$respLower])) $assignedTo = $userMap[$respLower];
        elseif ($respLower === 'dudu' && isset($userMap['luiz'])) $assignedTo = $userMap['luiz'];
        elseif ($respLower === 'carina' && isset($userMap['carina'])) $assignedTo = $userMap['carina'];
        elseif ($respLower === 'fla' || $respLower === 'lu') $assignedTo = isset($userMap['amanda']) ? $userMap['amanda'] : null;
    }

    // Tipo especial
    $tipoEspecial = 'nenhum';
    if ($card['proc_tipo_especial'] && $card['proc_tipo_especial'] !== 'nenhum') {
        $tipoEspecial = $card['proc_tipo_especial'];
    }
    if ($card['coluna_atual'] === 'parceria_previdenciario') {
        $tipoEspecial = 'previdenciario';
    }

    // Case type
    $caseType = 'outro';
    $tipoAcao = mb_strtolower($card['tipo_acao'] ?: '');
    if (strpos($tipoAcao, 'aliment') !== false || strpos($tipoAcao, 'pensão') !== false || strpos($tipoAcao, 'pensao') !== false) $caseType = 'pensao';
    elseif (strpos($tipoAcao, 'divórcio') !== false || strpos($tipoAcao, 'divorcio') !== false || strpos($tipoAcao, 'dissolução') !== false) $caseType = 'divorcio';
    elseif (strpos($tipoAcao, 'guarda') !== false) $caseType = 'guarda';
    elseif (strpos($tipoAcao, 'convivência') !== false || strpos($tipoAcao, 'convivencia') !== false) $caseType = 'convivencia';
    elseif (strpos($tipoAcao, 'inventário') !== false || strpos($tipoAcao, 'inventario') !== false) $caseType = 'inventario';

    $driveUrl = $card['link_drive'] ?: null;
    $closedAt = in_array($status, array('distribuido', 'cancelado')) ? ($card['data_distribuicao'] ?: date('Y-m-d')) : null;

    $stmt = $pdo->prepare(
        "INSERT INTO cases (client_id, title, case_type, tipo_especial, case_number, court, distribution_date, status, priority, responsible_user_id, drive_folder_url, notes, opened_at, closed_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'normal', ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute(array(
        $clientId,
        $title,
        $caseType,
        $tipoEspecial,
        $card['numero_processo'] ?: null,
        $card['vara_juizo'] ?: null,
        $card['data_distribuicao'] ?: null,
        $status,
        $assignedTo,
        $driveUrl,
        $card['proc_obs'] ?: null,
        $card['data_cadastro'] ?: date('Y-m-d'),
        $closedAt,
        $card['dt_movimentacao'] ?: date('Y-m-d H:i:s')
    ));

    $newCaseId = (int)$pdo->lastInsertId();

    // Vincular lead ao caso (se existir)
    $leadStmt = $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = ? WHERE client_id = ? AND linked_case_id IS NULL LIMIT 1");
    $leadStmt->execute(array($newCaseId, $clientId));

    $casesInserted++;
}

echo "   Cases inseridos: $casesInserted | Ignorados: $casesSkipped\n";

// ─── VERIFICAÇÃO FINAL ──────────────────────────────────
echo "\n=== VERIFICAÇÃO FINAL ===\n";
echo "clients: " . $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn() . "\n";
echo "pipeline_leads: " . $pdo->query("SELECT COUNT(*) FROM pipeline_leads")->fetchColumn() . "\n";
echo "cases: " . $pdo->query("SELECT COUNT(*) FROM cases")->fetchColumn() . "\n";

echo "\nPipeline stages:\n";
$rows = $pdo->query("SELECT stage, COUNT(*) as qtd FROM pipeline_leads GROUP BY stage ORDER BY qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  {$r['stage']}: {$r['qtd']}\n"; }

echo "\nCases status:\n";
$rows = $pdo->query("SELECT status, COUNT(*) as qtd FROM cases GROUP BY status ORDER BY qtd DESC")->fetchAll();
foreach ($rows as $r) { echo "  {$r['status']}: {$r['qtd']}\n"; }

echo "\nPronto!\n";
