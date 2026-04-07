<?php
/**
 * API unificada do Card — retorna todos os dados do cliente/lead/caso
 * Usado pelo drawer lateral em ambos os Kanbans
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();

$clientId = (int)($_GET['client_id'] ?? 0);
$leadId = (int)($_GET['lead_id'] ?? 0);
$caseId = (int)($_GET['case_id'] ?? 0);

// Resolver IDs a partir de qualquer entrada
if ($leadId) {
    $r = $pdo->prepare("SELECT client_id, linked_case_id FROM pipeline_leads WHERE id = ?");
    $r->execute(array($leadId));
    $lr = $r->fetch();
    if ($lr) {
        if (!$clientId) $clientId = (int)$lr['client_id'];
        // Resolver case_id APENAS pelo linked_case_id — sem fallback por client_id
        // (fallback por client_id pode pegar o caso errado quando o cliente tem múltiplos)
        if (!$caseId) $caseId = (int)$lr['linked_case_id'];
    }
}
if ($caseId && !$clientId) {
    $r = $pdo->prepare("SELECT client_id FROM cases WHERE id = ?");
    $r->execute(array($caseId));
    $cr = $r->fetch();
    if ($cr) $clientId = (int)$cr['client_id'];
}
if ($clientId && !$leadId) {
    $r = $pdo->prepare("SELECT id FROM pipeline_leads WHERE client_id = ? AND stage NOT IN ('finalizado','perdido') ORDER BY id DESC LIMIT 1");
    $r->execute(array($clientId));
    $lr = $r->fetch();
    if ($lr) $leadId = (int)$lr['id'];
}
if ($clientId && !$caseId) {
    $r = $pdo->prepare("SELECT id FROM cases WHERE client_id = ? ORDER BY created_at DESC LIMIT 1");
    $r->execute(array($clientId));
    $cr = $r->fetch();
    if ($cr) $caseId = (int)$cr['id'];
}

if (!$clientId) { echo json_encode(array('error' => 'Cliente não encontrado')); exit; }

$result = array('client_id' => $clientId, 'lead_id' => $leadId, 'case_id' => $caseId);

// ── 1. CLIENTE ──
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute(array($clientId));
$result['client'] = $stmt->fetch();

// ── 1B. FORMULÁRIO DE CADASTRO (dados extras do cliente) ──
$result['form_data'] = null;
try {
    $stmtForm = $pdo->prepare("SELECT payload_json, form_type, created_at FROM form_submissions WHERE linked_client_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmtForm->execute(array($clientId));
    $formRow = $stmtForm->fetch();
    if ($formRow && $formRow['payload_json']) {
        $payload = json_decode($formRow['payload_json'], true);
        if (is_array($payload)) {
            $result['form_data'] = $payload;
            $result['form_type'] = $formRow['form_type'];
            $result['form_date'] = $formRow['created_at'];
        }
    }
} catch (Exception $e) {}

// ── 2. LEAD (Pipeline) ──
$result['lead'] = null;
if ($leadId) {
    $stmt = $pdo->prepare("SELECT pl.*, u.name as assigned_name FROM pipeline_leads pl LEFT JOIN users u ON u.id = pl.assigned_to WHERE pl.id = ?");
    $stmt->execute(array($leadId));
    $result['lead'] = $stmt->fetch();
}

// ── 3. CASO (Operacional) ──
$result['caso'] = null;
$result['casos_todos'] = array();
if ($caseId) {
    $stmt = $pdo->prepare("SELECT cs.*, u.name as responsible_name FROM cases cs LEFT JOIN users u ON u.id = cs.responsible_user_id WHERE cs.id = ?");
    $stmt->execute(array($caseId));
    $result['caso'] = $stmt->fetch();
}
// Todos os casos do cliente
$stmt = $pdo->prepare("SELECT id, title, status, case_number, case_type FROM cases WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute(array($clientId));
$result['casos_todos'] = $stmt->fetchAll();

// ── 4. PIPELINE HISTORY ──
$result['pipeline_history'] = array();
if ($leadId) {
    $stmt = $pdo->prepare("SELECT ph.*, u.name as user_name FROM pipeline_history ph LEFT JOIN users u ON u.id = ph.changed_by WHERE ph.lead_id = ? ORDER BY ph.created_at DESC LIMIT 20");
    $stmt->execute(array($leadId));
    $result['pipeline_history'] = $stmt->fetchAll();
}

// ── 5. TAREFAS ──
$result['tasks'] = array();
if ($caseId) {
    $stmt = $pdo->prepare("SELECT ct.*, u.name as assigned_name FROM case_tasks ct LEFT JOIN users u ON u.id = ct.assigned_to WHERE ct.case_id = ? ORDER BY ct.status ASC, ct.created_at ASC");
    $stmt->execute(array($caseId));
    $result['tasks'] = $stmt->fetchAll();
}

// ── 6. ANDAMENTOS ──
$result['andamentos'] = array();
if ($caseId) {
    $stmt = $pdo->prepare("SELECT a.*, u.name as user_name FROM case_andamentos a LEFT JOIN users u ON u.id = a.created_by WHERE a.case_id = ? ORDER BY a.data_andamento DESC, a.created_at DESC LIMIT 20");
    $stmt->execute(array($caseId));
    $result['andamentos'] = $stmt->fetchAll();
}

// ── 7. PROCESSOS INCIDENTAIS ──
$result['incidentais'] = array();
if ($caseId) {
    try {
        $stmtInc = $pdo->prepare("SELECT id, title, case_number, case_type, status, tipo_relacao FROM cases WHERE processo_principal_id = ? ORDER BY created_at DESC");
        $stmtInc->execute(array($caseId));
        $result['incidentais'] = $stmtInc->fetchAll();
    } catch (Exception $e) {}
}

// ── 8. DOCUMENTOS PENDENTES ──
// Busca por case_id OU lead_id OU client_id para garantir que documentos
// apareçam mesmo quando o lead não tem linked_case_id
$result['docs_pendentes'] = array();
try {
    $docConditions = array();
    $docParams = array();
    if ($caseId) { $docConditions[] = 'dp.case_id = ?'; $docParams[] = $caseId; }
    if ($leadId) { $docConditions[] = 'dp.lead_id = ?'; $docParams[] = $leadId; }
    if ($clientId && !$caseId && !$leadId) { $docConditions[] = 'dp.client_id = ?'; $docParams[] = $clientId; }
    if ($docConditions) {
        $docWhere = implode(' OR ', $docConditions);
        $stmt = $pdo->prepare("SELECT dp.*, u.name as solicitante_name FROM documentos_pendentes dp LEFT JOIN users u ON u.id = dp.solicitado_por WHERE ($docWhere) ORDER BY dp.solicitado_em DESC");
        $stmt->execute($docParams);
        // Deduplicar por ID (caso match por case_id E lead_id retorne o mesmo doc)
        $seen = array();
        foreach ($stmt->fetchAll() as $doc) {
            if (!isset($seen[$doc['id']])) {
                $seen[$doc['id']] = true;
                $result['docs_pendentes'][] = $doc;
            }
        }
    }
} catch (Exception $e) {}

// ── 8. PEÇAS GERADAS ──
$result['pecas'] = array();
try {
    $stmt = $pdo->prepare("SELECT cd.*, u.name as user_name FROM case_documents cd LEFT JOIN users u ON u.id = cd.gerado_por WHERE cd.client_id = ? ORDER BY cd.created_at DESC LIMIT 10");
    $stmt->execute(array($clientId));
    $result['pecas'] = $stmt->fetchAll();
} catch (Exception $e) {}

// ── 9. COBRANÇAS (Financeiro) ──
$result['cobrancas'] = array();
try {
    $stmt = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE client_id = ? ORDER BY vencimento DESC LIMIT 10");
    $stmt->execute(array($clientId));
    $result['cobrancas'] = $stmt->fetchAll();
} catch (Exception $e) {}

// ── 10. AGENDA ──
$result['compromissos'] = array();
try {
    $stmt = $pdo->prepare("SELECT e.*, u.name as responsavel_name FROM agenda_eventos e LEFT JOIN users u ON u.id = e.responsavel_id WHERE e.client_id = ? AND e.status != 'cancelado' ORDER BY e.data_inicio DESC LIMIT 10");
    $stmt->execute(array($clientId));
    $result['compromissos'] = $stmt->fetchAll();
} catch (Exception $e) {}

// ── 11. COMENTÁRIOS (filtrar por case_id > lead_id > client_id, nessa prioridade) ──
$result['comments'] = array();
try {
    if ($caseId) {
        $stmt = $pdo->prepare("SELECT cc.*, u.name as user_name FROM card_comments cc LEFT JOIN users u ON u.id = cc.user_id WHERE cc.case_id = ? ORDER BY cc.created_at DESC LIMIT 30");
        $stmt->execute(array($caseId));
    } elseif ($leadId) {
        $stmt = $pdo->prepare("SELECT cc.*, u.name as user_name FROM card_comments cc LEFT JOIN users u ON u.id = cc.user_id WHERE cc.lead_id = ? ORDER BY cc.created_at DESC LIMIT 30");
        $stmt->execute(array($leadId));
    } else {
        $stmt = $pdo->prepare("SELECT cc.*, u.name as user_name FROM card_comments cc LEFT JOIN users u ON u.id = cc.user_id WHERE cc.client_id = ? ORDER BY cc.created_at DESC LIMIT 30");
        $stmt->execute(array($clientId));
    }
    $result['comments'] = $stmt->fetchAll();
} catch (Exception $e) {}

// ── 12. HISTÓRICO UNIFICADO ──
$result['historico'] = array();
$histItems = array();

// Pipeline history
foreach ($result['pipeline_history'] as $h) {
    $histItems[] = array('date' => $h['created_at'], 'type' => 'pipeline', 'icon' => '📈', 'text' => ($h['user_name'] ? explode(' ', $h['user_name'])[0] : 'Sistema') . ' moveu para ' . ($h['to_stage'] ?: '?'), 'detail' => $h['notes'] ?: '');
}
// Andamentos
foreach ($result['andamentos'] as $a) {
    $histItems[] = array('date' => $a['created_at'], 'type' => 'andamento', 'icon' => '⚖️', 'text' => ($a['user_name'] ? explode(' ', $a['user_name'])[0] : 'Sistema') . ': ' . ($a['tipo'] ?: 'andamento'), 'detail' => mb_substr($a['descricao'], 0, 100));
}
// Peças
foreach ($result['pecas'] as $p) {
    $histItems[] = array('date' => $p['created_at'], 'type' => 'documento', 'icon' => '📝', 'text' => ($p['user_name'] ? explode(' ', $p['user_name'])[0] : '') . ' gerou ' . ($p['tipo_peca'] ?: 'documento'), 'detail' => $p['titulo'] ?: '');
}
// Compromissos
foreach ($result['compromissos'] as $c) {
    $histItems[] = array('date' => $c['data_inicio'], 'type' => 'agenda', 'icon' => '📅', 'text' => $c['titulo'], 'detail' => $c['tipo'] ?: '');
}

usort($histItems, function($a, $b) { return strcmp($b['date'], $a['date']); });
$result['historico'] = array_slice($histItems, 0, 30);

// ── STAGE LABELS ──
$result['stage_labels'] = array(
    'cadastro_preenchido'=>'Cadastro','elaboracao_docs'=>'Elaboração','link_enviados'=>'Link Enviado',
    'contrato_assinado'=>'Contrato Assinado','agendado_docs'=>'Agendado Docs','reuniao_cobranca'=>'Cobrando Docs',
    'doc_faltante'=>'Doc Faltante','pasta_apta'=>'Pasta Apta','finalizado'=>'Finalizado','perdido'=>'Cancelado','cancelado'=>'Cancelado',
);
$result['status_labels'] = array(
    'aguardando_docs'=>'Ag. Docs','em_elaboracao'=>'Pasta Apta','em_andamento'=>'Em Andamento',
    'doc_faltante'=>'Doc Faltante','aguardando_prazo'=>'Aguard. Distribuição','distribuido'=>'Distribuído',
    'suspenso'=>'Suspenso','concluido'=>'Finalizado','arquivado'=>'Arquivado','cancelado'=>'Cancelado','renunciamos'=>'Renunciamos',
);

// Permissões do usuário logado
$result['can_comercial'] = has_role('admin','gestao','comercial','cx');
$result['can_financeiro'] = can_access('faturamento');

// CSRF token fresco para ações do drawer
$result['csrf'] = generate_csrf_token();

echo json_encode($result, JSON_UNESCAPED_UNICODE);
