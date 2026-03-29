<?php
/**
 * Ferreira & Sá Hub — API do CRM
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('crm')); }
if (!validate_csrf()) {
    flash_set('error', 'Token inválido.');
    redirect(module_url('crm'));
}

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'add_contact':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $type = $_POST['type'] ?? 'nota';
        $summary = clean_str($_POST['summary'] ?? '', 2000);
        $contactedAt = $_POST['contacted_at'] ?? date('Y-m-d H:i:s');

        if (!$clientId || empty($summary)) {
            flash_set('error', 'Preencha o resumo.');
            redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        }

        $validTypes = ['whatsapp', 'telefone', 'email', 'presencial', 'reuniao', 'nota'];
        if (!in_array($type, $validTypes)) $type = 'nota';

        $pdo->prepare(
            'INSERT INTO contacts (client_id, type, summary, contacted_by, contacted_at) VALUES (?,?,?,?,?)'
        )->execute([$clientId, $type, $summary, current_user_id(), $contactedAt]);

        audit_log('contact_added', 'client', $clientId);
        flash_set('success', 'Contato registrado.');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'add_case':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $title = clean_str($_POST['title'] ?? '', 200);
        $caseType = $_POST['case_type'] ?? 'outro';
        $caseNumber = clean_str($_POST['case_number'] ?? '', 30);
        $court = clean_str($_POST['court'] ?? '', 150);
        $priority = $_POST['priority'] ?? 'normal';
        $responsibleId = (int)($_POST['responsible_user_id'] ?? 0) ?: null;
        $deadline = $_POST['deadline'] ?? null;
        $notes = clean_str($_POST['notes'] ?? '', 2000);

        if (!$clientId || empty($title)) {
            flash_set('error', 'Título é obrigatório.');
            redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        }

        if ($deadline === '') $deadline = null;

        $pdo->prepare(
            'INSERT INTO cases (client_id, title, case_type, case_number, court, priority, responsible_user_id, deadline, notes, opened_at)
             VALUES (?,?,?,?,?,?,?,?,?,CURDATE())'
        )->execute([$clientId, $title, $caseType, $caseNumber ?: null, $court ?: null, $priority, $responsibleId, $deadline, $notes ?: null]);

        $newId = (int)$pdo->lastInsertId();
        generate_case_checklist($newId, $caseType);
        audit_log('case_created', 'case', $newId);
        flash_set('success', 'Caso criado com checklist automático.');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'update_client_status':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $newStatus = $_POST['client_status'] ?? '';
        $validStatuses = array('ativo', 'contrato_assinado', 'cancelou', 'parou_responder', 'demitido');

        if ($clientId && in_array($newStatus, $validStatuses)) {
            $pdo->prepare('UPDATE clients SET client_status = ?, updated_at = NOW() WHERE id = ?')
                ->execute(array($newStatus, $clientId));

            audit_log('client_status_changed', 'client', $clientId, $newStatus);

            // Se CONTRATO ASSINADO → criar caso no Operacional
            if ($newStatus === 'contrato_assinado') {
                // Buscar dados do cliente
                $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
                $stmt->execute(array($clientId));
                $cl = $stmt->fetch();

                if ($cl) {
                    // Verificar se já tem caso ativo
                    $existCase = $pdo->prepare("SELECT id FROM cases WHERE client_id = ? AND status NOT IN ('concluido','arquivado') LIMIT 1");
                    $existCase->execute(array($clientId));

                    if (!$existCase->fetch()) {
                        // Criar caso
                        $pdo->prepare(
                            "INSERT INTO cases (client_id, title, case_type, status, priority, opened_at, notes)
                             VALUES (?, ?, 'outro', 'aguardando_docs', 'normal', CURDATE(), ?)"
                        )->execute(array(
                            $clientId,
                            'Novo caso — ' . $cl['name'],
                            'Contrato assinado em ' . date('d/m/Y') . '. Aguardando documentação.'
                        ));
                        $caseId = (int)$pdo->lastInsertId();
                        generate_case_checklist($caseId, 'outro');
                        audit_log('case_auto_created', 'case', $caseId, 'Contrato assinado - client: ' . $clientId);

                        flash_set('success', 'Status atualizado para "Contrato Assinado" e caso criado no Operacional (#' . $caseId . ')!');
                        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
                    }
                }
            }

            $statusLabels = array('ativo'=>'Ativo', 'contrato_assinado'=>'Contrato Assinado', 'cancelou'=>'Cancelou', 'parou_responder'=>'Parou de Responder', 'demitido'=>'Demitimos');

            // Notificações por status
            $cliStmt = $pdo->prepare('SELECT name FROM clients WHERE id = ?');
            $cliStmt->execute(array($clientId));
            $cliRow = $cliStmt->fetch();
            $cliName = $cliRow ? $cliRow['name'] : 'Cliente';

            if ($newStatus === 'contrato_assinado') {
                notify_gestao('Contrato assinado!', $cliName . ' teve contrato assinado.', 'sucesso', url('modules/crm/cliente_ver.php?id=' . $clientId), '✅');
            } elseif ($newStatus === 'cancelou') {
                notify_gestao('Cliente cancelou', $cliName . ' cancelou o serviço.', 'alerta', url('modules/crm/cliente_ver.php?id=' . $clientId), '⚠️');
            }

            flash_set('success', 'Status alterado para "' . ($statusLabels[$newStatus] ?? $newStatus) . '".');
        }
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'remove_from_crm':
        // Apenas arquiva os formulários — NÃO apaga o cadastro do cliente
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId) {
            $pdo->prepare("UPDATE form_submissions SET status = 'arquivado' WHERE linked_client_id = ?")
                ->execute(array($clientId));
            audit_log('client_removed_from_crm', 'client', $clientId);
            flash_set('success', 'Cliente removido do CRM. O cadastro do contato foi mantido.');
        }
        redirect(module_url('crm'));
        break;

    case 'delete_client':
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId) {
            // Apagar leads do pipeline vinculados
            $pdo->prepare('DELETE FROM pipeline_history WHERE lead_id IN (SELECT id FROM pipeline_leads WHERE client_id = ?)')->execute(array($clientId));
            $pdo->prepare('DELETE FROM pipeline_leads WHERE client_id = ?')->execute(array($clientId));
            // Apagar contatos
            $pdo->prepare('DELETE FROM contacts WHERE client_id = ?')->execute(array($clientId));
            // Apagar tarefas dos casos
            $pdo->prepare('DELETE FROM case_tasks WHERE case_id IN (SELECT id FROM cases WHERE client_id = ?)')->execute(array($clientId));
            // Apagar casos
            $pdo->prepare('DELETE FROM cases WHERE client_id = ?')->execute(array($clientId));
            // Desvincular formulários (não apagar, só desvincular)
            $pdo->prepare('UPDATE form_submissions SET linked_client_id = NULL WHERE linked_client_id = ?')->execute(array($clientId));
            // Apagar cliente
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute(array($clientId));
            audit_log('client_deleted', 'client', $clientId);
            flash_set('success', 'Cliente e dados relacionados excluídos.');
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        if (strpos($referer, 'clientes') !== false) {
            redirect(module_url('clientes'));
        } else {
            redirect(module_url('crm'));
        }
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('crm'));
}
