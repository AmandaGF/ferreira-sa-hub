<?php
/**
 * Ferreira & Sá Hub — API Operacional
 * Gatilhos: doc_faltante bidirecional, processo distribuído modal, auto-finalizar pipeline
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('operacional')); }
if (!validate_csrf()) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Token inválido')); exit; }
    flash_set('error', 'Token inválido.'); redirect(module_url('operacional'));
}

$action = $_POST['action'] ?? '';
$pdo = db();

// Helper: buscar lead vinculado ao caso (por case_id ou client_id)
function buscarLeadVinculado($pdo, $caseId, $clientId = 0) {
    // Primeiro por linked_case_id
    $stmt = $pdo->prepare("SELECT id, stage, coluna_antes_suspensao, stage_antes_doc_faltante FROM pipeline_leads WHERE linked_case_id = ? LIMIT 1");
    $stmt->execute(array($caseId));
    $row = $stmt->fetch();
    if ($row) return $row;
    // Fallback por client_id
    if ($clientId > 0) {
        $stmt2 = $pdo->prepare("SELECT id, stage, coluna_antes_suspensao, stage_antes_doc_faltante FROM pipeline_leads WHERE client_id = ? AND stage NOT IN ('finalizado','perdido') ORDER BY id DESC LIMIT 1");
        $stmt2->execute(array($clientId));
        return $stmt2->fetch();
    }
    return null;
}

switch ($action) {
    case 'ocultar_kanban':
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($caseId) {
            $pdo->prepare("UPDATE cases SET kanban_oculto = 1 WHERE id = ?")->execute(array($caseId));
            audit_log('kanban_oculto', 'case', $caseId);
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
        flash_set('success', 'Processo ocultado do Kanban.');
        redirect(module_url('operacional'));
        break;

    case 'update_status':
        if (!has_min_role('gestao') && !has_role('colaborador')) { break; }
        $caseId = (int)($_POST['case_id'] ?? 0);
        $status = isset($_POST['new_status']) && $_POST['new_status'] ? $_POST['new_status'] : (isset($_POST['status']) ? $_POST['status'] : '');
        $validStatuses = array('em_andamento','suspenso','arquivado','renunciamos',
            // Legados (processos antigos que ainda têm esses status)
            'aguardando_docs','em_elaboracao','doc_faltante','aguardando_prazo','distribuido','parceria_previdenciario','cancelado','concluido','finalizado');

        if ($caseId && in_array($status, $validStatuses)) {
            // Buscar caso atual
            $caseStmt = $pdo->prepare('SELECT * FROM cases WHERE id = ?');
            $caseStmt->execute(array($caseId));
            $currentCase = $caseStmt->fetch();
            $oldStatus = $currentCase ? $currentCase['status'] : '';

            // ── DOC FALTANTE: espelhar no Pipeline Comercial/CX ──
            if ($status === 'doc_faltante') {
                $docDesc = clean_str($_POST['doc_faltante_desc'] ?? 'Documento não especificado', 300);

                // Salvar status anterior para retorno
                $pdo->prepare('UPDATE cases SET status=?, stage_antes_doc_faltante=?, updated_at=NOW() WHERE id=?')
                    ->execute(array($status, $oldStatus, $caseId));

                // Registrar documento pendente
                $clientId = $currentCase ? (int)$currentCase['client_id'] : 0;
                $leadRow = buscarLeadVinculado($pdo, $caseId, $clientId);
                $leadId = $leadRow ? (int)$leadRow['id'] : null;

                try {
                    if ($clientId > 0) {
                        $pdo->prepare("INSERT INTO documentos_pendentes (client_id, case_id, lead_id, descricao, solicitado_por) VALUES (?,?,?,?,?)")
                            ->execute(array($clientId, $caseId, $leadId, $docDesc, current_user_id()));
                    }
                } catch (Exception $e) { /* silenciar FK errors */ }

                // Espelhar no Pipeline: mover lead para doc_faltante
                if ($leadId) {
                    try {
                        $pdo->prepare("UPDATE pipeline_leads SET stage='doc_faltante', doc_faltante_motivo=?, stage_antes_doc_faltante=stage, updated_at=NOW() WHERE id=?")
                            ->execute(array($docDesc, $leadId));
                        $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                            ->execute(array($leadId, 'auto', 'doc_faltante', current_user_id(), 'Operacional sinalizou: ' . $docDesc));
                    } catch (Exception $e) { /* silenciar se lead não compatível */ }
                }

                // Notificar CX/gestão
                $cliName = $currentCase ? $currentCase['title'] : 'Caso #' . $caseId;
                notify_gestao('Documento faltante!', $cliName . ' — ' . $docDesc, 'alerta', url('modules/pipeline/'), '⚠️');

                // BLOCO 4: Notificar cliente — Documento faltante
                if ($clientId > 0) {
                    notificar_cliente('doc_faltante', $clientId, array(
                        '[descricao_documento]' => $docDesc,
                        '[tipo_acao]' => $currentCase ? ($currentCase['case_type'] ?: '') : '',
                    ), $caseId, $leadId);
                }

                audit_log('doc_faltante', 'case', $caseId, $docDesc);

                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
                flash_set('success', 'Documento faltante sinalizado. CX notificado.');
                redirect(module_url('operacional'));
                exit;
            }

            // ── PROCESSO DISTRIBUÍDO / EXTRAJUDICIAL: salvar dados do modal ──
            if ($status === 'distribuido') {
                $procNumero = clean_str($_POST['proc_numero'] ?? '', 30);
                $procVara = clean_str($_POST['proc_vara'] ?? '', 150);
                $procTipo = clean_str($_POST['proc_tipo'] ?? '', 60);
                $procData = $_POST['proc_data'] ?? null;
                if ($procData === '') $procData = null;
                $procCategory = ($_POST['proc_category'] ?? 'judicial') === 'extrajudicial' ? 'extrajudicial' : 'judicial';
                $procComarca = clean_str($_POST['proc_comarca'] ?? '', 100);
                $procComarcaUf = clean_str($_POST['proc_comarca_uf'] ?? '', 2);
                $procRegional = clean_str($_POST['proc_regional'] ?? '', 100);
                $procSistema = clean_str($_POST['proc_sistema'] ?? '', 30);
                $procSegredo = (int)($_POST['proc_segredo'] ?? 0);

                $pdo->prepare(
                    'UPDATE cases SET status=?, case_number=?, court=?, case_type=COALESCE(NULLIF(?,\'\'),case_type),
                     distribution_date=?, category=?, comarca=?, comarca_uf=?, regional=?,
                     sistema_tribunal=?, segredo_justica=?, opened_at=COALESCE(opened_at, CURDATE()),
                     updated_at=NOW() WHERE id=?'
                )->execute(array(
                    $status, $procNumero ?: null, $procVara ?: null, $procTipo,
                    $procData, $procCategory, $procComarca ?: null, $procComarcaUf ?: null,
                    $procRegional ?: null, $procSistema ?: null, $procSegredo, $caseId
                ));

                // Gerar checklist de tarefas (se ainda não existir)
                $stmtCk = $pdo->prepare("SELECT COUNT(*) FROM case_tasks WHERE case_id = ?");
                $stmtCk->execute(array($caseId));
                $existingTasks = (int)$stmtCk->fetchColumn();
                if ($existingTasks === 0) {
                    $caseType = $procTipo ?: ($currentCase['case_type'] ?? '');
                    if ($caseType) {
                        generate_case_checklist($caseId, $caseType);
                    }
                }

                audit_log($procCategory === 'extrajudicial' ? 'extrajudicial' : 'processo_distribuido', 'case', $caseId, ($procCategory === 'extrajudicial' ? 'Extrajudicial: ' : 'Processo: ') . ($procNumero ?: $procVara));
                notify_gestao('Processo distribuído!', ($currentCase ? $currentCase['title'] : 'Caso') . ' — ' . $procNumero . ' (' . $procVara . ')', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $caseId), '🏛️');

                // BLOCO 4: Notificar cliente — Processo distribuído (só se tiver nº)
                if ($procNumero && $currentCase) {
                    notificar_cliente('processo_distribuido', (int)$currentCase['client_id'], array(
                        '[numero_processo]' => $procNumero,
                        '[vara_juizo]' => $procVara ?: 'A definir',
                        '[tipo_acao]' => $procTipo ?: ($currentCase['case_type'] ?: ''),
                    ), $caseId);
                }

                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
                flash_set('success', 'Processo distribuído! Dados salvos.');
                redirect(module_url('operacional'));
                exit;
            }

            // ── SUSPENSO: só Admin + bilateral com memória de estado ──
            if ($status === 'suspenso') {
                if (!has_role('admin')) {
                    $msg = 'Apenas administradores podem suspender.';
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => $msg)); exit; }
                    flash_set('error', $msg);
                    redirect(module_url('operacional'));
                    exit;
                }
                $prazoSusp = isset($_POST['prazo_suspensao']) ? $_POST['prazo_suspensao'] : null;
                if ($prazoSusp === '') $prazoSusp = null;

                // Salvar status anterior + data da suspensão
                $pdo->prepare('UPDATE cases SET status=?, coluna_antes_suspensao=?, data_suspensao=NOW(), prazo_suspensao=?, updated_at=NOW() WHERE id=?')
                    ->execute(array('suspenso', $oldStatus, $prazoSusp, $caseId));

                // Espelhar no Pipeline
                $leadRow = buscarLeadVinculado($pdo, $caseId, $clientId);
                if ($leadRow && !in_array($leadRow['stage'], array('cancelado', 'finalizado', 'perdido', 'suspenso'))) {
                    $pdo->prepare("UPDATE pipeline_leads SET stage='suspenso', coluna_antes_suspensao=?, data_suspensao=NOW(), prazo_suspensao=?, updated_at=NOW() WHERE id=?")
                        ->execute(array($leadRow['stage'], $prazoSusp, $leadRow['id']));
                    $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                        ->execute(array($leadRow['id'], $leadRow['stage'], 'suspenso', current_user_id(), 'Auto: caso suspenso no Operacional'));
                    audit_log('lead_auto_suspended', 'lead', $leadRow['id'], 'Operacional suspendeu caso #' . $caseId);
                }

                $caseTitle = $currentCase ? $currentCase['title'] : 'Caso #' . $caseId;
                notify_gestao('Caso suspenso', $caseTitle . ' foi suspenso.' . ($leadRow ? ' Lead também suspenso.' : '') . ($prazoSusp ? ' Prazo: ' . $prazoSusp : ''), 'alerta', url('modules/operacional/'), '⏸️');

                audit_log('case_suspended', 'case', $caseId, 'Anterior: ' . $oldStatus . ($prazoSusp ? ' Prazo: ' . $prazoSusp : ''));
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
                flash_set('success', 'Caso suspenso.');
                redirect(module_url('operacional'));
                exit;
            }

            // ── REATIVAR DO SUSPENSO: restaurar estado anterior ──
            if ($oldStatus === 'suspenso' && $status !== 'suspenso') {
                // Restaurar lead no Pipeline
                $leadRow = buscarLeadVinculado($pdo, $caseId, $clientId);
                if ($leadRow && $leadRow['stage'] === 'suspenso') {
                    $stageAnterior = $leadRow['coluna_antes_suspensao'] ? $leadRow['coluna_antes_suspensao'] : 'elaboracao_docs';
                    $pdo->prepare("UPDATE pipeline_leads SET stage=?, coluna_antes_suspensao=NULL, data_suspensao=NULL, prazo_suspensao=NULL, updated_at=NOW() WHERE id=?")
                        ->execute(array($stageAnterior, $leadRow['id']));
                    $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                        ->execute(array($leadRow['id'], 'suspenso', $stageAnterior, current_user_id(), 'Auto: caso reativado no Operacional'));
                    audit_log('lead_auto_reactivated', 'lead', $leadRow['id'], 'Operacional reativou caso #' . $caseId);
                }
                // Limpar dados de suspensão do caso
                $pdo->prepare('UPDATE cases SET coluna_antes_suspensao=NULL, data_suspensao=NULL, prazo_suspensao=NULL WHERE id=?')
                    ->execute(array($caseId));
                notify_gestao('Caso reativado!', ($currentCase ? $currentCase['title'] : 'Caso') . ' saiu do Suspenso.', 'sucesso', url('modules/operacional/'), '▶️');
            }

            // ── CANCELADO: só Admin + espelhar no Pipeline ──
            if ($status === 'cancelado') {
                if (!has_role('admin')) {
                    $msg = 'Apenas administradores podem cancelar.';
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => $msg)); exit; }
                    flash_set('error', $msg);
                    redirect(module_url('operacional'));
                    exit;
                }
                // Cancelar lead vinculado no Pipeline
                $leadRow = buscarLeadVinculado($pdo, $caseId, $clientId);
                if ($leadRow && !in_array($leadRow['stage'], array('cancelado', 'finalizado'))) {
                    $pdo->prepare("UPDATE pipeline_leads SET stage = 'cancelado', updated_at = NOW() WHERE id = ?")
                        ->execute(array($leadRow['id']));
                    $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                        ->execute(array($leadRow['id'], $leadRow['stage'], 'cancelado', current_user_id(), 'Auto: caso cancelado no Operacional'));
                    audit_log('lead_auto_cancelled', 'lead', $leadRow['id'], 'Operacional cancelou caso #' . $caseId);
                }
                $caseTitle = $currentCase ? $currentCase['title'] : 'Caso #' . $caseId;
                notify_gestao('Caso cancelado', $caseTitle . ' foi cancelado no Operacional.' . ($leadRow ? ' Lead também cancelado no Pipeline.' : ''), 'alerta', url('modules/operacional/'), '❌');
            }

            // ── PARCERIA: salvar parceiro_id ──
            if ($status === 'parceria_previdenciario' && isset($_POST['parceiro_id'])) {
                $parceiroId = (int)$_POST['parceiro_id'];
                if ($parceiroId) {
                    $pdo->prepare("UPDATE cases SET parceiro_id = ? WHERE id = ?")->execute(array($parceiroId, $caseId));
                    audit_log('parceiro_vinculado', 'case', $caseId, 'Parceiro #' . $parceiroId);
                }
            }

            // ── MOVIMENTAÇÕES NORMAIS ──
            $closedAt = in_array($status, array('concluido','arquivado','cancelado')) ? date('Y-m-d') : null;
            $pdo->prepare('UPDATE cases SET status=?, closed_at=?, updated_at=NOW() WHERE id=?')
                ->execute(array($status, $closedAt, $caseId));
            audit_log('case_status', 'case', $caseId, $status);

            // ── EM EXECUÇÃO: auto-finalizar Pipeline se Pasta Apta ──
            if ($status === 'em_andamento') {
                $leadRow = buscarLeadVinculado($pdo, $caseId, $clientId);
                if ($leadRow && $leadRow['stage'] === 'pasta_apta') {
                    $pdo->prepare("UPDATE pipeline_leads SET stage = 'finalizado', updated_at = NOW() WHERE id = ?")
                        ->execute(array($leadRow['id']));
                    $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                        ->execute(array($leadRow['id'], 'pasta_apta', 'finalizado', current_user_id(), 'Auto: caso em execução no Operacional'));
                }
            }

            // ── RETORNO DE DOC FALTANTE (Operacional move de volta) ──
            if ($oldStatus === 'doc_faltante' && $status !== 'doc_faltante') {
                // Limpar estado
                $pdo->prepare("UPDATE cases SET stage_antes_doc_faltante = NULL WHERE id = ?")->execute(array($caseId));

                // Marcar docs como recebidos
                $pdo->prepare("UPDATE documentos_pendentes SET status = 'recebido', recebido_em = NOW(), recebido_por = ? WHERE case_id = ? AND status = 'pendente'")
                    ->execute(array(current_user_id(), $caseId));

                // Retornar lead do Pipeline (se estava em doc_faltante)
                $leadRow = buscarLeadVinculado($pdo, $caseId, $clientId);
                if ($leadRow && $leadRow['stage'] === 'doc_faltante') {
                    $pdo->prepare("UPDATE pipeline_leads SET stage='reuniao_cobranca', doc_faltante_motivo=NULL, stage_antes_doc_faltante=NULL, updated_at=NOW() WHERE id=?")
                        ->execute(array($leadRow['id']));
                }
            }

            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
            flash_set('success', 'Status atualizado.');
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : module_url('operacional');
        if (strpos($referer, 'caso_ver') !== false) {
            redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        } else {
            redirect(module_url('operacional'));
        }
        break;

    case 'resolve_doc':
        $docId = (int)($_POST['doc_id'] ?? 0);
        if (!$docId) {
            error_log('[resolve_doc] ERRO: doc_id vazio');
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'doc_id obrigatório')); exit; }
            break;
        }

        // ═══ PASSO 1: Resolver IDs a partir do DOCUMENTO (fonte da verdade) ═══
        $dRow = $pdo->prepare("SELECT id, case_id, client_id, lead_id, status, descricao FROM documentos_pendentes WHERE id = ?");
        $dRow->execute(array($docId));
        $doc = $dRow->fetch();

        if (!$doc) {
            error_log('[resolve_doc] ERRO: doc_id=' . $docId . ' não encontrado');
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Documento não encontrado')); exit; }
            break;
        }
        if ($doc['status'] !== 'pendente') {
            error_log('[resolve_doc] SKIP: doc_id=' . $docId . ' já está ' . $doc['status']);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true, 'message' => 'Já recebido')); exit; }
            break;
        }

        $caseId = (int)$doc['case_id'];
        $clientId = (int)$doc['client_id'];
        $docLeadId = (int)$doc['lead_id'];

        // Fallbacks mínimos (sem client_id guessing)
        if (!$caseId) $caseId = (int)($_POST['case_id'] ?? 0);
        if ($caseId && !$clientId) {
            $cRow = $pdo->prepare("SELECT client_id FROM cases WHERE id = ?");
            $cRow->execute(array($caseId));
            $cr = $cRow->fetch();
            if ($cr) $clientId = (int)$cr['client_id'];
        }

        error_log('[resolve_doc] INICIO: doc_id=' . $docId . ' case_id=' . $caseId . ' client_id=' . $clientId . ' lead_id=' . $docLeadId . ' desc=' . ($doc['descricao'] ?? ''));

        // ═══ PASSO 2: Marcar documento como recebido ═══
        $pdo->prepare("UPDATE documentos_pendentes SET status = 'recebido', recebido_em = NOW(), recebido_por = ? WHERE id = ?")
            ->execute(array(current_user_id(), $docId));

        // ═══ PASSO 3: Contar pendentes APENAS pelo case_id (sem OR client_id) ═══
        $numPending = 0;
        if ($caseId) {
            $stillPending = $pdo->prepare("SELECT COUNT(*) FROM documentos_pendentes WHERE case_id = ? AND status = 'pendente'");
            $stillPending->execute(array($caseId));
            $numPending = (int)$stillPending->fetchColumn();
        }

        error_log('[resolve_doc] PENDENTES: case_id=' . $caseId . ' numPending=' . $numPending);

        // ═══ PASSO 4: Se 0 pendentes E caso está em doc_faltante → restaurar bilateral ═══
        $restoredTo = null;
        if ($numPending === 0 && $caseId) {
            $caseStmt = $pdo->prepare("SELECT status, stage_antes_doc_faltante FROM cases WHERE id = ?");
            $caseStmt->execute(array($caseId));
            $caseRow = $caseStmt->fetch();

            error_log('[resolve_doc] CASO: id=' . $caseId . ' status=' . ($caseRow ? $caseRow['status'] : 'NULL') . ' stage_antes=' . ($caseRow ? ($caseRow['stage_antes_doc_faltante'] ?: 'NULL') : 'NULL'));

            if ($caseRow && $caseRow['status'] === 'doc_faltante') {
                $anteriorStatus = $caseRow['stage_antes_doc_faltante'] ? $caseRow['stage_antes_doc_faltante'] : 'em_elaboracao';
                $restoredTo = $anteriorStatus;

                // 4A: Restaurar caso no Operacional
                $pdo->prepare("UPDATE cases SET status = ?, stage_antes_doc_faltante = NULL, updated_at = NOW() WHERE id = ?")
                    ->execute(array($anteriorStatus, $caseId));
                error_log('[resolve_doc] CASO RESTAURADO: case_id=' . $caseId . ' → ' . $anteriorStatus);
                audit_log('case_doc_restored', 'case', $caseId, 'Voltou para ' . $anteriorStatus);

                // 4B: Restaurar lead no Pipeline
                $leadRow = buscarLeadVinculado($pdo, $caseId, $clientId);
                error_log('[resolve_doc] LEAD VINCULADO: ' . ($leadRow ? 'id=' . $leadRow['id'] . ' stage=' . $leadRow['stage'] : 'NÃO ENCONTRADO'));

                if ($leadRow && $leadRow['stage'] === 'doc_faltante') {
                    $novoStageLead = ($anteriorStatus === 'em_andamento') ? 'finalizado' : 'pasta_apta';
                    $pdo->prepare("UPDATE pipeline_leads SET stage=?, doc_faltante_motivo=NULL, stage_antes_doc_faltante=NULL, updated_at=NOW() WHERE id=?")
                        ->execute(array($novoStageLead, $leadRow['id']));
                    $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                        ->execute(array($leadRow['id'], 'doc_faltante', $novoStageLead, current_user_id(), 'Auto: docs recebidos, caso voltou para ' . $anteriorStatus));
                    error_log('[resolve_doc] LEAD RESTAURADO: lead_id=' . $leadRow['id'] . ' → ' . $novoStageLead);
                }

                notify_gestao('Docs completos!', 'Caso #' . $caseId . ' — docs recebidos. Voltou para: ' . $anteriorStatus, 'sucesso', url('modules/operacional/caso_ver.php?id=' . $caseId), '');
                if ($clientId) {
                    notificar_cliente('docs_recebidos', $clientId, array(), $caseId, $docLeadId);
                }
            } else {
                error_log('[resolve_doc] CASO NÃO RESTAURADO: status não é doc_faltante (é ' . ($caseRow ? $caseRow['status'] : 'NULL') . ')');
            }
        }

        error_log('[resolve_doc] FIM: doc_id=' . $docId . ' restored_to=' . ($restoredTo ?: 'nenhum'));

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(array('ok' => true, 'pending' => $numPending, 'case_id' => $caseId, 'restored_to' => $restoredTo));
            exit;
        }
        flash_set('success', $numPending > 0 ? 'Documento recebido. Ainda ' . $numPending . ' pendente(s).' : 'Todos os documentos recebidos!');

        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'update_case_info':
        if (!has_min_role('gestao')) { break; }
        $caseId = (int)($_POST['case_id'] ?? 0);
        $priority = $_POST['priority'] ?? 'normal';
        $responsibleId = (int)($_POST['responsible_user_id'] ?? 0) ?: null;
        $validPriorities = array('urgente','alta','normal','baixa');
        if ($caseId && in_array($priority, $validPriorities)) {
            $pdo->prepare('UPDATE cases SET priority=?, responsible_user_id=?, updated_at=NOW() WHERE id=?')
                ->execute(array($priority, $responsibleId, $caseId));
            flash_set('success', 'Caso atualizado.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'add_task':
        $caseId = (int)($_POST['case_id'] ?? 0);
        $title = clean_str($_POST['title'] ?? '', 200);
        $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $dueDate = $_POST['due_date'] ?? null;
        if ($dueDate === '') $dueDate = null;
        if ($caseId && $title) {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM case_tasks WHERE case_id = ?');
            $stmt->execute(array($caseId));
            $nextOrder = (int)$stmt->fetchColumn();
            $pdo->prepare('INSERT INTO case_tasks (case_id, title, assigned_to, due_date, sort_order) VALUES (?,?,?,?,?)')
                ->execute(array($caseId, $title, $assignedTo, $dueDate, $nextOrder));
            flash_set('success', 'Tarefa adicionada.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'toggle_task':
        $taskId = (int)($_POST['task_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($taskId) {
            $stmt = $pdo->prepare('SELECT status FROM case_tasks WHERE id = ?');
            $stmt->execute(array($taskId));
            $task = $stmt->fetch();
            if ($task) {
                $newStatus = ($task['status'] === 'concluido' || $task['status'] === 'feito') ? 'a_fazer' : 'concluido';
                $completedAt = $newStatus === 'concluido' ? date('Y-m-d H:i:s') : null;
                $pdo->prepare('UPDATE case_tasks SET status=?, completed_at=? WHERE id=?')
                    ->execute(array($newStatus, $completedAt, $taskId));
            }
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'delete_task':
        $taskId = (int)($_POST['task_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($taskId) {
            $pdo->prepare('DELETE FROM case_tasks WHERE id = ?')->execute(array($taskId));
            flash_set('success', 'Tarefa removida.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'delete_case':
        if (!has_min_role('gestao')) { flash_set('error', 'Sem permissão.'); redirect(module_url('operacional')); }
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($caseId) {
            // Desvincular lead do pipeline
            $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = NULL WHERE linked_case_id = ?")->execute(array($caseId));
            // Remover docs pendentes
            $pdo->prepare("DELETE FROM documentos_pendentes WHERE case_id = ?")->execute(array($caseId));
            // Remover tarefas
            $pdo->prepare("DELETE FROM case_tasks WHERE case_id = ?")->execute(array($caseId));
            // Remover caso
            $pdo->prepare("DELETE FROM cases WHERE id = ?")->execute(array($caseId));
            audit_log('case_deleted', 'case', $caseId);
            flash_set('success', 'Caso excluído.');
        }
        redirect(module_url('operacional'));
        break;

    case 'update_title':
        if (!has_min_role('gestao')) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Apenas gestão/admin pode renomear')); exit; }
            flash_set('error', 'Sem permissão.'); redirect(module_url('operacional')); break;
        }
        $caseId = (int)($_POST['case_id'] ?? 0);
        $newTitle = trim($_POST['title'] ?? '');
        if (!$newTitle || mb_strlen($newTitle) < 5) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Nome deve ter no mínimo 5 caracteres')); exit; }
            flash_set('error', 'Nome deve ter no mínimo 5 caracteres.'); redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId)); break;
        }
        if ($caseId) {
            // Buscar nome antigo e client_id
            $old = $pdo->prepare("SELECT title, client_id, drive_folder_url FROM cases WHERE id = ?");
            $old->execute(array($caseId));
            $oldData = $old->fetch();
            $oldTitle = $oldData ? $oldData['title'] : '';
            $clientId = $oldData ? (int)$oldData['client_id'] : 0;

            // 1. Atualizar cases.title
            $pdo->prepare("UPDATE cases SET title = ?, updated_at = NOW() WHERE id = ?")->execute(array($newTitle, $caseId));

            // 2. Atualizar pipeline_leads.nome_pasta
            try {
                $pdo->prepare("UPDATE pipeline_leads SET nome_pasta = ?, updated_at = NOW() WHERE linked_case_id = ?")
                    ->execute(array($newTitle, $caseId));
                if ($clientId) {
                    $pdo->prepare("UPDATE pipeline_leads SET nome_pasta = ?, updated_at = NOW() WHERE client_id = ? AND linked_case_id IS NULL AND nome_pasta = ?")
                        ->execute(array($newTitle, $clientId, $oldTitle));
                }
            } catch (Exception $e) {}

            // 3. Renomear pasta no Google Drive via webhook
            try {
                $webhookUrl = '';
                $wh = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'google_drive_webhook'");
                $wh->execute();
                $whRow = $wh->fetch();
                if ($whRow) $webhookUrl = $whRow['valor'];

                if ($webhookUrl && $oldTitle !== $newTitle) {
                    $ch = curl_init($webhookUrl);
                    curl_setopt_array($ch, array(
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode(array('action' => 'rename', 'old_name' => $oldTitle, 'new_name' => $newTitle)),
                        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_FOLLOWLOCATION => true,
                    ));
                    curl_exec($ch);
                    curl_close($ch);
                }
            } catch (Exception $e) {}

            // 4. Audit log
            audit_log('CASE_TITLE_CHANGED', 'case', $caseId, $oldTitle . ' → ' . $newTitle);
        }
        $newCsrfTitle = generate_csrf_token();
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true, 'title' => $newTitle, 'csrf' => $newCsrfTitle)); exit; }
        flash_set('success', 'Nome da pasta atualizado.');
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'add_andamento':
        $caseId = (int)($_POST['case_id'] ?? 0);
        $dataAnd = $_POST['data_andamento'] ?? date('Y-m-d');
        $tipoAnd = $_POST['tipo'] ?? 'movimentacao';
        $descAnd = trim($_POST['descricao'] ?? '');
        if ($caseId && $descAnd) {
            try {
                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, created_at) VALUES (?,?,?,?,?,NOW())"
                )->execute(array($caseId, $dataAnd, $tipoAnd, $descAnd, current_user_id()));
                audit_log('ANDAMENTO_CRIADO', 'case', $caseId, $tipoAnd . ': ' . mb_substr($descAnd, 0, 80, 'UTF-8'));
                flash_set('success', 'Andamento registrado.');
            } catch (Exception $e) {
                flash_set('error', 'Erro ao salvar andamento: ' . $e->getMessage());
            }
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'delete_andamento':
        $andId = (int)($_POST['andamento_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($andId) {
            $canDelete = has_min_role('gestao');
            if (!$canDelete) {
                $chk = $pdo->prepare("SELECT created_by FROM case_andamentos WHERE id = ?");
                $chk->execute(array($andId));
                $row = $chk->fetch();
                $canDelete = $row && (int)$row['created_by'] === current_user_id();
            }
            if ($canDelete) {
                $pdo->prepare("DELETE FROM case_andamentos WHERE id = ?")->execute(array($andId));
                audit_log('ANDAMENTO_EXCLUIDO', 'case', $caseId, 'ID: ' . $andId);
                flash_set('success', 'Andamento excluído.');
            } else {
                flash_set('error', 'Sem permissão para excluir.');
            }
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'log_whatsapp_andamento':
        $andId = (int)($_POST['andamento_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($andId && $caseId) {
            try {
                $pdo->prepare(
                    "UPDATE case_andamentos SET whatsapp_enviado_em = NOW(), whatsapp_enviado_por = ? WHERE id = ? AND case_id = ?"
                )->execute(array(current_user_id(), $andId, $caseId));
            } catch (Exception $e) {
                // Colunas podem não existir ainda — ignorar
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(array('ok' => true));
            exit;
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'toggle_visibilidade':
        $andId = (int)($_POST['andamento_id'] ?? 0);
        $visivel = (int)($_POST['visivel'] ?? 1);
        if ($andId) {
            try {
                $pdo->prepare("UPDATE case_andamentos SET visivel_cliente = ? WHERE id = ?")
                    ->execute(array($visivel, $andId));
                audit_log('ANDAMENTO_VISIBILIDADE', 'andamento', $andId, $visivel ? 'visivel' : 'interno');
            } catch (Exception $e) {}
        }
        $newCsrf = generate_csrf_token();
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true, 'csrf' => $newCsrf)); exit; }
        redirect(module_url('operacional'));
        break;

    case 'edit_andamento':
        $andId = (int)($_POST['andamento_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);
        $descricao = trim($_POST['descricao'] ?? '');
        if (!$andId || !$descricao) {
            $newCsrf = generate_csrf_token();
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Dados incompletos', 'csrf' => $newCsrf)); exit; }
            break;
        }
        $canEdit = has_min_role('gestao');
        if (!$canEdit) {
            $chk = $pdo->prepare("SELECT created_by FROM case_andamentos WHERE id = ?");
            $chk->execute(array($andId));
            $row = $chk->fetch();
            $canEdit = $row && (int)$row['created_by'] === current_user_id();
        }
        if (!$canEdit) {
            $newCsrf = generate_csrf_token();
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Sem permissão', 'csrf' => $newCsrf)); exit; }
            break;
        }
        $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?")->execute(array($descricao, $andId));
        audit_log('ANDAMENTO_EDITADO', 'andamento', $andId, mb_substr($descricao, 0, 80, 'UTF-8'));
        $newCsrf = generate_csrf_token();
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true, 'csrf' => $newCsrf)); exit; }
        flash_set('success', 'Andamento atualizado.');
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'vincular_incidental':
        $principalId = (int)($_POST['principal_id'] ?? 0);
        $incidentalId = (int)($_POST['incidental_id'] ?? 0);
        $tipoRelacao = clean_str($_POST['tipo_relacao'] ?? '', 50);

        if ($principalId && $incidentalId && $principalId !== $incidentalId) {
            $pdo->prepare("UPDATE cases SET processo_principal_id = ?, tipo_relacao = ?, is_incidental = 1, updated_at = NOW() WHERE id = ?")
                ->execute(array($principalId, $tipoRelacao ?: null, $incidentalId));
            audit_log('incidental_vinculado', 'case', $incidentalId, "Principal: #$principalId, Tipo: $tipoRelacao");
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
            flash_set('success', 'Processo incidental vinculado.');
        } else {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Dados inválidos')); exit; }
            flash_set('error', 'Dados inválidos.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $principalId));
        break;

    case 'desvincular_incidental':
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($caseId) {
            $pdo->prepare("UPDATE cases SET processo_principal_id = NULL, tipo_relacao = NULL, is_incidental = 0, updated_at = NOW() WHERE id = ?")
                ->execute(array($caseId));
            audit_log('incidental_desvinculado', 'case', $caseId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
            flash_set('success', 'Processo desvinculado.');
        }
        redirect(module_url('operacional', 'caso_ver.php?id=' . $caseId));
        break;

    case 'buscar_casos_cliente':
        // AJAX: buscar casos do mesmo cliente para vincular
        $clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
        $excludeId = (int)($_GET['exclude_id'] ?? $_POST['exclude_id'] ?? 0);
        header('Content-Type: application/json');
        if ($clientId) {
            $stmt = $pdo->prepare("SELECT id, title, case_number, case_type, status FROM cases WHERE client_id = ? AND id != ? AND is_incidental = 0 ORDER BY created_at DESC LIMIT 20");
            $stmt->execute(array($clientId, $excludeId));
            echo json_encode($stmt->fetchAll());
        } else {
            echo json_encode(array());
        }
        exit;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('operacional'));
}
