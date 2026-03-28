<?php
/**
 * Ferreira & Sá Hub — API de Formulários
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('formularios')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('formularios')); }

$action = $_POST['action'] ?? '';
$formId = (int)($_POST['form_id'] ?? 0);
$pdo = db();

switch ($action) {
    case 'update_status':
        $status = $_POST['status'] ?? '';
        $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $validStatuses = ['novo', 'em_analise', 'processado', 'arquivado'];

        if ($formId && in_array($status, $validStatuses)) {
            $pdo->prepare('UPDATE form_submissions SET status=?, assigned_to=?, updated_at=NOW() WHERE id=?')
                ->execute([$status, $assignedTo, $formId]);
            audit_log('form_status', 'form', $formId, $status);
            flash_set('success', 'Status atualizado.');
        }
        redirect(module_url('formularios', 'ver.php?id=' . $formId));
        break;

    case 'update_notes':
        $notes = clean_str($_POST['notes'] ?? '', 2000);
        if ($formId) {
            $pdo->prepare('UPDATE form_submissions SET notes=?, updated_at=NOW() WHERE id=?')
                ->execute([$notes, $formId]);
            flash_set('success', 'Notas salvas.');
        }
        redirect(module_url('formularios', 'ver.php?id=' . $formId));
        break;

    case 'link_client':
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($formId && $clientId) {
            $pdo->prepare('UPDATE form_submissions SET linked_client_id=?, updated_at=NOW() WHERE id=?')
                ->execute([$clientId, $formId]);
            audit_log('form_linked', 'form', $formId, "client: $clientId");
            flash_set('success', 'Formulário vinculado ao cliente.');
        }
        redirect(module_url('formularios', 'ver.php?id=' . $formId));
        break;

    case 'create_client_from_form':
        if (!$formId) break;
        $stmt = $pdo->prepare('SELECT * FROM form_submissions WHERE id = ?');
        $stmt->execute([$formId]);
        $form = $stmt->fetch();
        if (!$form) break;

        $pdo->prepare(
            'INSERT INTO clients (name, phone, email, source, notes, created_by) VALUES (?,?,?,?,?,?)'
        )->execute([
            $form['client_name'] ?: 'Sem nome',
            $form['client_phone'],
            $form['client_email'],
            'landing',
            'Criado a partir do formulário ' . $form['protocol'] . ' (' . $form['form_type'] . ')',
            current_user_id()
        ]);
        $clientId = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE form_submissions SET linked_client_id=?, status="em_analise", updated_at=NOW() WHERE id=?')
            ->execute([$clientId, $formId]);

        audit_log('client_from_form', 'client', $clientId, "form: $formId");
        flash_set('success', 'Cliente criado e vinculado!');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('formularios'));
}
