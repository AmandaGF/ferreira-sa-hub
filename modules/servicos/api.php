<?php
/**
 * Ferreira & Sá Hub — API Serviços Administrativos
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('servicos')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('servicos')); }

$action = isset($_POST['action']) ? $_POST['action'] : '';
$pdo = db();

switch ($action) {
    case 'create':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $caseType = clean_str($_POST['case_type'] ?? '', 100);
        $title = clean_str($_POST['title'] ?? '', 200);
        $responsibleId = (int)($_POST['responsible_user_id'] ?? 0);
        $priority = $_POST['priority'] ?? 'normal';

        if (!$clientId || !$title) {
            flash_set('error', 'Cliente e título são obrigatórios.');
            redirect(module_url('servicos'));
        }

        // Gerar número interno: ADM-2026-XXX
        $ano = date('Y');
        $lastNum = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE (case_number IS NULL OR case_number = '') AND YEAR(created_at) = $ano")->fetchColumn();
        $internalNumber = 'ADM-' . $ano . '-' . str_pad($lastNum + 1, 3, '0', STR_PAD_LEFT);

        $pdo->prepare(
            "INSERT INTO cases (client_id, title, case_type, status, priority, responsible_user_id, internal_number, opened_at, created_at)
             VALUES (?, ?, ?, 'ativo', ?, ?, ?, CURDATE(), NOW())"
        )->execute(array($clientId, $title, $caseType, $priority, $responsibleId ? $responsibleId : null, $internalNumber));

        $caseId = (int)$pdo->lastInsertId();
        audit_log('service_created', 'case', $caseId, "Serviço administrativo: $internalNumber");

        // Notificar responsável
        if ($responsibleId) {
            notify($responsibleId, 'Novo serviço atribuído', $title . ' (' . $internalNumber . ')', 'info', url('modules/operacional/caso_ver.php?id=' . $caseId), '📋');
        }
        notify_gestao('Novo serviço administrativo', $title . ' — ' . $internalNumber, 'info', url('modules/servicos/'), '📋');

        flash_set('success', 'Serviço criado! Nº ' . $internalNumber);
        redirect(module_url('servicos'));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('servicos'));
}
