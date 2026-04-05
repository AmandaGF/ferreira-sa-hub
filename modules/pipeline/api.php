<?php
/**
 * Ferreira & Sá Hub — API do Pipeline Comercial/CX
 * Gatilhos automáticos conforme doc técnico Kanban v2
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/google_drive.php';
require_login();
if (!can_view_pipeline()) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('pipeline')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('pipeline')); }

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'move':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $toStage = $_POST['to_stage'] ?? '';
        $notes = clean_str($_POST['notes'] ?? '', 500);
        $folderName = isset($_POST['folder_name']) ? clean_str($_POST['folder_name'], 200) : '';

        $validStages = array('cadastro_preenchido','elaboracao_docs','link_enviados','contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','cancelado','suspenso','finalizado','perdido');
        if (!$leadId || !in_array($toStage, $validStages)) {
            flash_set('error', 'Dados inválidos.');
            redirect(module_url('pipeline'));
        }

        $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
        $stmt->execute(array($leadId));
        $lead = $stmt->fetch();
        if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

        $fromStage = $lead['stage'];

        // Atualizar estágio
        $pdo->prepare('UPDATE pipeline_leads SET stage=?, updated_at=NOW() WHERE id=?')
            ->execute(array($toStage, $leadId));

        // Se perdido, salvar motivo
        if ($toStage === 'perdido' && $notes) {
            $pdo->prepare('UPDATE pipeline_leads SET lost_reason=? WHERE id=?')
                ->execute(array($notes, $leadId));
        }

        // Registrar histórico
        $pdo->prepare('INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)')
            ->execute(array($leadId, $fromStage, $toStage, current_user_id(), $notes ?: null));

        audit_log('lead_moved', 'lead', $leadId, "$fromStage -> $toStage");

        // ═══════════════════════════════════════════════════
        // GATILHOS AUTOMÁTICOS
        // ═══════════════════════════════════════════════════

        // ── CONTRATO ASSINADO: criar pasta Drive + caso no Operacional ──
        if ($toStage === 'contrato_assinado') {
            $pdo->prepare('UPDATE pipeline_leads SET converted_at=NOW() WHERE id=? AND converted_at IS NULL')
                ->execute(array($leadId));

            // Criar/buscar cliente
            $clientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
            if (!$clientId) {
                $pdo->prepare(
                    'INSERT INTO clients (name, phone, email, source, notes, created_by) VALUES (?,?,?,?,?,?)'
                )->execute(array(
                    $lead['name'], $lead['phone'], $lead['email'],
                    $lead['source'], 'Convertido do Pipeline. Tipo: ' . ($lead['case_type'] ?: 'N/I'),
                    current_user_id()
                ));
                $clientId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE pipeline_leads SET client_id=? WHERE id=?')
                    ->execute(array($clientId, $leadId));
            }

            // Título do caso
            $caseTitle = $folderName ? $folderName : ($lead['name'] . ($lead['case_type'] ? ' x ' . $lead['case_type'] : ''));

            // Verificar se já tem caso vinculado
            $existingCase = isset($lead['linked_case_id']) && $lead['linked_case_id'] ? (int)$lead['linked_case_id'] : 0;

            if (!$existingCase) {
                // Criar caso no Operacional (status: aguardando_docs = contrato assinado aguardando documentação)
                $caseType = 'outro';
                if ($lead['case_type']) {
                    $typeMap = array('divórcio'=>'divorcio','divorcio'=>'divorcio','pensão'=>'pensao','pensao'=>'pensao','alimentos'=>'pensao','guarda'=>'guarda','convivência'=>'convivencia','convivencia'=>'convivencia','inventário'=>'inventario','inventario'=>'inventario');
                    $lowerType = mb_strtolower($lead['case_type']);
                    foreach ($typeMap as $key => $val) {
                        if (strpos($lowerType, $key) !== false) { $caseType = $val; break; }
                    }
                }

                $pdo->prepare(
                    "INSERT INTO cases (client_id, title, case_type, status, priority, responsible_user_id, opened_at, notes)
                     VALUES (?,?,?,'aguardando_docs','normal',?,CURDATE(),?)"
                )->execute(array(
                    $clientId, $caseTitle, $caseType, $lead['assigned_to'],
                    'Contrato assinado. Aguardando documentação. Origem: Pipeline.'
                ));
                $newCaseId = (int)$pdo->lastInsertId();
                generate_case_checklist($newCaseId, $caseType);

                $pdo->prepare('UPDATE pipeline_leads SET linked_case_id=? WHERE id=?')
                    ->execute(array($newCaseId, $leadId));

                // Criar pasta no Google Drive
                $driveFolderName = $folderName ? $folderName : ($lead['name'] . ($lead['case_type'] ? ' x ' . $lead['case_type'] : ''));
                $driveResult = create_drive_folder($driveFolderName, $caseType, $newCaseId, $caseTitle);

                audit_log('case_auto_created', 'case', $newCaseId, 'Pipeline contrato_assinado - lead: ' . $leadId);
                notify_gestao('Contrato assinado!', $lead['name'] . ' — Caso criado no Operacional.' . ($driveResult['success'] ? ' Pasta criada no Drive!' : ''), 'sucesso', url('modules/operacional/caso_ver.php?id=' . $newCaseId), '✅');

                // GAMIFICAÇÃO: contrato fechado
                $assignedTo = isset($lead['assigned_to']) ? (int)$lead['assigned_to'] : 0;
                if ($assignedTo > 0) {
                    gamificar($assignedTo, 'contrato_fechado', $leadId, 'pipeline_leads');
                    // Bônus alto valor (>R$2k)
                    $valorCents = isset($lead['estimated_value_cents']) ? (int)$lead['estimated_value_cents'] : 0;
                    if (!$valorCents) $valorCents = isset($lead['honorarios_cents']) ? (int)$lead['honorarios_cents'] : 0;
                    if ($valorCents > 200000) {
                        gamificar($assignedTo, 'contrato_bonus_alto', $leadId, 'pipeline_leads');
                    }
                }

                // BLOCO 4: Notificar cliente — Boas-vindas
                notificar_cliente('boas_vindas', $clientId, array('[tipo_acao]' => $lead['case_type'] ?: ''), $newCaseId, $leadId);
            }
        }

        // ── PASTA APTA: espelhar no Operacional ──
        if ($toStage === 'pasta_apta') {
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                // Atualizar caso no Operacional para "pasta_apta" (em_elaboracao)
                $pdo->prepare("UPDATE cases SET status = 'em_elaboracao', updated_at = NOW() WHERE id = ? AND status = 'aguardando_docs'")
                    ->execute(array($linkedCaseId));
                notify_gestao('Pasta apta!', $lead['name'] . ' está com pasta apta. Operacional pode executar.', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $linkedCaseId), '✔️');

                // BLOCO 4: Notificar cliente — Documentos recebidos
                $clientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
                if ($clientId) {
                    notificar_cliente('docs_recebidos', $clientId, array('[tipo_acao]' => $lead['case_type'] ?: ''), $linkedCaseId, $leadId);
                }
            }
        }

        // ── DOC FALTANTE RESOLVIDO (CX resolve): retornar ao Operacional ──
        if ($fromStage === 'doc_faltante' && $toStage !== 'doc_faltante') {
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                // Retornar caso para "em_andamento" (em execução)
                $pdo->prepare("UPDATE cases SET status = 'em_andamento', stage_antes_doc_faltante = NULL, updated_at = NOW() WHERE id = ? AND status = 'doc_faltante'")
                    ->execute(array($linkedCaseId));

                // Marcar documentos pendentes como recebidos
                $pdo->prepare("UPDATE documentos_pendentes SET status = 'recebido', recebido_em = NOW(), recebido_por = ? WHERE lead_id = ? AND status = 'pendente'")
                    ->execute(array(current_user_id(), $leadId));

                notify_gestao('Documento recebido!', $lead['name'] . ' — documento recebido, caso retornou para execução.', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $linkedCaseId), '📄');
            }
        }

        // ── CANCELADO: só Admin + espelhar no Operacional ──
        if ($toStage === 'cancelado') {
            if (!has_role('admin')) {
                flash_set('error', 'Apenas administradores podem cancelar.');
                redirect(module_url('pipeline'));
                exit;
            }
            // Cancelar caso vinculado no Operacional
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                $pdo->prepare("UPDATE cases SET status = 'cancelado', closed_at = CURDATE(), updated_at = NOW() WHERE id = ?")
                    ->execute(array($linkedCaseId));
                audit_log('case_auto_cancelled', 'case', $linkedCaseId, 'Pipeline cancelou lead #' . $leadId);
            }
            notify_gestao('Lead cancelado', $lead['name'] . ' foi cancelado no Pipeline.' . ($linkedCaseId ? ' Caso #' . $linkedCaseId . ' também cancelado.' : ''), 'alerta', url('modules/pipeline/'), '❌');
        }

        // ── SUSPENSO: só Admin + bilateral com memória de estado ──
        if ($toStage === 'suspenso') {
            if (!has_role('admin')) {
                flash_set('error', 'Apenas administradores podem suspender.');
                redirect(module_url('pipeline'));
                exit;
            }
            $prazoSusp = isset($_POST['prazo_suspensao']) ? $_POST['prazo_suspensao'] : null;
            if ($prazoSusp === '') $prazoSusp = null;

            // Salvar coluna anterior + data da suspensão
            $pdo->prepare('UPDATE pipeline_leads SET coluna_antes_suspensao=?, data_suspensao=NOW(), prazo_suspensao=? WHERE id=?')
                ->execute(array($fromStage, $prazoSusp, $leadId));

            // Espelhar no Operacional
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                $caseStmt = $pdo->prepare('SELECT status FROM cases WHERE id = ?');
                $caseStmt->execute(array($linkedCaseId));
                $caseRow = $caseStmt->fetch();
                if ($caseRow && !in_array($caseRow['status'], array('cancelado', 'concluido', 'arquivado', 'suspenso'))) {
                    $pdo->prepare("UPDATE cases SET status='suspenso', coluna_antes_suspensao=?, data_suspensao=NOW(), prazo_suspensao=?, updated_at=NOW() WHERE id=?")
                        ->execute(array($caseRow['status'], $prazoSusp, $linkedCaseId));
                    audit_log('case_auto_suspended', 'case', $linkedCaseId, 'Pipeline suspendeu lead #' . $leadId);
                }
            }
            notify_gestao('Lead suspenso', $lead['name'] . ' foi suspenso no Pipeline.' . ($linkedCaseId ? ' Caso também suspenso.' : '') . ($prazoSusp ? ' Prazo: ' . $prazoSusp : ''), 'alerta', url('modules/pipeline/'), '⏸️');
        }

        // ── REATIVAR DO SUSPENSO: restaurar estado anterior ──
        if ($fromStage === 'suspenso' && $toStage !== 'suspenso') {
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                // Restaurar caso para status anterior
                $caseStmt = $pdo->prepare('SELECT coluna_antes_suspensao FROM cases WHERE id = ?');
                $caseStmt->execute(array($linkedCaseId));
                $caseRow = $caseStmt->fetch();
                $statusAnterior = ($caseRow && $caseRow['coluna_antes_suspensao']) ? $caseRow['coluna_antes_suspensao'] : 'em_andamento';
                $pdo->prepare("UPDATE cases SET status=?, coluna_antes_suspensao=NULL, data_suspensao=NULL, prazo_suspensao=NULL, updated_at=NOW() WHERE id=? AND status='suspenso'")
                    ->execute(array($statusAnterior, $linkedCaseId));
                audit_log('case_auto_reactivated', 'case', $linkedCaseId, 'Pipeline reativou lead #' . $leadId . ' → ' . $statusAnterior);
            }
            // Limpar dados de suspensão do lead
            $pdo->prepare('UPDATE pipeline_leads SET coluna_antes_suspensao=NULL, data_suspensao=NULL, prazo_suspensao=NULL WHERE id=?')
                ->execute(array($leadId));
            notify_gestao('Lead reativado!', $lead['name'] . ' saiu do Suspenso.', 'sucesso', url('modules/pipeline/'), '▶️');
        }

        // Labels para flash
        $stageLabels = array('cadastro_preenchido'=>'Cadastro Preenchido','elaboracao_docs'=>'Elaboração Docs','link_enviados'=>'Link Enviados','contrato_assinado'=>'Contrato Assinado','agendado_docs'=>'Agendado + Docs','reuniao_cobranca'=>'Reunião/Cobrança','doc_faltante'=>'Doc Faltante','pasta_apta'=>'Pasta Apta','cancelado'=>'Cancelado','suspenso'=>'Suspenso','perdido'=>'Perdido');
        flash_set('success', 'Lead movido para "' . (isset($stageLabels[$toStage]) ? $stageLabels[$toStage] : $toStage) . '".');
        redirect(module_url('pipeline'));
        break;

    case 'convert':
        // Manter compatibilidade
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
        $stmt->execute(array($leadId));
        $lead = $stmt->fetch();
        if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

        $clientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
        if (!$clientId) {
            $pdo->prepare('INSERT INTO clients (name, phone, email, source, notes, created_by) VALUES (?,?,?,?,?,?)')
                ->execute(array($lead['name'], $lead['phone'], $lead['email'], $lead['source'], 'Convertido do Pipeline', current_user_id()));
            $clientId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE pipeline_leads SET client_id=? WHERE id=?')->execute(array($clientId, $leadId));
        }
        flash_set('success', 'Cliente criado!');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'delete':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId) {
            $pdo->prepare('DELETE FROM pipeline_history WHERE lead_id = ?')->execute(array($leadId));
            $pdo->prepare('DELETE FROM pipeline_leads WHERE id = ?')->execute(array($leadId));
            audit_log('lead_deleted', 'lead', $leadId);
            flash_set('success', 'Lead excluído.');
        }
        redirect(module_url('pipeline'));
        break;

    case 'duplicate':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId) {
            $orig = $pdo->prepare("SELECT * FROM pipeline_leads WHERE id = ?");
            $orig->execute(array($leadId));
            $origLead = $orig->fetch();
            if ($origLead) {
                $pdo->prepare(
                    "INSERT INTO pipeline_leads (client_id, name, phone, email, source, stage, case_type, assigned_to, notes, created_at)
                     VALUES (?,?,?,?,?,'cadastro_preenchido','',?,?,NOW())"
                )->execute(array(
                    $origLead['client_id'], $origLead['name'], $origLead['phone'], $origLead['email'],
                    $origLead['source'], $origLead['assigned_to'], 'Duplicado de lead #' . $leadId
                ));
                $newId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO pipeline_history (lead_id, to_stage, changed_by, notes) VALUES (?,?,?,?)")
                    ->execute(array($newId, 'cadastro_preenchido', current_user_id(), 'Duplicado para nova ação'));
                flash_set('success', 'Lead duplicado! Edite o tipo de ação.');
                redirect(module_url('pipeline', 'lead_ver.php?id=' . $newId));
            }
        }
        redirect(module_url('pipeline'));
        break;

    case 'inline_edit':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
        $allowed = array('name','phone','email','case_type','notes','estimated_value_cents','assigned_to',
            'valor_acao','exito_percentual','vencimento_parcela','forma_pagamento','urgencia','cadastro_asaas','observacoes','nome_pasta','pendencias',
            'data_agendamento','onboard_realizado','origem_lead');
        if ($leadId && in_array($field, $allowed)) {
            if ($field === 'assigned_to') $value = (int)$value ?: null;
            $pdo->prepare("UPDATE pipeline_leads SET $field = ?, updated_at = NOW() WHERE id = ?")->execute(array($value ?: null, $leadId));
            // Sincronizar valor_acao → estimated_value_cents
            if ($field === 'valor_acao') { sync_estimated_value($pdo, $leadId, $value ?: null); }
            // GAMIFICAÇÃO: onboarding realizado
            if ($field === 'onboard_realizado' && $value) {
                $leadAssigned = $pdo->prepare("SELECT assigned_to FROM pipeline_leads WHERE id = ?");
                $leadAssigned->execute(array($leadId));
                $assignTo = (int)$leadAssigned->fetchColumn();
                if ($assignTo) gamificar($assignTo, 'onboarding_realizado', $leadId, 'pipeline_leads');
            }
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true)); exit; }
        }
        redirect(module_url('pipeline'));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('pipeline'));
}
