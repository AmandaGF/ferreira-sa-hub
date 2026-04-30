<?php
/**
 * Ferreira & Sá Hub — API do Pipeline Comercial/CX
 * Gatilhos automáticos conforme doc técnico Kanban v2
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/google_drive.php';

// Detectar se é chamada AJAX (pra retornar JSON de erro em vez de redirect HTML)
$_isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
function _api_fail($msg, $code = 400) {
    global $_isAjax;
    if ($_isAjax) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => $msg));
        exit;
    }
    flash_set('error', $msg);
    redirect(url('modules/dashboard/'));
    exit;
}

if (!is_logged_in()) _api_fail('Sua sessão expirou. Recarregue a página (F5).', 401);
if (!can_view_pipeline()) _api_fail('Sem permissão.', 403);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('pipeline')); }
if (!validate_csrf()) _api_fail('Token CSRF inválido — sessão pode ter expirado. Recarregue a página (F5).', 419);

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'move':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $toStage = $_POST['to_stage'] ?? '';
        $notes = clean_str($_POST['notes'] ?? '', 500);
        $folderName = isset($_POST['folder_name']) ? clean_str($_POST['folder_name'], 200) : '';

        $validStages = array('cadastro_preenchido','elaboracao_docs','link_enviados','contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','cancelado','suspenso','finalizado','perdido');
        if (!$leadId || !in_array($toStage, $validStages)) {
            flash_set('error', 'Dados inválidos.');
            redirect(module_url('pipeline'));
        }

        $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
        $stmt->execute(array($leadId));
        $lead = $stmt->fetch();
        if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

        $fromStage = $lead['stage'];

        // ── Checagens de permissão ANTES de qualquer update (para não dessincronizar) ──
        if (in_array($toStage, array('cancelado','suspenso'), true) && !has_role('admin')) {
            flash_set('error', 'Apenas administradores podem ' . ($toStage === 'cancelado' ? 'cancelar' : 'suspender') . '.');
            redirect(module_url('pipeline'));
            exit;
        }

        // Atualizar estágio
        $pdo->prepare('UPDATE pipeline_leads SET stage=?, updated_at=NOW() WHERE id=?')
            ->execute(array($toStage, $leadId));

        // Se perdido, salvar motivo
        if ($toStage === 'perdido' && $notes) {
            $pdo->prepare('UPDATE pipeline_leads SET lost_reason=? WHERE id=?')
                ->execute(array($notes, $leadId));
        }

        // Registrar histórico
        $pdo->prepare('INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)')
            ->execute(array($leadId, $fromStage, $toStage, current_user_id(), $notes ?: null));

        audit_log('lead_moved', 'lead', $leadId, "$fromStage -> $toStage");

        // ═══════════════════════════════════════════════════
        // GATILHOS AUTOMÁTICOS
        // ═══════════════════════════════════════════════════

        // ── CONTRATO ASSINADO: criar pasta Drive + caso no Operacional ──
        if ($toStage === 'contrato_assinado') {
            $pdo->prepare('UPDATE pipeline_leads SET converted_at=NOW() WHERE id=? AND converted_at IS NULL')
                ->execute(array($leadId));

            // Atualizar case_type do lead se selecionado no modal
            $caseTypeSelected = clean_str($_POST['case_type_selected'] ?? '', 60);
            if ($caseTypeSelected) {
                $pdo->prepare("UPDATE pipeline_leads SET case_type = ? WHERE id = ?")->execute(array($caseTypeSelected, $leadId));
                $lead['case_type'] = $caseTypeSelected;
            }

            // Criar/buscar cliente
            $clientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
            if (!$clientId) {
                $pdo->prepare(
                    'INSERT INTO clients (name, phone, email, source, notes, created_by) VALUES (?,?,?,?,?,?)'
                )->execute(array(
                    $lead['name'], $lead['phone'], $lead['email'],
                    $lead['source'], 'Convertido do Pipeline. Tipo: ' . ($lead['case_type'] ?: 'N/I'),
                    current_user_id()
                ));
                $clientId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE pipeline_leads SET client_id=? WHERE id=?')
                    ->execute(array($clientId, $leadId));
            }

            // Título do caso
            $caseTitle = $folderName ? $folderName : ($lead['name'] . ($lead['case_type'] ? ' x ' . $lead['case_type'] : ''));

            // Verificar se já tem caso vinculado
            $existingCase = isset($lead['linked_case_id']) && $lead['linked_case_id'] ? (int)$lead['linked_case_id'] : 0;

            if (!$existingCase) {
                // Criar caso no Operacional (status: aguardando_docs = contrato assinado aguardando documentação)
                $caseType = 'outro';
                if ($lead['case_type']) {
                    $typeMap = array('divórcio'=>'divorcio','divorcio'=>'divorcio','pensão'=>'pensao','pensao'=>'pensao','alimentos'=>'pensao','guarda'=>'guarda','convivência'=>'convivencia','convivencia'=>'convivencia','inventário'=>'inventario','inventario'=>'inventario');
                    $lowerType = mb_strtolower($lead['case_type']);
                    foreach ($typeMap as $key => $val) {
                        if (strpos($lowerType, $key) !== false) { $caseType = $val; break; }
                    }
                }

                $pdo->prepare(
                    "INSERT INTO cases (client_id, title, case_type, status, priority, responsible_user_id, opened_at, notes)
                     VALUES (?,?,?,'aguardando_docs','normal',?,CURDATE(),?)"
                )->execute(array(
                    $clientId, $caseTitle, $caseType, $lead['assigned_to'],
                    'Contrato assinado. Aguardando documentação. Origem: Pipeline.'
                ));
                $newCaseId = (int)$pdo->lastInsertId();
                generate_case_checklist($newCaseId, $caseType);

                $pdo->prepare('UPDATE pipeline_leads SET linked_case_id=? WHERE id=?')
                    ->execute(array($newCaseId, $leadId));

                // Criar pasta no Google Drive
                $driveFolderName = $folderName ? $folderName : ($lead['name'] . ($lead['case_type'] ? ' x ' . $lead['case_type'] : ''));
                $driveResult = create_drive_folder($driveFolderName, $caseType, $newCaseId, $caseTitle);

                audit_log('case_auto_created', 'case', $newCaseId, 'Pipeline contrato_assinado - lead: ' . $leadId);
                notify_gestao('Contrato assinado!', $lead['name'] . ' — Caso criado no Operacional.' . ($driveResult['success'] ? ' Pasta criada no Drive!' : ''), 'sucesso', url('modules/operacional/caso_ver.php?id=' . $newCaseId), '✅');
                if (!$driveResult['success']) {
                    notify_gestao('Falha ao criar pasta no Drive', $lead['name'] . ' — ' . ($driveResult['error'] ?? 'erro desconhecido') . '. Use o botão "Criar pasta" na pasta do caso pra tentar de novo.', 'erro', url('modules/operacional/caso_ver.php?id=' . $newCaseId), '⚠️');
                }

                // GAMIFICAÇÃO: contrato fechado
                $assignedTo = isset($lead['assigned_to']) ? (int)$lead['assigned_to'] : 0;
                if ($assignedTo > 0) {
                    gamificar($assignedTo, 'contrato_fechado', $leadId, 'pipeline_leads');
                    // Bônus alto valor (>R$2k)
                    $valorCents = isset($lead['estimated_value_cents']) ? (int)$lead['estimated_value_cents'] : 0;
                    if (!$valorCents) $valorCents = isset($lead['honorarios_cents']) ? (int)$lead['honorarios_cents'] : 0;
                    if ($valorCents > 200000) {
                        gamificar($assignedTo, 'contrato_bonus_alto', $leadId, 'pipeline_leads');
                    }
                    // Push notification pro usuário que fechou
                    if (function_exists('push_notify')) {
                        push_notify($assignedTo, '🏆 Contrato fechado!', $lead['name'] . ' — +50 pts', '/conecta/modules/pipeline/');
                    }
                }

                // BLOCO 4: Notificar cliente — Boas-vindas
                notificar_cliente('boas_vindas', $clientId, array('[tipo_acao]' => $lead['case_type'] ?: ''), $newCaseId, $leadId);
            } else {
                // Lead já tinha caso vinculado. Se o caso está sem pasta no Drive,
                // tentar criar agora (cobre falhas do Apps Script na criação inicial).
                $stCase = $pdo->prepare("SELECT id, title, case_type, drive_folder_url FROM cases WHERE id = ?");
                $stCase->execute(array($existingCase));
                $caseExistente = $stCase->fetch();
                if ($caseExistente && empty($caseExistente['drive_folder_url'])) {
                    require_once APP_ROOT . '/core/google_drive.php';
                    $driveFolderName = $folderName ? $folderName : ($caseExistente['title'] ?: ($lead['name'] . ($lead['case_type'] ? ' x ' . $lead['case_type'] : '')));
                    $driveResult = create_drive_folder($driveFolderName, $caseExistente['case_type'] ?: 'outro', (int)$existingCase, $caseExistente['title']);
                    if ($driveResult['success']) {
                        notify_gestao('Pasta criada!', $lead['name'] . ' — pasta do Drive criada (retry).', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $existingCase), '📂');
                    } else {
                        notify_gestao('Falha ao criar pasta no Drive', $lead['name'] . ' — ' . ($driveResult['error'] ?? 'erro desconhecido'), 'erro', url('modules/operacional/caso_ver.php?id=' . $existingCase), '⚠️');
                    }
                }
            }
        }

        // ── PASTA APTA: espelhar no Operacional ──
        if ($toStage === 'pasta_apta') {
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                // Atualizar caso no Operacional para "pasta_apta" (em_elaboracao)
                $pdo->prepare("UPDATE cases SET status = 'em_elaboracao', updated_at = NOW() WHERE id = ? AND status = 'aguardando_docs'")
                    ->execute(array($linkedCaseId));
                notify_gestao('Pasta apta!', $lead['name'] . ' está com pasta apta. Operacional pode executar.', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $linkedCaseId), '✔️');

                // BLOCO 4: Notificar cliente — Documentos recebidos
                $clientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
                if ($clientId) {
                    notificar_cliente('docs_recebidos', $clientId, array('[tipo_acao]' => $lead['case_type'] ?: ''), $linkedCaseId, $leadId);
                }
            }
        }

        // ── DOC FALTANTE: espelhar no Operacional ──
        if ($toStage === 'doc_faltante') {
            $docDesc = clean_str($_POST['doc_faltante_desc'] ?? 'Documento não especificado', 1000);
            $docItens = array_filter(array_map('trim', explode(';', $docDesc)));
            if (empty($docItens)) $docItens = array($docDesc);
            $docDescLimpo = implode('; ', $docItens);

            // Salvar motivo no lead
            $pdo->prepare("UPDATE pipeline_leads SET doc_faltante_motivo = ?, stage_antes_doc_faltante = ? WHERE id = ?")
                ->execute(array($docDescLimpo, $fromStage, $leadId));

            // Espelhar no caso do Operacional
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                $caseStmt = $pdo->prepare("SELECT status FROM cases WHERE id = ?");
                $caseStmt->execute(array($linkedCaseId));
                $caseRow = $caseStmt->fetch();
                $oldCaseStatus = $caseRow ? $caseRow['status'] : '';

                $pdo->prepare("UPDATE cases SET status = 'doc_faltante', stage_antes_doc_faltante = ?, updated_at = NOW() WHERE id = ?")
                    ->execute(array($oldCaseStatus, $linkedCaseId));

                // Registrar documentos pendentes
                $clientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
                if ($clientId) {
                    $stmtDoc = $pdo->prepare("INSERT INTO documentos_pendentes (client_id, case_id, lead_id, descricao, solicitado_por) VALUES (?,?,?,?,?)");
                    foreach ($docItens as $item) {
                        $stmtDoc->execute(array($clientId, $linkedCaseId, $leadId, $item, current_user_id()));
                    }
                }

                // Notificar cliente
                if ($clientId) {
                    notificar_cliente('doc_faltante', $clientId, array(
                        '[descricao_documento]' => $docDescLimpo,
                        '[tipo_acao]' => $lead['case_type'] ?: '',
                    ), $linkedCaseId, $leadId);
                }
            }

            notify_gestao('Documento faltante!', $lead['name'] . ' — ' . $docDescLimpo, 'alerta', url('modules/pipeline/'), '');
            audit_log('doc_faltante_pipeline', 'lead', $leadId, $docDescLimpo);
        }

        // ── DOC FALTANTE RESOLVIDO (CX resolve): retornar ao Operacional ──
        if ($fromStage === 'doc_faltante' && $toStage !== 'doc_faltante') {
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                // Retornar caso para "em_andamento" (em execução)
                $pdo->prepare("UPDATE cases SET status = 'em_andamento', stage_antes_doc_faltante = NULL, updated_at = NOW() WHERE id = ? AND status = 'doc_faltante'")
                    ->execute(array($linkedCaseId));

                // Marcar documentos pendentes como recebidos
                $pdo->prepare("UPDATE documentos_pendentes SET status = 'recebido', recebido_em = NOW(), recebido_por = ? WHERE lead_id = ? AND status = 'pendente'")
                    ->execute(array(current_user_id(), $leadId));

                notify_gestao('Documento recebido!', $lead['name'] . ' — documento recebido, caso retornou para execução.', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $linkedCaseId), '📄');
            }
        }

        // ── CANCELADO ou PERDIDO: espelhar no Operacional ──
        if ($toStage === 'cancelado' || $toStage === 'perdido') {
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                $pdo->prepare("UPDATE cases SET status = 'cancelado', closed_at = CURDATE(), updated_at = NOW() WHERE id = ? AND status NOT IN ('cancelado','arquivado','finalizado')")
                    ->execute(array($linkedCaseId));
                audit_log('case_auto_cancelled', 'case', $linkedCaseId, 'Pipeline ' . $toStage . ' lead #' . $leadId);
            }
            notify_gestao('Lead ' . $toStage, $lead['name'] . ' foi ' . $toStage . ' no Pipeline.' . ($linkedCaseId ? ' Caso #' . $linkedCaseId . ' também cancelado.' : ''), 'alerta', url('modules/pipeline/'), '❌');
        }

        // ── SUSPENSO: bilateral com memória de estado (admin checado acima) ──
        if ($toStage === 'suspenso') {
            $prazoSusp = isset($_POST['prazo_suspensao']) ? $_POST['prazo_suspensao'] : null;
            if ($prazoSusp === '') $prazoSusp = null;

            // Salvar coluna anterior + data da suspensão
            $pdo->prepare('UPDATE pipeline_leads SET coluna_antes_suspensao=?, data_suspensao=NOW(), prazo_suspensao=? WHERE id=?')
                ->execute(array($fromStage, $prazoSusp, $leadId));

            // Espelhar no Operacional
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                $caseStmt = $pdo->prepare('SELECT status FROM cases WHERE id = ?');
                $caseStmt->execute(array($linkedCaseId));
                $caseRow = $caseStmt->fetch();
                if ($caseRow && !in_array($caseRow['status'], array('cancelado', 'concluido', 'arquivado', 'suspenso'))) {
                    $pdo->prepare("UPDATE cases SET status='suspenso', coluna_antes_suspensao=?, data_suspensao=NOW(), prazo_suspensao=?, updated_at=NOW() WHERE id=?")
                        ->execute(array($caseRow['status'], $prazoSusp, $linkedCaseId));
                    audit_log('case_auto_suspended', 'case', $linkedCaseId, 'Pipeline suspendeu lead #' . $leadId);
                }
            }
            notify_gestao('Lead suspenso', $lead['name'] . ' foi suspenso no Pipeline.' . ($linkedCaseId ? ' Caso também suspenso.' : '') . ($prazoSusp ? ' Prazo: ' . $prazoSusp : ''), 'alerta', url('modules/pipeline/'), '⏸️');
        }

        // ── REATIVAR DO SUSPENSO: restaurar estado anterior ──
        if ($fromStage === 'suspenso' && $toStage !== 'suspenso') {
            $linkedCaseId = isset($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : 0;
            if ($linkedCaseId) {
                // Restaurar caso para status anterior
                $caseStmt = $pdo->prepare('SELECT coluna_antes_suspensao FROM cases WHERE id = ?');
                $caseStmt->execute(array($linkedCaseId));
                $caseRow = $caseStmt->fetch();
                $statusAnterior = ($caseRow && $caseRow['coluna_antes_suspensao']) ? $caseRow['coluna_antes_suspensao'] : 'em_andamento';
                $pdo->prepare("UPDATE cases SET status=?, coluna_antes_suspensao=NULL, data_suspensao=NULL, prazo_suspensao=NULL, updated_at=NOW() WHERE id=? AND status='suspenso'")
                    ->execute(array($statusAnterior, $linkedCaseId));
                audit_log('case_auto_reactivated', 'case', $linkedCaseId, 'Pipeline reativou lead #' . $leadId . ' → ' . $statusAnterior);
            }
            // Limpar dados de suspensão do lead
            $pdo->prepare('UPDATE pipeline_leads SET coluna_antes_suspensao=NULL, data_suspensao=NULL, prazo_suspensao=NULL WHERE id=?')
                ->execute(array($leadId));
            notify_gestao('Lead reativado!', $lead['name'] . ' saiu do Suspenso.', 'sucesso', url('modules/pipeline/'), '▶️');
        }

        // Labels para flash
        $stageLabels = array('cadastro_preenchido'=>'Cadastro Preenchido','elaboracao_docs'=>'Elaboração Docs','link_enviados'=>'Link Enviados','contrato_assinado'=>'Contrato Assinado','agendado_docs'=>'Agendado + Docs','reuniao_cobranca'=>'Reunião/Cobrança','doc_faltante'=>'Doc Faltante','pasta_apta'=>'Pasta Apta','cancelado'=>'Cancelado','suspenso'=>'Suspenso','perdido'=>'Perdido');
        flash_set('success', 'Lead movido para "' . (isset($stageLabels[$toStage]) ? $stageLabels[$toStage] : $toStage) . '".');
        redirect(module_url('pipeline'));
        break;

    case 'convert':
        // Manter compatibilidade
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
        $stmt->execute(array($leadId));
        $lead = $stmt->fetch();
        if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

        $clientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
        if (!$clientId) {
            $pdo->prepare('INSERT INTO clients (name, phone, email, source, notes, created_by) VALUES (?,?,?,?,?,?)')
                ->execute(array($lead['name'], $lead['phone'], $lead['email'], $lead['source'], 'Convertido do Pipeline', current_user_id()));
            $clientId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE pipeline_leads SET client_id=? WHERE id=?')->execute(array($clientId, $leadId));
        }
        flash_set('success', 'Cliente criado!');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'delete':
        // IMPORTANTE: Remove APENAS o lead da Planilha Comercial (pipeline_leads + pipeline_history).
        // NÃO toca em clients, cases, case_andamentos, asaas_cobrancas, honorarios_cobranca,
        // agenda_eventos, etc. O cliente continua cadastrado no CRM/Operacional/Financeiro.
        if (!can_excluir_lead_pipeline()) _api_fail('Sem permissão — só Amanda ou Luiz Eduardo podem excluir leads.', 403);
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId) {
            $stmtInfo = $pdo->prepare('SELECT name, linked_case_id FROM pipeline_leads WHERE id = ?');
            $stmtInfo->execute(array($leadId));
            $info = $stmtInfo->fetch();
            $nomeLead = $info ? $info['name'] : '(desconhecido)';

            // Só 2 tabelas afetadas — ambas exclusivas da Planilha Comercial:
            $pdo->prepare('DELETE FROM pipeline_history WHERE lead_id = ?')->execute(array($leadId));
            $pdo->prepare('DELETE FROM pipeline_leads WHERE id = ?')->execute(array($leadId));
            audit_log('lead_deleted', 'lead', $leadId, 'Lead "' . $nomeLead . '" removido da Planilha Comercial por ' . current_user_name() . ' (cliente preservado)');
            if ($_isAjax) {
                header('Content-Type: application/json');
                echo json_encode(array('ok' => true));
                exit;
            }
            flash_set('success', 'Lead "' . $nomeLead . '" excluído.');
        }
        redirect(module_url('pipeline'));
        break;

    case 'duplicate':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $dupCaseType = clean_str($_POST['case_type'] ?? '', 60);
        $dupTitulo = clean_str($_POST['titulo'] ?? '', 200);
        if ($leadId) {
            $orig = $pdo->prepare("SELECT * FROM pipeline_leads WHERE id = ?");
            $orig->execute(array($leadId));
            $origLead = $orig->fetch();
            if ($origLead) {
                $clientId = (int)$origLead['client_id'];
                $novoTitulo = $dupTitulo ?: ($origLead['name'] . ' x ' . ($dupCaseType ?: 'Nova Ação'));

                // 1. Criar caso no Operacional (mesma lógica do contrato_assinado)
                $pdo->prepare(
                    "INSERT INTO cases (client_id, title, case_type, status, priority, responsible_user_id, opened_at, notes)
                     VALUES (?,?,?,'aguardando_docs','normal',?,CURDATE(),?)"
                )->execute(array(
                    $clientId, $novoTitulo, $dupCaseType ?: 'outro', $origLead['assigned_to'],
                    'Duplicado de lead #' . $leadId . '. Origem: Pipeline.'
                ));
                $newCaseId = (int)$pdo->lastInsertId();

                // Gerar checklist automática
                if (function_exists('generate_case_checklist')) {
                    generate_case_checklist($newCaseId, $dupCaseType ?: 'outro');
                }

                // Copiar partes da pasta original (se existir)
                if ($origLead['linked_case_id']) {
                    $partes = $pdo->prepare("SELECT * FROM case_partes WHERE case_id = ?");
                    $partes->execute(array($origLead['linked_case_id']));
                    $stmtP = $pdo->prepare("INSERT INTO case_partes (case_id, papel, tipo_pessoa, nome, cpf, rg, nascimento, profissao, estado_civil, email, telefone, endereco, cidade, uf, client_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
                    foreach ($partes->fetchAll() as $pt) {
                        $stmtP->execute(array($newCaseId, $pt['papel'], $pt['tipo_pessoa'], $pt['nome'], $pt['cpf'], $pt['rg'], $pt['nascimento'], $pt['profissao'], $pt['estado_civil'], $pt['email'], $pt['telefone'], $pt['endereco'], $pt['cidade'], $pt['uf'], $pt['client_id']));
                    }
                }

                // Criar pasta no Google Drive
                $driveResult = create_drive_folder($novoTitulo, $dupCaseType ?: 'outro', $newCaseId, $novoTitulo);

                // 2. Criar novo lead no MESMO estágio, vinculado à nova pasta
                $pdo->prepare(
                    "INSERT INTO pipeline_leads (client_id, linked_case_id, name, phone, email, source, stage, case_type, assigned_to, converted_at, notes, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,NOW())"
                )->execute(array(
                    $clientId, $newCaseId,
                    $origLead['name'], $origLead['phone'], $origLead['email'],
                    $origLead['source'], $origLead['stage'],
                    $dupCaseType ?: '', $origLead['assigned_to'],
                    ($dupCaseType ?: 'Nova ação') . ' — duplicado de #' . $leadId
                ));
                $newLeadId = (int)$pdo->lastInsertId();

                $pdo->prepare("INSERT INTO pipeline_history (lead_id, to_stage, changed_by, notes) VALUES (?,?,?,?)")
                    ->execute(array($newLeadId, $origLead['stage'], current_user_id(), 'Duplicado: ' . ($dupCaseType ?: 'nova ação')));

                audit_log('CASE_DUPLICATED', 'case', $newCaseId, 'Duplicado via lead #' . $leadId . ' tipo: ' . $dupCaseType);
                notify_gestao('Nova ação duplicada!', $origLead['name'] . ' — ' . ($dupCaseType ?: 'Nova ação') . '. Pasta + lead criados.', 'sucesso', url('modules/operacional/caso_ver.php?id=' . $newCaseId), '📋');

                flash_set('success', 'Lead + pasta criados! ' . $novoTitulo);
                redirect(module_url('pipeline', 'lead_ver.php?id=' . $newLeadId));
            }
        }
        redirect(module_url('pipeline'));
        break;

    case 'inline_edit':
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
        $allowed = array('name','phone','email','case_type','notes','estimated_value_cents','assigned_to',
            'valor_acao','exito_percentual','vencimento_parcela','forma_pagamento','urgencia','cadastro_asaas','observacoes','nome_pasta','pendencias',
            'data_agendamento','onboard_realizado','origem_lead','converted_at','num_parcelas');
        if (!$leadId) _api_fail('lead_id inválido.');
        if (!in_array($field, $allowed, true)) _api_fail("Campo '{$field}' não autorizado.");

        try {
            if ($field === 'assigned_to') $value = (int)$value ?: null;
            // num_parcelas: 1 a 60 parcelas (clamp)
            if ($field === 'num_parcelas') {
                $value = (int)$value;
                if ($value < 1) $value = 1;
                if ($value > 60) $value = 60;
            }
            // converted_at (data de fechamento do contrato) — aceita YYYY-MM-DD.
            // Preserva hora original do converted_at se já existia; senão usa 12:00:00.
            // NÃO toca em created_at (histórico de criação do lead fica intacto).
            if ($field === 'converted_at') {
                if ($value === '' || $value === null) {
                    _api_fail('Data inválida (vazia).');
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !strtotime($value)) {
                    _api_fail('Data inválida — use o formato AAAA-MM-DD.');
                }
                $stOrig = $pdo->prepare("SELECT converted_at FROM pipeline_leads WHERE id = ?");
                $stOrig->execute(array($leadId));
                $origConv = $stOrig->fetchColumn();
                $horaParte = $origConv ? date('H:i:s', strtotime($origConv)) : '12:00:00';
                $value = $value . ' ' . $horaParte;
            }
            // IMPORTANTE: preserva string "0" e "0,00" (valores financeiros legítimos). Só NULLa se vazio de fato.
            $valueToStore = ($value === '' || $value === null) ? null : $value;
            $pdo->prepare("UPDATE pipeline_leads SET $field = ?, updated_at = NOW() WHERE id = ?")->execute(array($valueToStore, $leadId));
            if ($field === 'valor_acao') { sync_estimated_value($pdo, $leadId, $valueToStore); }
            if ($field === 'onboard_realizado' && $value) {
                $leadAssigned = $pdo->prepare("SELECT assigned_to FROM pipeline_leads WHERE id = ?");
                $leadAssigned->execute(array($leadId));
                $assignTo = (int)$leadAssigned->fetchColumn();
                if ($assignTo) gamificar($assignTo, 'onboarding_realizado', $leadId, 'pipeline_leads');
            }
        } catch (Exception $e) {
            _api_fail('Erro ao salvar: ' . $e->getMessage(), 500);
        }

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(array('ok' => true, 'field' => $field, 'lead_id' => $leadId)); exit; }
        redirect(module_url('pipeline'));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('pipeline'));
}
