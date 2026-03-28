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
        audit_log('case_created', 'case', $newId);
        flash_set('success', 'Caso criado.');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'delete_client':
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$clientId]);
            audit_log('client_deleted', 'client', $clientId);
            flash_set('success', 'Cliente excluído.');
        }
        redirect(module_url('crm'));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('crm'));
}
