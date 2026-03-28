<?php
/**
 * Ferreira & Sá Hub — API do Pipeline
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('pipeline')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('pipeline')); }

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'move':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $toStage = $_POST['to_stage'] ?? '';
        $notes = clean_str($_POST['notes'] ?? '', 500);

        $validStages = ['novo','contato_inicial','agendado','proposta','elaboracao','contrato','perdido'];
        if (!$leadId || !in_array($toStage, $validStages)) {
            flash_set('error', 'Dados inválidos.');
            redirect(module_url('pipeline'));
        }

        $stmt = $pdo->prepare('SELECT stage FROM pipeline_leads WHERE id = ?');
        $stmt->execute([$leadId]);
        $current = $stmt->fetch();
        if (!$current) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

        $fromStage = $current['stage'];

        // Atualizar estágio
        $updateData = ['stage' => $toStage, 'updated_at' => date('Y-m-d H:i:s')];
        if ($toStage === 'contrato') {
            $pdo->prepare('UPDATE pipeline_leads SET stage=?, converted_at=NOW(), updated_at=NOW() WHERE id=?')
                ->execute([$toStage, $leadId]);
        } else {
            $pdo->prepare('UPDATE pipeline_leads SET stage=?, updated_at=NOW() WHERE id=?')
                ->execute([$toStage, $leadId]);
        }

        // Se perdido, salvar motivo
        if ($toStage === 'perdido' && $notes) {
            $pdo->prepare('UPDATE pipeline_leads SET lost_reason=? WHERE id=?')
                ->execute([$notes, $leadId]);
        }

        // Registrar histórico
        $pdo->prepare('INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)')
            ->execute([$leadId, $fromStage, $toStage, current_user_id(), $notes ?: null]);

        audit_log('lead_moved', 'lead', $leadId, "$fromStage -> $toStage");
        flash_set('success', 'Lead movido para ' . $toStage . '.');
        redirect(module_url('pipeline'));
        break;

    case 'convert':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();

        if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

        // Criar cliente no CRM
        $pdo->prepare(
            'INSERT INTO clients (name, phone, email, source, notes, created_by) VALUES (?,?,?,?,?,?)'
        )->execute([
            $lead['name'],
            $lead['phone'],
            $lead['email'],
            $lead['source'],
            'Convertido do Pipeline. Tipo: ' . ($lead['case_type'] ?: 'N/I'),
            current_user_id()
        ]);
        $clientId = (int)$pdo->lastInsertId();

        // Vincular lead ao cliente
        $pdo->prepare('UPDATE pipeline_leads SET client_id=? WHERE id=?')
            ->execute([$clientId, $leadId]);

        // Criar caso se tiver tipo
        if ($lead['case_type']) {
            $caseType = 'outro';
            $typeMap = [
                'divórcio' => 'divorcio', 'divorcio' => 'divorcio',
                'pensão' => 'pensao', 'pensao' => 'pensao', 'alimentos' => 'pensao',
                'guarda' => 'guarda', 'convivência' => 'convivencia', 'convivencia' => 'convivencia',
                'inventário' => 'inventario', 'inventario' => 'inventario',
                'família' => 'familia', 'familia' => 'familia',
                'responsabilidade' => 'responsabilidade_civil',
            ];
            $lowerType = mb_strtolower($lead['case_type']);
            foreach ($typeMap as $key => $val) {
                if (strpos($lowerType, $key) !== false) { $caseType = $val; break; }
            }

            $pdo->prepare(
                'INSERT INTO cases (client_id, title, case_type, priority, responsible_user_id, opened_at)
                 VALUES (?,?,?,?,?,CURDATE())'
            )->execute([
                $clientId,
                $lead['case_type'] . ' — ' . $lead['name'],
                $caseType,
                'normal',
                $lead['assigned_to']
            ]);
        }

        audit_log('lead_converted', 'lead', $leadId, "client_id: $clientId");
        flash_set('success', 'Cliente criado no CRM! Lead convertido.');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'delete':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId) {
            $pdo->prepare('DELETE FROM pipeline_leads WHERE id = ?')->execute([$leadId]);
            audit_log('lead_deleted', 'lead', $leadId);
            flash_set('success', 'Lead excluído.');
        }
        redirect(module_url('pipeline'));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('pipeline'));
}
