<?php
/**
 * Ferreira & Sá Hub — API de Links do Portal
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(module_url('portal'));
}

if (!validate_csrf()) {
    flash_set('error', 'Token de segurança inválido.');
    redirect(module_url('portal'));
}

// Apenas admin pode criar/editar/excluir
if (!has_role('admin')) {
    flash_set('error', 'Sem permissão.');
    redirect(module_url('portal'));
}

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'create':
    case 'update':
        $linkId   = (int)($_POST['link_id'] ?? 0);
        $title    = clean_str($_POST['title'] ?? '', 150);
        $category = clean_str($_POST['category'] ?? '', 60);
        $url      = trim($_POST['url'] ?? '');
        $username = clean_str($_POST['username'] ?? '', 100);
        $password = $_POST['password'] ?? '';
        $hint     = clean_str($_POST['hint'] ?? '', 500);
        $audience = $_POST['audience'] ?? 'internal';
        $favorite = (int)($_POST['is_favorite'] ?? 0);
        $order    = (int)($_POST['sort_order'] ?? 0);

        if (empty($title)) {
            flash_set('error', 'Título é obrigatório.');
            redirect(module_url('portal'));
        }

        if (empty($category)) {
            $category = 'Geral';
        }

        if (!in_array($audience, ['internal', 'client', 'both'])) {
            $audience = 'internal';
        }

        // Criptografar senha se preenchida
        $passEncrypted = null;
        if (!empty($password)) {
            $passEncrypted = encrypt_value($password);
        }

        if ($action === 'update' && $linkId > 0) {
            // Se senha vazia no update, manter a anterior
            if (empty($password)) {
                $stmt = $pdo->prepare('SELECT password_encrypted FROM portal_links WHERE id = ?');
                $stmt->execute([$linkId]);
                $existing = $stmt->fetch();
                $passEncrypted = $existing['password_encrypted'] ?? null;
            }

            $stmt = $pdo->prepare(
                'UPDATE portal_links SET title = ?, category = ?, url = ?, username = ?,
                 password_encrypted = ?, hint = ?, audience = ?, is_favorite = ?, sort_order = ?,
                 updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute([
                $title, $category, $url ?: null, $username ?: null,
                $passEncrypted, $hint ?: null, $audience, $favorite, $order,
                $linkId
            ]);
            audit_log('link_updated', 'portal_link', $linkId);
            flash_set('success', 'Link atualizado.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO portal_links (title, category, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $title, $category, $url ?: null, $username ?: null,
                $passEncrypted, $hint ?: null, $audience, $favorite, $order,
                current_user_id()
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log('link_created', 'portal_link', $newId);
            flash_set('success', 'Link criado.');
        }
        break;

    case 'delete':
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId > 0) {
            $pdo->prepare('DELETE FROM portal_links WHERE id = ?')->execute([$linkId]);
            audit_log('link_deleted', 'portal_link', $linkId);
            flash_set('success', 'Link excluído.');
        }
        break;

    default:
        flash_set('error', 'Ação inválida.');
}

redirect(module_url('portal'));
