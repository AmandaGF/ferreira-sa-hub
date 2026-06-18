<?php
/**
 * Ferreira & Sá Hub — API do CRM
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_salavip_email.php';
require_access('crm');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('crm')); }
if (!validate_csrf()) {
    flash_set('error', 'Token inválido.');
    redirect(module_url('crm'));
}

$action = $_POST['action'] ?? '';
$pdo = db();

switch ($action) {
    case 'add_contact':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $type = $_POST['type'] ?? 'nota';
        $summary = clean_str($_POST['summary'] ?? '', 2000);
        $contactedAt = $_POST['contacted_at'] ?? date('Y-m-d H:i:s');

        if (!$clientId || empty($summary)) {
            flash_set('error', 'Preencha o resumo.');
            redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        }

        $validTypes = ['whatsapp', 'telefone', 'email', 'presencial', 'reuniao', 'nota'];
        if (!in_array($type, $validTypes)) $type = 'nota';

        $pdo->prepare(
            'INSERT INTO contacts (client_id, type, summary, contacted_by, contacted_at) VALUES (?,?,?,?,?)'
        )->execute([$clientId, $type, $summary, current_user_id(), $contactedAt]);

        audit_log('contact_added', 'client', $clientId);
        flash_set('success', 'Contato registrado.');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'add_case':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $title = clean_str($_POST['title'] ?? '', 200);
        $caseType = $_POST['case_type'] ?? 'outro';
        $caseNumber = clean_str($_POST['case_number'] ?? '', 30);
        $court = clean_str($_POST['court'] ?? '', 150);
        $priority = $_POST['priority'] ?? 'normal';
        $responsibleId = (int)($_POST['responsible_user_id'] ?? 0) ?: null;
        $deadline = $_POST['deadline'] ?? null;
        $notes = clean_str($_POST['notes'] ?? '', 2000);

        if (!$clientId || empty($title)) {
            flash_set('error', 'Título é obrigatório.');
            redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        }

        if ($deadline === '') $deadline = null;

        $pdo->prepare(
            'INSERT INTO cases (client_id, title, case_type, case_number, court, priority, responsible_user_id, deadline, notes, opened_at)
             VALUES (?,?,?,?,?,?,?,?,?,CURDATE())'
        )->execute([$clientId, $title, $caseType, $caseNumber ?: null, $court ?: null, $priority, $responsibleId, $deadline, $notes ?: null]);

        $newId = (int)$pdo->lastInsertId();
        generate_case_checklist($newId, $caseType);
        audit_log('case_created', 'case', $newId);
        flash_set('success', 'Caso criado com checklist automático.');
        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
        break;

    case 'update_senha_gov':
        // Save AJAX da senha gov.br do cliente (campo único da Sprint 31)
        header('Content-Type: application/json; charset=utf-8');
        $clientId = (int)($_POST['client_id'] ?? 0);
        $senha = trim($_POST['senha_gov'] ?? '');
        if ($clientId <= 0) { echo json_encode(array('ok' => false, 'erro' => 'client_id inválido')); exit; }
        // Self-heal da coluna
        try { $pdo->exec("ALTER TABLE clients ADD COLUMN senha_gov VARCHAR(100) NULL"); } catch (Exception $e) {}
        $pdo->prepare('UPDATE clients SET senha_gov = ?, updated_at = NOW() WHERE id = ?')
            ->execute(array($senha === '' ? null : $senha, $clientId));
        audit_log('senha_gov_atualizada', 'client', $clientId);
        echo json_encode(array('ok' => true, 'preenchida' => $senha !== ''));
        exit;

    case 'update_client_status':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $newStatus = $_POST['client_status'] ?? '';
        $validStatuses = array('ativo', 'inativo', 'contrato_assinado', 'cancelou', 'parou_responder', 'demitido', 'prospect');

        if ($clientId && in_array($newStatus, $validStatuses)) {
            $pdo->prepare('UPDATE clients SET client_status = ?, updated_at = NOW() WHERE id = ?')
                ->execute(array($newStatus, $clientId));

            audit_log('client_status_changed', 'client', $clientId, $newStatus);

            // Se CONTRATO ASSINADO → criar caso no Operacional
            if ($newStatus === 'contrato_assinado') {
                // Buscar dados do cliente
                $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
                $stmt->execute(array($clientId));
                $cl = $stmt->fetch();

                if ($cl) {
                    // Verificar se já tem caso ativo
                    $existCase = $pdo->prepare("SELECT id FROM cases WHERE client_id = ? AND status NOT IN ('concluido','arquivado') LIMIT 1");
                    $existCase->execute(array($clientId));

                    if (!$existCase->fetch()) {
                        // Criar caso
                        $pdo->prepare(
                            "INSERT INTO cases (client_id, title, case_type, status, priority, opened_at, notes)
                             VALUES (?, ?, 'outro', 'aguardando_docs', 'normal', CURDATE(), ?)"
                        )->execute(array(
                            $clientId,
                            'Novo caso — ' . $cl['name'],
                            'Contrato assinado em ' . date('d/m/Y') . '. Aguardando documentação.'
                        ));
                        $caseId = (int)$pdo->lastInsertId();
                        generate_case_checklist($caseId, 'outro');
                        audit_log('case_auto_created', 'case', $caseId, 'Contrato assinado - client: ' . $clientId);

                        flash_set('success', 'Status atualizado para "Contrato Assinado" e caso criado no Operacional (#' . $caseId . ')!');
                        redirect(module_url('crm', 'cliente_ver.php?id=' . $clientId));
                    }
                }
            }

            $statusLabels = array('ativo'=>'Ativo', 'contrato_assinado'=>'Contrato Assinado', 'cancelou'=>'Cancelou', 'parou_responder'=>'Parou de Responder', 'demitido'=>'Demitimos');

            // Notificações por status
            $cliStmt = $pdo->prepare('SELECT name FROM clients WHERE id = ?');
            $cliStmt->execute(array($clientId));
            $cliRow = $cliStmt->fetch();
            $cliName = $cliRow ? $cliRow['name'] : 'Cliente';

            if ($newStatus === 'contrato_assinado') {
                notify_gestao('Contrato assinado!', $cliName . ' teve contrato assinado.', 'sucesso', url('modules/crm/cliente_ver.php?id=' . $clientId), '✅');
            } elseif ($newStatus === 'cancelou') {
                notify_gestao('Cliente cancelou', $cliName . ' cancelou o serviço.', 'alerta', url('modules/crm/cliente_ver.php?id=' . $clientId), '⚠️');
            }

            flash_set('success', 'Status alterado para "' . ($statusLabels[$newStatus] ?? $newStatus) . '".');
        }
        // Tentar redirect para a ficha do cliente (ver.php na pasta clientes)
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, 'clientes/ver.php') !== false) {
            redirect(url('modules/clientes/ver.php?id=' . $clientId));
        } else {
            redirect(module_url('crm'));
        }
        break;

    case 'remove_from_crm':
        // Apenas arquiva os formulários — NÃO apaga o cadastro do cliente
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId) {
            $pdo->prepare("UPDATE form_submissions SET status = 'arquivado' WHERE linked_client_id = ?")
                ->execute(array($clientId));
            audit_log('client_removed_from_crm', 'client', $clientId);
            flash_set('success', 'Cliente removido do CRM. O cadastro do contato foi mantido.');
        }
        redirect(module_url('crm'));
        break;

    case 'delete_client':
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId) {
            // Apagar leads do pipeline vinculados
            $pdo->prepare('DELETE FROM pipeline_history WHERE lead_id IN (SELECT id FROM pipeline_leads WHERE client_id = ?)')->execute(array($clientId));
            $pdo->prepare('DELETE FROM pipeline_leads WHERE client_id = ?')->execute(array($clientId));
            // Apagar contatos
            $pdo->prepare('DELETE FROM contacts WHERE client_id = ?')->execute(array($clientId));
            // Apagar tarefas dos casos
            $pdo->prepare('DELETE FROM case_tasks WHERE case_id IN (SELECT id FROM cases WHERE client_id = ?)')->execute(array($clientId));
            // Apagar casos
            $pdo->prepare('DELETE FROM cases WHERE client_id = ?')->execute(array($clientId));
            // Desvincular formulários (não apagar, só desvincular)
            $pdo->prepare('UPDATE form_submissions SET linked_client_id = NULL WHERE linked_client_id = ?')->execute(array($clientId));
            // Apagar cliente
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute(array($clientId));
            audit_log('client_deleted', 'client', $clientId);
            flash_set('success', 'Cliente e dados relacionados excluídos.');
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        if (strpos($referer, 'clientes') !== false) {
            redirect(module_url('clientes'));
        } else {
            redirect(module_url('crm'));
        }
        break;

    case 'criar_salavip':
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId && has_min_role('gestao')) {
            $cl = $pdo->prepare('SELECT id, name, cpf, email FROM clients WHERE id = ?');
            $cl->execute(array($clientId));
            $cli = $cl->fetch();
            if ($cli) {
                $cpfClean = preg_replace('/\D/', '', $cli['cpf'] ?? '');
                if (strlen($cpfClean) < 11) {
                    flash_set('error', 'Cliente não possui CPF válido cadastrado. Cadastre o CPF antes de criar o acesso.');
                    redirect(module_url('clientes', 'ver.php?id=' . $clientId));
                    break;
                }
                // Verificar se já existe
                $chk = $pdo->prepare('SELECT id FROM salavip_usuarios WHERE cliente_id = ?');
                $chk->execute(array($clientId));
                if ($chk->fetch()) {
                    flash_set('warning', 'Este cliente já possui acesso à Central VIP.');
                    redirect(module_url('clientes', 'ver.php?id=' . $clientId));
                    break;
                }
                $token = bin2hex(random_bytes(32));
                $expira = date('Y-m-d H:i:s', strtotime('+72 hours'));
                $cpfFormatado = substr($cpfClean, 0, 3) . '.' . substr($cpfClean, 3, 3) . '.' . substr($cpfClean, 6, 3) . '-' . substr($cpfClean, 9, 2);
                $senhaTemp = password_hash($cpfClean, PASSWORD_DEFAULT);
                $pdo->prepare(
                    'INSERT INTO salavip_usuarios (cliente_id, cpf, senha_hash, email, nome_exibicao, ativo, token_ativacao, token_expira, criado_por)
                     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)'
                )->execute(array(
                    $clientId, $cpfFormatado, $senhaTemp,
                    $cli['email'] ?: '', $cli['name'], $token, $expira, current_user_id()
                ));
                audit_log('criar_salavip', 'client', $clientId, 'Token: ' . substr($token, 0, 8) . '...');

                // Enviar e-mail de ativação via Brevo
                $linkAtivacao = 'https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=' . $token;
                _salavip_enviar_email_ativacao($cli['email'], $cli['name'], $linkAtivacao);

                flash_set('success', 'Acesso Central VIP criado! E-mail de ativação enviado para ' . $cli['email'] . '.');
            }
        }
        redirect(module_url('clientes', 'ver.php?id=' . $clientId));
        break;

    case 'reset_salavip':
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId && has_min_role('gestao')) {
            // FIX CRITICO Amanda 10/06/2026: nunca zerar ativo se cliente ja
            // cadastrou senha — caso da Renata da Silva (cliente_id=2412) que
            // logou 12:08 e foi bloqueada indevidamente 12:15 por alguem
            // clicando 'Reenviar Link' aqui. Pra quem ja ativou, regenera
            // token mas mantem ativo=1 e oferece link de recuperar senha.
            $usrAtual = $pdo->prepare("SELECT id, ativo, senha_hash FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
            $usrAtual->execute(array($clientId));
            $svInfo = $usrAtual->fetch();
            $jaTemSenha = $svInfo && !empty($svInfo['senha_hash']);

            $token = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', strtotime('+72 hours'));

            if ($jaTemSenha) {
                // Mantem ativo=1, regenera token (pode ser usado em "recuperar senha")
                $pdo->prepare(
                    'UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ? WHERE cliente_id = ?'
                )->execute(array($token, $expira, $clientId));
                audit_log('reset_salavip_senha', 'client', $clientId, 'Cliente ja ativo — token de recuperacao gerado: ' . substr($token, 0, 8) . '...');
            } else {
                // Cadastro novo / ainda nao ativou — fluxo original
                $pdo->prepare(
                    'UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ?, ativo = 0 WHERE cliente_id = ?'
                )->execute(array($token, $expira, $clientId));
                audit_log('reset_salavip', 'client', $clientId, 'Novo token de ativacao: ' . substr($token, 0, 8) . '...');
            }

            // Reenviar e-mail
            $cl2 = $pdo->prepare('SELECT name, email FROM clients WHERE id = ?');
            $cl2->execute(array($clientId));
            $cli2 = $cl2->fetch();
            if ($cli2 && $cli2['email']) {
                $linkAtivacao = 'https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=' . $token;
                _salavip_enviar_email_ativacao($cli2['email'], $cli2['name'], $linkAtivacao);
            }

            $msg = $jaTemSenha
                ? 'Cliente JÁ tinha conta ativa — link enviado por e-mail (pode ser usado pra trocar de senha). Acesso continua liberado.'
                : 'Link de ativação reenviado por e-mail (válido por 72h).';
            flash_set('success', $msg);
        }
        redirect(module_url('clientes', 'ver.php?id=' . $clientId));
        break;

    case 'toggle_salavip':
        // Habilita/desabilita o acesso do cliente à Central VIP (flag ativo).
        // Desabilitar NÃO apaga a conta nem a senha — só bloqueia o login.
        // Reabilitar devolve o acesso com a mesma senha que o cliente já tinha.
        $clientId = (int)($_POST['client_id'] ?? 0);
        $novoAtivo = (int)($_POST['ativo'] ?? 0) ? 1 : 0;
        if ($clientId && has_min_role('gestao')) {
            $sv = $pdo->prepare("SELECT id FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
            $sv->execute(array($clientId));
            if ($sv->fetch()) {
                $pdo->prepare('UPDATE salavip_usuarios SET ativo = ? WHERE cliente_id = ?')
                    ->execute(array($novoAtivo, $clientId));
                if ($novoAtivo) {
                    audit_log('reativar_salavip', 'client', $clientId, 'Acesso à Central VIP reabilitado');
                    flash_set('success', 'Acesso à Central VIP reabilitado. O cliente pode entrar com a senha de antes.');
                } else {
                    audit_log('desativar_salavip', 'client', $clientId, 'Acesso à Central VIP desabilitado');
                    flash_set('success', 'Acesso à Central VIP desabilitado. O cliente não consegue mais entrar (a conta e a senha foram preservadas).');
                }
            } else {
                flash_set('error', 'Cliente não possui conta na Central VIP.');
            }
        }
        redirect(module_url('clientes', 'ver.php?id=' . $clientId));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('crm'));
}

