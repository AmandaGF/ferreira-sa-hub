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
        $caseId = (int)($_GET['case_id'] ?? 0);
        $leadId = (int)($_GET['lead_id'] ?? 0);

        if (!$clientId && !$caseId && !$leadId) { echo '[]'; exit; }

        if ($caseId) {
            $stmt = $pdo->prepare(
                "SELECT cc.*, u.name as user_name FROM card_comments cc
                 LEFT JOIN users u ON u.id = cc.user_id
                 WHERE cc.case_id = ? ORDER BY cc.created_at DESC LIMIT 50"
            );
            $stmt->execute(array($caseId));
        } elseif ($leadId) {
            $stmt = $pdo->prepare(
                "SELECT cc.*, u.name as user_name FROM card_comments cc
                 LEFT JOIN users u ON u.id = cc.user_id
                 WHERE cc.lead_id = ? ORDER BY cc.created_at DESC LIMIT 50"
            );
            $stmt->execute(array($leadId));
        } else {
            $stmt = $pdo->prepare(
                "SELECT cc.*, u.name as user_name FROM card_comments cc
                 LEFT JOIN users u ON u.id = cc.user_id
                 WHERE cc.client_id = ? ORDER BY cc.created_at DESC LIMIT 50"
            );
            $stmt->execute(array($clientId));
        }
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
            'lead' => array('name','phone','email','case_type','valor_acao','exito_percentual','vencimento_parcela','forma_pagamento','nome_pasta','pendencias','urgencia','observacoes','notes'),
            'case' => array('title','case_type','case_number','court','comarca','comarca_uf','regional','sistema_tribunal','segredo_justica','departamento','parte_re_nome','parte_re_cpf_cnpj','drive_folder_url','notes','priority'),
            'task' => array('status','title','prioridade'),
        );

        if (!isset($allowed[$entity]) || !in_array($field, $allowed[$entity])) {
            echo json_encode(array('error' => 'Campo não editável: ' . $field));
            exit;
        }

        $table = array('client' => 'clients', 'lead' => 'pipeline_leads', 'case' => 'cases', 'task' => 'case_tasks');
        $tbl = $table[$entity];

        // Tratar valor vazio
        $dbValue = ($value === '' || $value === '—') ? null : $value;

        try {
            // Task: campo especial completed_at
            if ($entity === 'task' && $field === 'status') {
                $completedAt = ($dbValue === 'concluido') ? date('Y-m-d H:i:s') : null;
                $pdo->prepare("UPDATE case_tasks SET status = ?, completed_at = COALESCE(?, completed_at) WHERE id = ?")
                    ->execute(array($dbValue, $completedAt, $entityId));
            } else {
                $pdo->prepare("UPDATE $tbl SET $field = ?, updated_at = NOW() WHERE id = ?")
                    ->execute(array($dbValue, $entityId));
            }

            // Sincronizar valor_acao → estimated_value_cents
            if ($entity === 'lead' && $field === 'valor_acao') {
                sync_estimated_value($pdo, $entityId, $dbValue);
            }

            audit_log('card_edit', $entity, $entityId, "$field = " . ($dbValue ?: 'NULL'));
            echo json_encode(array('ok' => true, 'field' => $field, 'value' => $value));
        } catch (Exception $e) {
            echo json_encode(array('error' => 'Erro ao salvar: ' . $e->getMessage()));
        }
        break;

    // ═══════════════════════════════════════
    // REMOVER CARD DO FLUXO (arquiva, não apaga dados)
    // ═══════════════════════════════════════
    case 'delete_card':
        if (!has_min_role('gestao')) {
            echo json_encode(array('error' => 'Apenas gestão ou admin pode remover.'));
            break;
        }

        $leadId = (int)($_POST['lead_id'] ?? 0);
        $removedWhat = array();

        // NUNCA arquivar caso/pasta de processo via delete_card
        // Pastas só podem ser arquivadas explicitamente pelo Kanban Operacional

        // Arquivar lead (sai do Kanban Comercial, sem afetar métrica de perdidos)
        if ($leadId) {
            $stageAtual = '';
            $sl = $pdo->prepare("SELECT stage FROM pipeline_leads WHERE id = ?");
            $sl->execute(array($leadId));
            $sr = $sl->fetch();
            if ($sr) $stageAtual = $sr['stage'];

            $pdo->prepare("UPDATE pipeline_leads SET stage = 'arquivado', arquivado_por = ?, arquivado_em = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute(array($userId, $leadId));
            $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                ->execute(array($leadId, $stageAtual, 'arquivado', $userId, 'Arquivado via drawer por ' . $userName));
            audit_log('lead_archived', 'lead', $leadId, 'Arquivado via drawer por ' . $userName);
            $removedWhat[] = 'lead #' . $leadId . ' arquivado';
        }

        echo json_encode(array('ok' => true, 'removed' => implode(' + ', $removedWhat)));
        break;

    default:
        echo json_encode(array('error' => 'Ação inválida'));
}
