<?php
/**
 * Ferreira & Sá Hub — API de Formulários
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('formularios');

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

    case 'delete':
        if ($formId) {
            $pdo->prepare('DELETE FROM form_submissions WHERE id = ?')->execute(array($formId));
            audit_log('form_deleted', 'form', $formId);
            flash_set('success', 'Formulário apagado.');
        }
        $redirectType = $_POST['redirect_type'] ?? '';
        redirect(module_url('formularios', $redirectType ? '?type=' . urlencode($redirectType) : ''));
        break;

    case 'salvar_gastos_edit':
    case 'reverter_gastos_edit':
        // Edição manual dos valores do Relatório de Gastos. NÃO sobrescreve os
        // dados originais do cliente: guarda uma camada de override em
        // payload['_edit'] que o relatorio_gastos.php aplica por cima.
        // Reversível (reverter_gastos_edit limpa a camada). Amanda 19/06/2026.
        header('Content-Type: application/json; charset=utf-8');
        $st = $pdo->prepare("SELECT id, payload_json FROM form_submissions WHERE id = ? AND form_type IN ('gastos_pensao','despesas_mensais') LIMIT 1");
        $st->execute(array($formId));
        $f = $st->fetch();
        if (!$f) { echo json_encode(array('ok' => false, 'erro' => 'Formulário de gastos não encontrado.')); exit; }

        $payload = json_decode($f['payload_json'], true);
        if (!is_array($payload)) $payload = array();

        if ($action === 'reverter_gastos_edit') {
            unset($payload['_edit'], $payload['_edit_meta']);
            $pdo->prepare('UPDATE form_submissions SET payload_json = ?, updated_at = NOW() WHERE id = ?')
                ->execute(array(json_encode($payload, JSON_UNESCAPED_UNICODE), $formId));
            audit_log('gastos_edit_revert', 'form', $formId);
            echo json_encode(array('ok' => true, 'revertido' => true));
            exit;
        }

        // salvar: recebe JSON {cats:{key:cents}, subs:{key:cents}, total_geral_cents:int}
        $editIn = json_decode($_POST['edit'] ?? '', true);
        if (!is_array($editIn)) { echo json_encode(array('ok' => false, 'erro' => 'Dados de edição inválidos.')); exit; }

        $clean = array('cats' => array(), 'subs' => array());
        if (!empty($editIn['cats']) && is_array($editIn['cats'])) {
            foreach ($editIn['cats'] as $k => $v) {
                if (preg_match('/^[a-z_]+$/', (string)$k)) $clean['cats'][$k] = max(0, (int)round($v));
            }
        }
        if (!empty($editIn['subs']) && is_array($editIn['subs'])) {
            foreach ($editIn['subs'] as $k => $v) {
                if (preg_match('/^[a-z0-9_]+$/i', (string)$k)) $clean['subs'][$k] = max(0, (int)round($v));
            }
        }
        if (isset($editIn['total_geral_cents'])) $clean['total_geral_cents'] = max(0, (int)round($editIn['total_geral_cents']));

        // Nome de quem editou (pro selo)
        $uid = current_user_id();
        $nome = '';
        try { $u = $pdo->prepare('SELECT name FROM users WHERE id = ?'); $u->execute(array($uid)); $nome = (string)$u->fetchColumn(); } catch (Exception $e) {}

        $payload['_edit'] = $clean;
        $payload['_edit_meta'] = array('por_id' => (int)$uid, 'por_nome' => $nome, 'em' => date('Y-m-d H:i:s'));

        $pdo->prepare('UPDATE form_submissions SET payload_json = ?, updated_at = NOW() WHERE id = ?')
            ->execute(array(json_encode($payload, JSON_UNESCAPED_UNICODE), $formId));
        audit_log('gastos_edit_save', 'form', $formId, count($clean['cats']) . ' cats, ' . count($clean['subs']) . ' subs');
        echo json_encode(array('ok' => true));
        exit;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('formularios'));
}
