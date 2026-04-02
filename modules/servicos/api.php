<?php
/**
 * Ferreira & Sá Hub — API Extrajudicial
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('operacional');

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
        $category = isset($_POST['category']) ? $_POST['category'] : 'extrajudicial';

        if (!$clientId || !$title) {
            flash_set('error', 'Cliente e título são obrigatórios.');
            redirect(module_url('servicos'));
        }

        // Gerar número interno
        $ano = date('Y');
        $prefix = ($category === 'extrajudicial') ? 'EXT' : 'PRE';
        $lastNum = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE category = '$category' AND YEAR(created_at) = $ano")->fetchColumn();
        $internalNumber = $prefix . '-' . $ano . '-' . str_pad($lastNum + 1, 3, '0', STR_PAD_LEFT);

        $pdo->prepare(
            "INSERT INTO cases (client_id, title, case_type, category, status, priority, responsible_user_id, internal_number, opened_at, created_at)
             VALUES (?, ?, ?, ?, 'ativo', ?, ?, ?, CURDATE(), NOW())"
        )->execute(array($clientId, $title, $caseType, $category, $priority, $responsibleId ? $responsibleId : null, $internalNumber));

        $caseId = (int)$pdo->lastInsertId();
        generate_case_checklist($caseId, $caseType);
        $label = ($category === 'extrajudicial') ? 'Extrajudicial' : 'Pré-Processual';
        audit_log('case_created', 'case', $caseId, "$label: $internalNumber");

        if ($responsibleId) {
            notify($responsibleId, "Novo caso $label", $title . ' (' . $internalNumber . ')', 'info', url('modules/operacional/caso_ver.php?id=' . $caseId), '📋');
        }
        notify_gestao("Novo caso $label", $title . ' — ' . $internalNumber, 'info', url('modules/servicos/'), '📋');

        flash_set('success', "$label criado! Nº $internalNumber");
        redirect(module_url($category === 'extrajudicial' ? 'servicos' : 'pre_processual'));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('servicos'));
}
