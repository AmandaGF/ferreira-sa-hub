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
        $validStatuses = array('aguardando_docs','em_elaboracao','em_andamento','doc_faltante','aguardando_prazo','distribuido','concluido','arquivado');

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

                audit_log('doc_faltante', 'case', $caseId, $docDesc);

                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
                flash_set('success', 'Documento faltante sinalizado. CX notificado.');
                redirect(module_url('operacional'));
                exit;
            }

            // ── PROCESSO DISTRIBUÍDO: salvar dados do modal ──
            if ($status === 'distribuido') {
                $procNumero = clean_str($_POST['proc_numero'] ?? '', 30);
                $procVara = clean_str($_POST['proc_vara'] ?? '', 150);
                $procTipo = clean_str($_POST['proc_tipo'] ?? '', 60);
                $procData = $_POST['proc_data'] ?? null;
                if ($procData === '') $procData = null;

                $pdo->prepare('UPDATE cases SET status=?, case_number=?, court=?, case_type=COALESCE(NULLIF(?,\'\'),case_type), distribution_date=?, updated_at=NOW() WHERE id=?')
                    ->execute(array($status, $procNumero ?: null, $procVara ?: null, $procTipo, $procData, $caseId));

                audit_log('processo_distribuido', 'case', $caseId, 'Processo: ' . $procNumero . ' - ' . $procVara);
                notify_gestao('Processo distribuído!', ($currentCase ? $currentCase['title'] : 'Caso') . ' — ' . $procNumero . ' (' . $procVara . ')', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $caseId), '🏛️');

                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
                flash_set('success', 'Processo distribuído! Dados salvos.');
                redirect(module_url('operacional'));
                exit;
            }

            // ── MOVIMENTAÇÕES NORMAIS ──
            $closedAt = in_array($status, array('concluido','arquivado')) ? date('Y-m-d') : null;
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
                // Todos os docs recebidos → Operacional vai para "Pasta Apta" + Pipeline vai para "Pasta Apta"
                $pdo->prepare("UPDATE cases SET status = 'em_elaboracao', stage_antes_doc_faltante = NULL, updated_at = NOW() WHERE id = ?")
                    ->execute(array($caseId));

                // Pipeline: mover lead para pasta_apta
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

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('operacional'));
}
