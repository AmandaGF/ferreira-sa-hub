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

switch ($action) {
    case 'update_status':
        if (!has_min_role('gestao') && !has_role('colaborador')) { break; }
        $caseId = (int)($_POST['case_id'] ?? 0);
        $status = isset($_POST['new_status']) && $_POST['new_status'] ? $_POST['new_status'] : (isset($_POST['status']) ? $_POST['status'] : '');
        $validStatuses = array('aguardando_docs','em_elaboracao','em_andamento','doc_faltante','aguardando_prazo','distribuido','parceria_previdenciario','cancelado','suspenso','concluido','arquivado');

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
                $linkedLead = $pdo->prepare("SELECT id FROM pipeline_leads WHERE linked_case_id = ?");
                $linkedLead->execute(array($caseId));
                $leadRow = $linkedLead->fetch();
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

                $pdo->prepare('UPDATE cases SET status=?, case_number=?, court=?, case_type=COALESCE(NULLIF(?,\'\'),case_type), distribution_date=?, category=?, updated_at=NOW() WHERE id=?')
                    ->execute(array($status, $procNumero ?: null, $procVara ?: null, $procTipo, $procData, $procCategory, $caseId));

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
                $linkedLead = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ?");
                $linkedLead->execute(array($caseId));
                $leadRow = $linkedLead->fetch();
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
                $linkedLead = $pdo->prepare("SELECT id, stage, coluna_antes_suspensao FROM pipeline_leads WHERE linked_case_id = ?");
                $linkedLead->execute(array($caseId));
                $leadRow = $linkedLead->fetch();
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
                $linkedLead = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ?");
                $linkedLead->execute(array($caseId));
                $leadRow = $linkedLead->fetch();
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
                $linkedLead = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ?");
                $linkedLead->execute(array($caseId));
                $leadRow = $linkedLead->fetch();
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
                $linkedLead = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ?");
                $linkedLead->execute(array($caseId));
                $leadRow = $linkedLead->fetch();
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
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($docId) {
            // Marcar documento como recebido
            $pdo->prepare("UPDATE documentos_pendentes SET status = 'recebido', recebido_em = NOW(), recebido_por = ? WHERE id = ?")
                ->execute(array(current_user_id(), $docId));

            // Verificar se ainda tem docs pendentes neste caso
            $stillPending = $pdo->prepare("SELECT COUNT(*) FROM documentos_pendentes WHERE case_id = ? AND status = 'pendente'");
            $stillPending->execute(array($caseId));
            $numPending = (int)$stillPending->fetchColumn();

            if ($numPending === 0) {
                // Todos os docs recebidos — verificar status anterior
                $caseStmt = $pdo->prepare("SELECT status, stage_antes_doc_faltante FROM cases WHERE id = ?");
                $caseStmt->execute(array($caseId));
                $caseRow = $caseStmt->fetch();
                $anteriorStatus = ($caseRow && $caseRow['stage_antes_doc_faltante']) ? $caseRow['stage_antes_doc_faltante'] : '';

                // Se estava em execução antes → volta para execução E finaliza Pipeline
                if ($anteriorStatus === 'em_andamento') {
                    $pdo->prepare("UPDATE cases SET status = 'em_andamento', stage_antes_doc_faltante = NULL, updated_at = NOW() WHERE id = ?")
                        ->execute(array($caseId));

                    // Finalizar lead no Pipeline (sai do comercial)
                    $linkedLead = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ?");
                    $linkedLead->execute(array($caseId));
                    $leadRow = $linkedLead->fetch();
                    if ($leadRow) {
                        $pdo->prepare("UPDATE pipeline_leads SET stage='finalizado', doc_faltante_motivo=NULL, stage_antes_doc_faltante=NULL, updated_at=NOW() WHERE id=?")
                            ->execute(array($leadRow['id']));
                        $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                            ->execute(array($leadRow['id'], $leadRow['stage'], 'finalizado', current_user_id(), 'Auto: docs recebidos + caso já em execução'));
                    }

                    notify_gestao('Docs completos!', 'Caso #' . $caseId . ' voltou para execução. Card saiu do Pipeline.', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $caseId), '⚙️');
                    flash_set('success', 'Todos os documentos recebidos! Caso voltou para execução.');
                } else {
                    // Não estava em execução → vai para Pasta Apta
                    $pdo->prepare("UPDATE cases SET status = 'em_elaboracao', stage_antes_doc_faltante = NULL, updated_at = NOW() WHERE id = ?")
                        ->execute(array($caseId));

                    $linkedLead = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ?");
                    $linkedLead->execute(array($caseId));
                    $leadRow = $linkedLead->fetch();
                    if ($leadRow && in_array($leadRow['stage'], array('doc_faltante', 'reuniao_cobranca', 'agendado_docs'))) {
                        $pdo->prepare("UPDATE pipeline_leads SET stage='pasta_apta', doc_faltante_motivo=NULL, stage_antes_doc_faltante=NULL, updated_at=NOW() WHERE id=?")
                            ->execute(array($leadRow['id']));
                        $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                            ->execute(array($leadRow['id'], $leadRow['stage'], 'pasta_apta', current_user_id(), 'Auto: todos os documentos recebidos'));
                    }

                    notify_gestao('Pasta apta!', 'Todos os documentos recebidos — caso #' . $caseId . ' está com pasta apta.', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $caseId), '✔️');
                    flash_set('success', 'Todos os documentos recebidos! Pasta apta.');
                }
            } else {
                flash_set('success', 'Documento marcado como recebido. Ainda ' . $numPending . ' pendente(s).');
            }

            audit_log('doc_received', 'documentos_pendentes', $docId);
        }
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
                $newStatus = $task['status'] === 'pendente' ? 'feito' : 'pendente';
                $completedAt = $newStatus === 'feito' ? date('Y-m-d H:i:s') : null;
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
        if (!has_min_role('gestao')) { flash_set('error', 'Sem permissão.'); break; }
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
        $caseId = (int)($_POST['case_id'] ?? 0);
        $newTitle = trim($_POST['title'] ?? '');
        if ($caseId && $newTitle) {
            $pdo->prepare("UPDATE cases SET title = ?, updated_at = NOW() WHERE id = ?")->execute(array($newTitle, $caseId));
            audit_log('CASE_TITLE_CHANGED', 'case', $caseId, $newTitle);
            flash_set('success', 'Nome da pasta atualizado.');
        }
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

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('operacional'));
}
