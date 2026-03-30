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

        $validStages = array('cadastro_preenchido','elaboracao_docs','link_enviados','contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado','perdido');
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

        // Labels para flash
        $stageLabels = array('cadastro_preenchido'=>'Cadastro Preenchido','elaboracao_docs'=>'Elaboração Docs','link_enviados'=>'Link Enviados','contrato_assinado'=>'Contrato Assinado','agendado_docs'=>'Agendado + Docs','reuniao_cobranca'=>'Reunião/Cobrança','doc_faltante'=>'Doc Faltante','pasta_apta'=>'Pasta Apta','perdido'=>'Perdido');
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

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('pipeline'));
}
