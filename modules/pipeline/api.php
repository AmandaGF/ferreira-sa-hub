<?php
/**
 * Ferreira & Sá Hub — API do Pipeline
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/google_drive.php';
require_min_role('gestao');

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

        $validStages = ['novo','contato_inicial','agendado','proposta','elaboracao','contrato','preparacao_pasta','pasta_apta','finalizado','perdido'];
        if (!$leadId || !in_array($toStage, $validStages)) {
            flash_set('error', 'Dados inválidos.');
            redirect(module_url('pipeline'));
        }

        $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();
        if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

        $fromStage = $lead['stage'];

        // Atualizar estágio
        if ($toStage === 'contrato') {
            $pdo->prepare('UPDATE pipeline_leads SET stage=?, converted_at=NOW(), updated_at=NOW() WHERE id=?')
                ->execute([$toStage, $leadId]);
        } else {
            $pdo->prepare('UPDATE pipeline_leads SET stage=?, updated_at=NOW() WHERE id=?')
                ->execute([$toStage, $leadId]);
        }

        // Se perdido, salvar motivo
        if ($toStage === 'perdido' && $notes) {
            $pdo->prepare('UPDATE pipeline_leads SET lost_reason=? WHERE id=?')
                ->execute([$notes, $leadId]);
        }

        // Registrar histórico
        $pdo->prepare('INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)')
            ->execute([$leadId, $fromStage, $toStage, current_user_id(), $notes ?: null]);

        audit_log('lead_moved', 'lead', $leadId, "$fromStage -> $toStage");

        // ── PREPARAÇÃO DA PASTA: criar caso no Operacional ──
        if ($toStage === 'preparacao_pasta') {
            // Verificar se já tem caso vinculado
            $existingCase = isset($lead['linked_case_id']) && $lead['linked_case_id']
                ? (int)$lead['linked_case_id'] : 0;

            if (!$existingCase) {
                // Criar/buscar cliente
                $clientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
                if (!$clientId) {
                    // Criar cliente
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

                // Criar caso no Operacional (status: aguardando_docs)
                $caseType = 'outro';
                if ($lead['case_type']) {
                    $typeMap = array(
                        'divórcio' => 'divorcio', 'divorcio' => 'divorcio',
                        'pensão' => 'pensao', 'pensao' => 'pensao', 'alimentos' => 'pensao',
                        'guarda' => 'guarda', 'convivência' => 'convivencia', 'convivencia' => 'convivencia',
                        'inventário' => 'inventario', 'inventario' => 'inventario',
                    );
                    $lowerType = mb_strtolower($lead['case_type']);
                    foreach ($typeMap as $key => $val) {
                        if (strpos($lowerType, $key) !== false) { $caseType = $val; break; }
                    }
                }

                // Título do caso = nome da pasta (digitado pelo usuário) ou fallback
                $caseTitle = $folderName ? $folderName : (($lead['case_type'] ?: 'Novo caso') . ' — ' . $lead['name']);

                $pdo->prepare(
                    "INSERT INTO cases (client_id, title, case_type, status, priority, responsible_user_id, opened_at, notes)
                     VALUES (?,?,?,'aguardando_docs','normal',?,CURDATE(),?)"
                )->execute(array(
                    $clientId,
                    $caseTitle,
                    $caseType,
                    $lead['assigned_to'],
                    'Preparação da pasta. Origem: Pipeline.' . ($folderName ? ' Pasta: ' . $folderName : '')
                ));
                $newCaseId = (int)$pdo->lastInsertId();
                generate_case_checklist($newCaseId, $caseType);

                // Vincular caso ao lead
                $pdo->prepare('UPDATE pipeline_leads SET linked_case_id=? WHERE id=?')
                    ->execute(array($newCaseId, $leadId));

                audit_log('case_auto_created', 'case', $newCaseId, 'Pipeline preparacao_pasta - lead: ' . $leadId);

                // Criar pasta no Google Drive (se configurado)
                $driveFolderName = $folderName ? $folderName : ($lead['name'] . ($lead['case_type'] ? ' x ' . $lead['case_type'] : ''));
                $driveResult = create_drive_folder($driveFolderName, $caseType, $newCaseId, $caseTitle);
                $driveMsg = '';
                if ($driveResult['success']) {
                    $driveMsg = ' Pasta criada no Drive!';
                }

                notify_gestao('Caso aberto no Operacional', $lead['name'] . ' entrou em Preparação da Pasta.' . $driveMsg, 'pendencia', url('modules/operacional/caso_ver.php?id=' . $newCaseId), '📂');
            }
        }

        // Notificações por evento
        $lName = $lead['name'];
        if ($toStage === 'contrato') {
            notify_gestao('Contrato assinado!', $lName . ' avançou para Contrato Assinado.', 'sucesso', url('modules/pipeline/'), '✅');
        } elseif ($toStage === 'pasta_apta') {
            notify_gestao('Pasta apta!', $lName . ' está com pasta apta.', 'sucesso', url('modules/pipeline/'), '✔️');
        } elseif ($toStage === 'perdido') {
            notify_gestao('Lead perdido', $lName . ' foi marcado como perdido.', 'alerta', url('modules/pipeline/'), '❌');
        }

        $stageLabels = array('novo'=>'Novo','contato_inicial'=>'Contato Inicial','agendado'=>'Agendado','proposta'=>'Proposta','elaboracao'=>'Elaboração Contrato','contrato'=>'Contrato Assinado','preparacao_pasta'=>'Preparação da Pasta','pasta_apta'=>'Pasta Apta','perdido'=>'Perdido');
        flash_set('success', 'Lead movido para "' . (isset($stageLabels[$toStage]) ? $stageLabels[$toStage] : $toStage) . '".');
        redirect(module_url('pipeline'));
        break;

    case 'convert':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();

        if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

        // Criar cliente no CRM
        $pdo->prepare(
            'INSERT INTO clients (name, phone, email, source, notes, created_by) VALUES (?,?,?,?,?,?)'
        )->execute([
            $lead['name'],
            $lead['phone'],
            $lead['email'],
            $lead['source'],
            'Convertido do Pipeline. Tipo: ' . ($lead['case_type'] ?: 'N/I'),
            current_user_id()
        ]);
        $clientId = (int)$pdo->lastInsertId();

        // Vincular lead ao cliente
        $pdo->prepare('UPDATE pipeline_leads SET client_id=? WHERE id=?')
            ->execute([$clientId, $leadId]);

        // Criar caso se tiver tipo
        if ($lead['case_type']) {
            $caseType = 'outro';
            $typeMap = [
                'divórcio' => 'divorcio', 'divorcio' => 'divorcio',
                'pensão' => 'pensao', 'pensao' => 'pensao', 'alimentos' => 'pensao',
                'guarda' => 'guarda', 'convivência' => 'convivencia', 'convivencia' => 'convivencia',
                'inventário' => 'inventario', 'inventario' => 'inventario',
                'família' => 'familia', 'familia' => 'familia',
                'responsabilidade' => 'responsabilidade_civil',
            ];
            $lowerType = mb_strtolower($lead['case_type']);
            foreach ($typeMap as $key => $val) {
                if (strpos($lowerType, $key) !== false) { $caseType = $val; break; }
            }

            $pdo->prepare(
                'INSERT INTO cases (client_id, title, case_type, priority, responsible_user_id, opened_at)
                 VALUES (?,?,?,?,?,CURDATE())'
            )->execute([
                $clientId,
                $lead['case_type'] . ' — ' . $lead['name'],
                $caseType,
                'normal',
                $lead['assigned_to']
            ]);
        }

        // Gerar checklist automático para o caso
        if ($lead['case_type']) {
            $lastCaseId = (int)$pdo->lastInsertId();
            if ($lastCaseId) {
                generate_case_checklist($lastCaseId, $lead['case_type']);
            }
        }

        audit_log('lead_converted', 'lead', $leadId, "client_id: $clientId");
        notify_gestao('Lead convertido em cliente', $lead['name'] . ' foi convertido e registrado no CRM.', 'sucesso', url('modules/crm/cliente_ver.php?id=' . $clientId), '🎉');
        flash_set('success', 'Cliente criado no CRM! Lead convertido.');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'delete':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId) {
            $pdo->prepare('DELETE FROM pipeline_leads WHERE id = ?')->execute([$leadId]);
            audit_log('lead_deleted', 'lead', $leadId);
            flash_set('success', 'Lead excluído.');
        }
        redirect(module_url('pipeline'));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('pipeline'));
}
