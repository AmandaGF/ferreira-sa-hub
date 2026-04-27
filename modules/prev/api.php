<?php
/**
 * Ferreira & Sá Hub — API Kanban PREV (Previdenciário)
 * Gatilhos: doc_faltante bilateral, suspensão, parceria, movimentações
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('prev');

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('prev')); }

$action = $_POST['action'] ?? '';

// Ações de leitura AJAX: não consomem CSRF
$readOnlyActions = array('inline_edit_case');
$skipCsrf = $isAjax && in_array($action, $readOnlyActions);

if (!$skipCsrf && !validate_csrf()) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Token inválido')); exit; }
    flash_set('error', 'Token inválido.'); redirect(module_url('prev'));
}
$pdo = db();

// Helper: buscar lead vinculado ao caso
function buscarLeadVinculadoPrev($pdo, $caseId, $clientId = 0) {
    $stmt = $pdo->prepare("SELECT id, stage, coluna_antes_suspensao, stage_antes_doc_faltante FROM pipeline_leads WHERE linked_case_id = ? LIMIT 1");
    $stmt->execute(array($caseId));
    $row = $stmt->fetch();
    if ($row) return $row;
    if ($clientId > 0) {
        $stmt2 = $pdo->prepare("SELECT id, stage, coluna_antes_suspensao, stage_antes_doc_faltante FROM pipeline_leads WHERE client_id = ? AND stage NOT IN ('finalizado','perdido') ORDER BY id DESC LIMIT 1");
        $stmt2->execute(array($clientId));
        return $stmt2->fetch();
    }
    return null;
}

// Status válidos do PREV
$validPrevStatuses = array(
    'aguardando_docs', 'pasta_apta', 'aguardando_analise_inss', 'aguardando_pericia',
    'recurso_administrativo', 'recurso_crps', 'acao_judicial', 'aguardando_sentenca',
    'cumprimento_precatorio', 'aguardando_implantacao', 'suspenso', 'parceria', 'cancelado'
);

switch ($action) {
    case 'update_prev_status':
        $caseId = (int)($_POST['case_id'] ?? 0);
        $newStatus = $_POST['new_prev_status'] ?? '';

        if (!$caseId || !in_array($newStatus, $validPrevStatuses)) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Dados inválidos')); exit; }
            flash_set('error', 'Dados inválidos.');
            redirect(module_url('prev'));
            break;
        }

        // Buscar caso atual
        $caseStmt = $pdo->prepare('SELECT * FROM cases WHERE id = ? AND kanban_prev = 1');
        $caseStmt->execute(array($caseId));
        $currentCase = $caseStmt->fetch();
        if (!$currentCase) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Caso não encontrado')); exit; }
            flash_set('error', 'Caso não encontrado.');
            redirect(module_url('prev'));
            break;
        }

        $oldStatus = $currentCase['prev_status'] ?: 'aguardando_docs';
        $clientId = (int)$currentCase['client_id'];

        // ── DOC FALTANTE (aguardando_docs com descrição): bilateral ──
        if ($newStatus === 'aguardando_docs' && isset($_POST['doc_faltante_desc']) && $_POST['doc_faltante_desc']) {
            $docDescRaw = clean_str($_POST['doc_faltante_desc'], 1000);
            $docItens = array_filter(array_map('trim', explode(';', $docDescRaw)));
            if (empty($docItens)) $docItens = array($docDescRaw);
            $docDesc = implode('; ', $docItens);

            // Salvar status anterior
            $pdo->prepare('UPDATE cases SET prev_status=?, stage_antes_doc_faltante=?, updated_at=NOW() WHERE id=?')
                ->execute(array('aguardando_docs', $oldStatus, $caseId));

            // Registrar cada documento pendente
            $leadRow = buscarLeadVinculadoPrev($pdo, $caseId, $clientId);
            $leadId = $leadRow ? (int)$leadRow['id'] : null;

            try {
                if ($clientId > 0) {
                    $stmtDoc = $pdo->prepare("INSERT INTO documentos_pendentes (client_id, case_id, lead_id, descricao, solicitado_por) VALUES (?,?,?,?,?)");
                    foreach ($docItens as $docItem) {
                        $stmtDoc->execute(array($clientId, $caseId, $leadId, $docItem, current_user_id()));
                    }
                }
            } catch (Exception $e) {}

            // Espelhar no Pipeline
            if ($leadId && !in_array($leadRow['stage'], array('cancelado', 'finalizado', 'perdido', 'doc_faltante'))) {
                try {
                    $pdo->prepare("UPDATE pipeline_leads SET stage='doc_faltante', doc_faltante_motivo=?, stage_antes_doc_faltante=stage, updated_at=NOW() WHERE id=?")
                        ->execute(array($docDesc, $leadId));
                    $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                        ->execute(array($leadId, 'auto', 'doc_faltante', current_user_id(), 'PREV sinalizou: ' . $docDesc));
                } catch (Exception $e) {}
            }

            // Notificar
            $cliName = $currentCase['title'] ?: 'Caso #' . $caseId;
            notify_gestao('Doc faltante (PREV)', $cliName . ' — ' . $docDesc, 'alerta', url('modules/prev/'), '⚠️');

            // Notificar cliente
            if ($clientId > 0) {
                notificar_cliente('doc_faltante', $clientId, array(
                    '[descricao_documento]' => $docDesc,
                    '[tipo_acao]' => $currentCase['prev_tipo_beneficio'] ?: ($currentCase['case_type'] ?: ''),
                ), $caseId, $leadId);
            }

            audit_log('prev_doc_faltante', 'case', $caseId, $docDesc);

            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
            flash_set('success', 'Documento faltante sinalizado (PREV).');
            redirect(module_url('prev'));
            break;
        }

        // ── SUSPENSO: bilateral com memória ──
        if ($newStatus === 'suspenso') {
            $suspMotivo = clean_str($_POST['suspensao_motivo'] ?? '', 100);
            $suspRetorno = ($_POST['suspensao_retorno_previsto'] ?? '') ?: null;

            $pdo->prepare('UPDATE cases SET prev_status=?, coluna_antes_suspensao=?, data_suspensao=NOW(), suspensao_motivo=?, suspensao_retorno_previsto=?, updated_at=NOW() WHERE id=?')
                ->execute(array('suspenso', $oldStatus, $suspMotivo ?: null, $suspRetorno, $caseId));

            // Espelhar no Pipeline
            $leadRow = buscarLeadVinculadoPrev($pdo, $caseId, $clientId);
            if ($leadRow && !in_array($leadRow['stage'], array('cancelado', 'finalizado', 'perdido', 'suspenso'))) {
                $pdo->prepare("UPDATE pipeline_leads SET stage='suspenso', coluna_antes_suspensao=?, data_suspensao=NOW(), prazo_suspensao=?, updated_at=NOW() WHERE id=?")
                    ->execute(array($leadRow['stage'], $suspRetorno, $leadRow['id']));
                $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                    ->execute(array($leadRow['id'], $leadRow['stage'], 'suspenso', current_user_id(), 'Auto: PREV suspendeu caso #' . $caseId));
            }

            $motivoMsg = $suspMotivo ? ' Motivo: ' . $suspMotivo : '';
            notify_gestao('Caso PREV suspenso', ($currentCase['title'] ?: 'Caso') . ' suspenso.' . $motivoMsg, 'alerta', url('modules/prev/'), '⏸️');
            audit_log('prev_suspenso', 'case', $caseId, 'Anterior: ' . $oldStatus . $motivoMsg);

            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
            flash_set('success', 'Caso PREV suspenso.');
            redirect(module_url('prev'));
            break;
        }

        // ── REATIVAR DO SUSPENSO ──
        if ($oldStatus === 'suspenso' && $newStatus !== 'suspenso') {
            $leadRow = buscarLeadVinculadoPrev($pdo, $caseId, $clientId);
            if ($leadRow && $leadRow['stage'] === 'suspenso') {
                $stageAnterior = $leadRow['coluna_antes_suspensao'] ?: 'elaboracao_docs';
                $pdo->prepare("UPDATE pipeline_leads SET stage=?, coluna_antes_suspensao=NULL, data_suspensao=NULL, prazo_suspensao=NULL, updated_at=NOW() WHERE id=?")
                    ->execute(array($stageAnterior, $leadRow['id']));
                $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                    ->execute(array($leadRow['id'], 'suspenso', $stageAnterior, current_user_id(), 'Auto: PREV reativou caso #' . $caseId));
            }
            $pdo->prepare('UPDATE cases SET coluna_antes_suspensao=NULL, data_suspensao=NULL, suspensao_motivo=NULL, suspensao_retorno_previsto=NULL, suspensao_observacao=NULL WHERE id=?')
                ->execute(array($caseId));
            notify_gestao('Caso PREV reativado', ($currentCase['title'] ?: 'Caso') . ' reativado.', 'sucesso', url('modules/prev/'), '▶️');
        }

        // ── CANCELADO: só admin ──
        if ($newStatus === 'cancelado') {
            if (!has_role('admin')) {
                $msg = 'Apenas administradores podem cancelar.';
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => $msg)); exit; }
                flash_set('error', $msg);
                redirect(module_url('prev'));
                break;
            }
            // Cancelar lead vinculado
            $leadRow = buscarLeadVinculadoPrev($pdo, $caseId, $clientId);
            if ($leadRow && !in_array($leadRow['stage'], array('cancelado', 'finalizado'))) {
                $pdo->prepare("UPDATE pipeline_leads SET stage = 'cancelado', updated_at = NOW() WHERE id = ?")
                    ->execute(array($leadRow['id']));
                $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                    ->execute(array($leadRow['id'], $leadRow['stage'], 'cancelado', current_user_id(), 'Auto: PREV cancelou caso #' . $caseId));
            }
            notify_gestao('Caso PREV cancelado', ($currentCase['title'] ?: 'Caso') . ' foi cancelado.', 'alerta', url('modules/prev/'), '✕');
        }

        // ── PARCERIA: salvar parceiro ──
        if ($newStatus === 'parceria' && isset($_POST['parceiro_id'])) {
            $parceiroId = (int)$_POST['parceiro_id'];
            if ($parceiroId) {
                $pdo->prepare("UPDATE cases SET parceiro_id = ? WHERE id = ?")->execute(array($parceiroId, $caseId));
                audit_log('prev_parceiro_vinculado', 'case', $caseId, 'Parceiro #' . $parceiroId);
            }
        }

        // ── RETORNO DE DOC FALTANTE ──
        if ($oldStatus === 'aguardando_docs' && $newStatus !== 'aguardando_docs') {
            // Marcar docs como recebidos
            $pdo->prepare("UPDATE documentos_pendentes SET status = 'recebido', recebido_em = NOW(), recebido_por = ? WHERE case_id = ? AND status = 'pendente'")
                ->execute(array(current_user_id(), $caseId));

            // Restaurar lead do Pipeline
            $leadRow = buscarLeadVinculadoPrev($pdo, $caseId, $clientId);
            if ($leadRow && $leadRow['stage'] === 'doc_faltante') {
                $stageAnterior = $leadRow['stage_antes_doc_faltante'] ?: 'elaboracao_docs';
                $pdo->prepare("UPDATE pipeline_leads SET stage=?, doc_faltante_motivo=NULL, stage_antes_doc_faltante=NULL, updated_at=NOW() WHERE id=?")
                    ->execute(array($stageAnterior, $leadRow['id']));
                $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                    ->execute(array($leadRow['id'], 'doc_faltante', $stageAnterior, current_user_id(), 'Auto: PREV docs recebidos'));
            }

            // Limpar estado
            $pdo->prepare("UPDATE cases SET stage_antes_doc_faltante = NULL WHERE id = ?")->execute(array($caseId));

            // Notificar cliente
            if ($clientId > 0) {
                notificar_cliente('docs_recebidos', $clientId, array(
                    '[tipo_acao]' => $currentCase['prev_tipo_beneficio'] ?: ($currentCase['case_type'] ?: ''),
                ), $caseId);
            }
        }

        // ── MOVIMENTAÇÃO NORMAL ──
        $pdo->prepare('UPDATE cases SET prev_status=?, updated_at=NOW() WHERE id=?')
            ->execute(array($newStatus, $caseId));
        audit_log('prev_status', 'case', $caseId, $oldStatus . ' → ' . $newStatus);

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
        flash_set('success', 'Status PREV atualizado.');
        redirect(module_url('prev'));
        break;

    case 'create_prev_case':
        // Criar caso diretamente no Kanban PREV
        $client_id = (int)($_POST['client_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $case_type = trim($_POST['case_type'] ?? '');
        $prev_tipo_beneficio = trim($_POST['prev_tipo_beneficio'] ?? '');
        $priority = in_array(($_POST['priority'] ?? ''), array('urgente','alta','normal','baixa')) ? $_POST['priority'] : 'normal';
        $responsible_user_id = (int)($_POST['responsible_user_id'] ?? 0);
        $drive_folder_url = trim($_POST['drive_folder_url'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $case_number = trim($_POST['case_number'] ?? '');
        $court = trim($_POST['court'] ?? '');
        $comarca = trim($_POST['comarca'] ?? '');
        $comarca_uf = trim($_POST['comarca_uf'] ?? '');
        $prev_numero_beneficio = trim($_POST['prev_numero_beneficio'] ?? '');

        $errors = array();
        if ($title === '') $errors[] = 'O título é obrigatório.';
        if ($client_id < 1) $errors[] = 'Selecione um cliente.';
        if ($prev_tipo_beneficio === '') $errors[] = 'Selecione o tipo de benefício.';

        if (!empty($errors)) {
            flash_set('error', implode(' ', $errors));
            redirect(module_url('prev', 'caso_novo.php'));
            break;
        }

        $sql = "INSERT INTO cases
            (client_id, title, case_type, case_number, court, comarca, comarca_uf, status, priority, responsible_user_id, drive_folder_url, notes,
             kanban_prev, prev_status, prev_enviado_em, prev_mes_envio, prev_ano_envio, prev_tipo_beneficio, prev_numero_beneficio, departamento, created_at, updated_at)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, 'em_andamento', ?, ?, ?, ?,
             1, 'aguardando_docs', NOW(), MONTH(NOW()), YEAR(NOW()), ?, ?, 'operacional', NOW(), NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(
            $client_id, $title, $case_type ?: 'outro', $case_number ?: null, $court ?: null,
            $comarca ?: null, $comarca_uf ?: null, $priority,
            $responsible_user_id > 0 ? $responsible_user_id : null,
            $drive_folder_url ?: null, $notes ?: null,
            $prev_tipo_beneficio, $prev_numero_beneficio ?: null
        ));

        $newId = (int)$pdo->lastInsertId();

        // Criar partes se veio cliente
        if ($client_id > 0) {
            try {
                $cl = $pdo->prepare("SELECT name, cpf, rg, birth_date, profession, marital_status, email, phone, address_street, address_city, address_state FROM clients WHERE id = ?");
                $cl->execute(array($client_id));
                $cliData = $cl->fetch();
                if ($cliData) {
                    $pdo->prepare("INSERT INTO case_partes (case_id, papel, tipo_pessoa, nome, cpf, rg, nascimento, profissao, estado_civil, email, telefone, endereco, cidade, uf, client_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute(array($newId, 'autor', 'fisica', $cliData['name'], $cliData['cpf'], $cliData['rg'], $cliData['birth_date'], $cliData['profession'], $cliData['marital_status'], $cliData['email'], $cliData['phone'], $cliData['address_street'], $cliData['address_city'], $cliData['address_state'], $client_id));
                }
            } catch (Exception $e) {}
        }

        audit_log('prev_caso_criado', 'case', $newId, 'PREV: ' . $prev_tipo_beneficio);
        notify_gestao('Novo caso PREV', $title . ' — ' . $prev_tipo_beneficio, 'info', url('modules/prev/'), '🏛️');

        flash_set('success', 'Processo PREV cadastrado com sucesso!');
        redirect(module_url('operacional', 'caso_ver.php?id=' . $newId));
        break;

    default:
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Ação inválida')); exit; }
        flash_set('error', 'Ação inválida.');
        redirect(module_url('prev'));
}
