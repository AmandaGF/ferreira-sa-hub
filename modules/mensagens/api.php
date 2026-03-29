<?php
/**
 * Ferreira & Sá Hub — API de Mensagens Prontas
 */

require_once __DIR__ . '/../../core/middleware.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('mensagens')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('mensagens')); }

$action = isset($_POST['action']) ? $_POST['action'] : '';
$pdo = db();

switch ($action) {
    case 'create':
        $category = clean_str($_POST['category'] ?? '', 60);
        $title = clean_str($_POST['title'] ?? '', 190);
        $body = isset($_POST['body']) ? trim($_POST['body']) : '';
        $forWpp = isset($_POST['for_whatsapp']) ? 1 : 0;
        $forEmail = isset($_POST['for_email']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (!$title || !$body) {
            flash_set('error', 'Título e corpo são obrigatórios.');
            break;
        }

        // Detectar placeholders
        $placeholders = '';
        if (preg_match_all('/\{(\w+)\}/', $body, $matches)) {
            $placeholders = implode(', ', array_unique($matches[0]));
        }

        $pdo->prepare(
            "INSERT INTO message_templates (category, title, body, placeholders, for_whatsapp, for_email, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute(array($category, $title, $body, $placeholders, $forWpp, $forEmail, $sortOrder, current_user_id()));

        audit_log('template_created', 'message_template', (int)$pdo->lastInsertId(), $title);
        flash_set('success', 'Mensagem criada!');
        break;

    case 'update':
        $msgId = (int)($_POST['msg_id'] ?? 0);
        $category = clean_str($_POST['category'] ?? '', 60);
        $title = clean_str($_POST['title'] ?? '', 190);
        $body = isset($_POST['body']) ? trim($_POST['body']) : '';
        $forWpp = isset($_POST['for_whatsapp']) ? 1 : 0;
        $forEmail = isset($_POST['for_email']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (!$msgId || !$title || !$body) {
            flash_set('error', 'Dados inválidos.');
            break;
        }

        $placeholders = '';
        if (preg_match_all('/\{(\w+)\}/', $body, $matches)) {
            $placeholders = implode(', ', array_unique($matches[0]));
        }

        $pdo->prepare(
            "UPDATE message_templates SET category=?, title=?, body=?, placeholders=?, for_whatsapp=?, for_email=?, sort_order=? WHERE id=?"
        )->execute(array($category, $title, $body, $placeholders, $forWpp, $forEmail, $sortOrder, $msgId));

        audit_log('template_updated', 'message_template', $msgId, $title);
        flash_set('success', 'Mensagem atualizada!');
        break;

    case 'delete':
        $msgId = (int)($_POST['msg_id'] ?? 0);
        if ($msgId) {
            $pdo->prepare("DELETE FROM message_templates WHERE id = ?")->execute(array($msgId));
            audit_log('template_deleted', 'message_template', $msgId);
            flash_set('success', 'Mensagem excluída.');
        }
        break;
}

redirect(module_url('mensagens'));
