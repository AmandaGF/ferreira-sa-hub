<?php
/**
 * API de ações do Card (comentários + edição inline)
 * Chamada via AJAX pelo drawer lateral
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$userId = current_user_id();
$userName = current_user_name();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ═══════════════════════════════════════
    // COMENTÁRIOS
    // ═══════════════════════════════════════
    case 'add_comment':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
        $leadId = (int)($_POST['lead_id'] ?? 0) ?: null;
        $message = trim($_POST['message'] ?? '');

        if (!$clientId || !$message) {
            echo json_encode(array('error' => 'Cliente e mensagem são obrigatórios'));
            exit;
        }

        $pdo->prepare("INSERT INTO card_comments (client_id, case_id, lead_id, user_id, message) VALUES (?,?,?,?,?)")
            ->execute(array($clientId, $caseId, $leadId, $userId, $message));

        $commentId = (int)$pdo->lastInsertId();
        echo json_encode(array(
            'ok' => true,
            'comment' => array(
                'id' => $commentId,
                'user_name' => $userName,
                'message' => $message,
                'created_at' => date('Y-m-d H:i:s'),
            )
        ));
        break;

    case 'get_comments':
        $clientId = (int)($_GET['client_id'] ?? 0);
        if (!$clientId) { echo '[]'; exit; }

        $stmt = $pdo->prepare(
            "SELECT cc.*, u.name as user_name FROM card_comments cc
             LEFT JOIN users u ON u.id = cc.user_id
             WHERE cc.client_id = ? ORDER BY cc.created_at DESC LIMIT 50"
        );
        $stmt->execute(array($clientId));
        echo json_encode($stmt->fetchAll());
        break;

    case 'delete_comment':
        $commentId = (int)($_POST['comment_id'] ?? 0);
        // Só pode excluir o próprio ou se for gestão
        $stmt = $pdo->prepare("SELECT user_id FROM card_comments WHERE id = ?");
        $stmt->execute(array($commentId));
        $row = $stmt->fetch();
        if ($row && ((int)$row['user_id'] === $userId || has_min_role('gestao'))) {
            $pdo->prepare("DELETE FROM card_comments WHERE id = ?")->execute(array($commentId));
            echo json_encode(array('ok' => true));
        } else {
            echo json_encode(array('error' => 'Sem permissão'));
        }
        break;

    // ═══════════════════════════════════════
    // EDIÇÃO INLINE
    // ═══════════════════════════════════════
    case 'update_field':
        $entity = $_POST['entity'] ?? ''; // 'client', 'lead', 'case'
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';

        if (!$entityId || !$field || !$entity) {
            echo json_encode(array('error' => 'Dados incompletos'));
            exit;
        }

        // Campos editáveis por entidade (whitelist de segurança)
        $allowed = array(
            'client' => array('name','cpf','phone','email','address_street','address_city','address_state','address_zip','profession','marital_status','rg','birth_date','pix_key','notes'),
            'lead' => array('name','phone','email','case_type','valor_acao','vencimento_parcela','forma_pagamento','nome_pasta','pendencias','urgencia','observacoes','notes'),
            'case' => array('title','case_type','case_number','court','comarca','comarca_uf','regional','sistema_tribunal','segredo_justica','departamento','parte_re_nome','parte_re_cpf_cnpj','drive_folder_url','notes','priority'),
        );

        if (!isset($allowed[$entity]) || !in_array($field, $allowed[$entity])) {
            echo json_encode(array('error' => 'Campo não editável: ' . $field));
            exit;
        }

        $table = array('client' => 'clients', 'lead' => 'pipeline_leads', 'case' => 'cases');
        $tbl = $table[$entity];

        // Tratar valor vazio
        $dbValue = ($value === '' || $value === '—') ? null : $value;

        try {
            $pdo->prepare("UPDATE $tbl SET $field = ?, updated_at = NOW() WHERE id = ?")
                ->execute(array($dbValue, $entityId));

            audit_log('card_edit', $entity, $entityId, "$field = " . ($dbValue ?: 'NULL'));
            echo json_encode(array('ok' => true, 'field' => $field, 'value' => $value));
        } catch (Exception $e) {
            echo json_encode(array('error' => 'Erro ao salvar: ' . $e->getMessage()));
        }
        break;

    // ═══════════════════════════════════════
    // EXCLUIR CARD (lead e/ou caso)
    // ═══════════════════════════════════════
    case 'delete_card':
        if (!has_min_role('gestao')) {
            echo json_encode(array('error' => 'Apenas gestão ou admin pode excluir.'));
            break;
        }

        $leadId = (int)($_POST['lead_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);
        $deletedWhat = array();

        // Excluir caso (e desvincula do lead)
        if ($caseId) {
            $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = NULL WHERE linked_case_id = ?")->execute(array($caseId));
            $pdo->prepare("DELETE FROM documentos_pendentes WHERE case_id = ?")->execute(array($caseId));
            $pdo->prepare("DELETE FROM case_tasks WHERE case_id = ?")->execute(array($caseId));
            try { $pdo->prepare("DELETE FROM case_andamentos WHERE case_id = ?")->execute(array($caseId)); } catch (Exception $e) {}
            try { $pdo->prepare("DELETE FROM card_comments WHERE case_id = ?")->execute(array($caseId)); } catch (Exception $e) {}
            $pdo->prepare("DELETE FROM cases WHERE id = ?")->execute(array($caseId));
            audit_log('case_deleted', 'case', $caseId, 'Via drawer por ' . $userName);
            $deletedWhat[] = 'caso #' . $caseId;
        }

        // Excluir lead
        if ($leadId) {
            $pdo->prepare("DELETE FROM pipeline_history WHERE lead_id = ?")->execute(array($leadId));
            try { $pdo->prepare("DELETE FROM card_comments WHERE lead_id = ?")->execute(array($leadId)); } catch (Exception $e) {}
            try { $pdo->prepare("DELETE FROM documentos_pendentes WHERE lead_id = ?")->execute(array($leadId)); } catch (Exception $e) {}
            $pdo->prepare("DELETE FROM pipeline_leads WHERE id = ?")->execute(array($leadId));
            audit_log('lead_deleted', 'lead', $leadId, 'Via drawer por ' . $userName);
            $deletedWhat[] = 'lead #' . $leadId;
        }

        echo json_encode(array('ok' => true, 'deleted' => implode(' + ', $deletedWhat)));
        break;

    default:
        echo json_encode(array('error' => 'Ação inválida'));
}
