<?php
/**
 * Ferreira & Sá Hub — API do Helpdesk
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('helpdesk')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('helpdesk')); }

$action = $_POST['action'] ?? '';
$pdo = db();

// Self-heal: tabela de tracking de processos resumidos por ticket (Amanda 08/06/2026)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_processos_enviados (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT UNSIGNED NOT NULL,
        case_id INT UNSIGNED NOT NULL,
        message_id INT UNSIGNED NULL,
        enviado_por INT UNSIGNED NOT NULL,
        enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tk (ticket_id),
        INDEX idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Self-heal: tabela de anexos dos chamados (print screen / PDF / doc / imagem)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_attachments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT UNSIGNED NOT NULL,
        message_id INT UNSIGNED NULL,
        user_id INT UNSIGNED NOT NULL,
        arquivo_nome VARCHAR(255) NOT NULL,
        arquivo_path VARCHAR(255) NOT NULL,
        arquivo_mime VARCHAR(80) NULL,
        arquivo_tamanho INT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ticket (ticket_id),
        INDEX idx_message (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

switch ($action) {
    case 'toggle_pin':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        if (!$ticketId) { echo json_encode(array('error' => 'ticket_id inválido')); exit; }
        if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido')); exit; }
        try { $pdo->exec("ALTER TABLE tickets ADD COLUMN pinned TINYINT(1) DEFAULT 0 AFTER status"); } catch (Exception $e) {}
        $atual = (int)$pdo->query("SELECT COALESCE(pinned,0) FROM tickets WHERE id = " . $ticketId)->fetchColumn();
        $novo = $atual ? 0 : 1;
        $pdo->prepare("UPDATE tickets SET pinned = ?, updated_at = NOW() WHERE id = ?")->execute(array($novo, $ticketId));
        audit_log('ticket_' . ($novo ? 'pinned' : 'unpinned'), 'ticket', $ticketId, 'Chamado ' . ($novo ? 'fixado' : 'desafixado'));
        echo json_encode(array('ok' => true, 'pinned' => $novo));
        exit;

    case 'update_status':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $category = clean_str($_POST['category'] ?? '', 60);
        $department = clean_str($_POST['department'] ?? '', 60);
        $dueDate = $_POST['due_date'] ?? null;
        if ($dueDate === '') $dueDate = null;

        $validStatuses = ['aberto','em_andamento','aguardando','resolvido','cancelado'];
        $validPriorities = ['baixa','normal','urgente'];

        if ($ticketId && in_array($status, $validStatuses) && in_array($priority, $validPriorities)) {
            // Captura estado anterior pra auditoria de SLA/prioridade (Nilce r7 31/05/2026)
            $_antes = array();
            try {
                $stmtAnt = $pdo->prepare('SELECT status, priority, due_date FROM tickets WHERE id = ?');
                $stmtAnt->execute(array($ticketId));
                $_antes = $stmtAnt->fetch() ?: array();
            } catch (Exception $e) {}

            $resolvedAt = ($status === 'resolvido') ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE tickets SET status=?, priority=?, category=?, department=?, due_date=?, resolved_at=COALESCE(?,resolved_at), updated_at=NOW() WHERE id=?')
                ->execute([$status, $priority, $category ?: null, $department ?: null, $dueDate, $resolvedAt, $ticketId]);

            // Atualizar responsáveis
            $newAssignees = $_POST['assignees'] ?? array();
            $pdo->prepare('DELETE FROM ticket_assignees WHERE ticket_id = ?')->execute(array($ticketId));
            if (!empty($newAssignees)) {
                $stmtA = $pdo->prepare('INSERT INTO ticket_assignees (ticket_id, user_id) VALUES (?, ?)');
                foreach ($newAssignees as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) $stmtA->execute(array($ticketId, $uid));
                }
            }

            // Andamento automático no processo vinculado ao resolver/cancelar
            if (in_array($status, array('resolvido', 'cancelado'))) {
                $stmtT = $pdo->prepare('SELECT title, case_id FROM tickets WHERE id = ?');
                $stmtT->execute(array($ticketId));
                $ticketData = $stmtT->fetch();
                if ($ticketData && $ticketData['case_id']) {
                    $descAndamento = ($status === 'resolvido')
                        ? 'CHAMADO INTERNO CONCLUÍDO - Chamado #' . $ticketId . ': ' . $ticketData['title']
                        : 'CHAMADO INTERNO CANCELADO - Chamado #' . $ticketId . ': ' . $ticketData['title'];
                    try {
                        $pdo->prepare(
                            "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at) VALUES (?,?,?,?,?,0,NOW())"
                        )->execute(array(
                            $ticketData['case_id'],
                            date('Y-m-d'),
                            'chamado',
                            $descAndamento,
                            current_user_id()
                        ));
                    } catch (Exception $e) { /* tabela pode não existir */ }
                }
            }

            // Auditoria detalhada: capta mudancas em status, prioridade E SLA
            $_diffs = array();
            if (isset($_antes['status']) && $_antes['status'] !== $status) $_diffs[] = "status: {$_antes['status']} -> $status";
            if (isset($_antes['priority']) && $_antes['priority'] !== $priority) $_diffs[] = "priority: {$_antes['priority']} -> $priority";
            $_antesSla = $_antes['due_date'] ?? null;
            if ($_antesSla !== $dueDate) $_diffs[] = "SLA: " . ($_antesSla ?: '—') . " -> " . ($dueDate ?: '—');
            $_msg = $_diffs ? implode(' | ', $_diffs) : "status:$status priority:$priority sla:" . ($dueDate ?: '—');
            audit_log('ticket_updated', 'ticket', $ticketId, $_msg);
            flash_set('success', 'Chamado atualizado.');
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    case 'update_links':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        if ($ticketId) {
            $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
            $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
            $clientName = clean_str($_POST['client_name'] ?? '', 150);
            $clientContact = clean_str($_POST['client_contact'] ?? '', 100);
            $caseNumber = clean_str($_POST['case_number'] ?? '', 30);

            $pdo->prepare('UPDATE tickets SET client_id=?, case_id=?, client_name=?, client_contact=?, case_number=?, updated_at=NOW() WHERE id=?')
                ->execute(array($clientId, $caseId, $clientName ?: null, $clientContact ?: null, $caseNumber ?: null, $ticketId));

            audit_log('ticket_links_updated', 'ticket', $ticketId);
            flash_set('success', 'Vínculos atualizados.');
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    case 'add_message':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = clean_str($_POST['message'] ?? '', 5000);

        // Arquivos anexados (printscreen, PDF, doc, etc.) — opcional
        $anexos = array();
        if (!empty($_FILES['anexos']) && is_array($_FILES['anexos']['name'])) {
            $destDir = APP_ROOT . '/files/helpdesk';
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            $allowedMime = array(
                'image/png','image/jpeg','image/gif','image/webp',
                'application/pdf',
                'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
            );
            $total = count($_FILES['anexos']['name']);
            for ($i = 0; $i < $total && $i < 5; $i++) { // máx 5 por msg
                if ((int)$_FILES['anexos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $tmp = $_FILES['anexos']['tmp_name'][$i];
                $nome = $_FILES['anexos']['name'][$i];
                $mime = $_FILES['anexos']['type'][$i] ?: (mime_content_type($tmp) ?: 'application/octet-stream');
                $tam  = (int)$_FILES['anexos']['size'][$i];
                if ($tam > 10 * 1024 * 1024) { flash_set('error','Arquivo "' . $nome . '" maior que 10MB.'); continue; }
                if (!in_array($mime, $allowedMime, true)) { flash_set('error','Formato não permitido: ' . $nome); continue; }
                $nomeSafe = preg_replace('/[^A-Za-z0-9._-]/', '_', $nome);
                $storedName = 'hd_' . $ticketId . '_' . uniqid('', true) . '_' . $nomeSafe;
                $dest = $destDir . '/' . $storedName;
                if (move_uploaded_file($tmp, $dest)) {
                    @chmod($dest, 0644);
                    $anexos[] = array('nome' => $nome, 'path' => $storedName, 'mime' => $mime, 'tamanho' => $tam);
                }
            }
        }

        // Permite enviar mensagem SÓ com anexo (sem texto) ou anexo + texto
        if ($ticketId && ($message !== '' || !empty($anexos))) {
            $msgContent = $message !== '' ? $message : '[' . count($anexos) . ' anexo(s)]';
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, user_id, sender_type, sender_id, message) VALUES (?,?,'equipe',?,?)")
                ->execute([$ticketId, current_user_id(), current_user_id(), $msgContent]);
            $msgId = (int)$pdo->lastInsertId();

            // Amanda 08/06/2026: tracking dos processos incluidos via "Inserir status dos processos"
            // (hidden field cases_incluidos[]). Permite o modal saber quais ja foram enviados nesse chamado.
            $casesInc = $_POST['cases_incluidos'] ?? array();
            if (!is_array($casesInc)) $casesInc = array($casesInc);
            $casesInc = array_values(array_unique(array_filter(array_map('intval', $casesInc), function($v){ return $v > 0; })));
            if (!empty($casesInc)) {
                $stIns = $pdo->prepare("INSERT INTO ticket_processos_enviados (ticket_id, case_id, message_id, enviado_por) VALUES (?,?,?,?)");
                foreach ($casesInc as $cid) {
                    try { $stIns->execute(array($ticketId, $cid, $msgId, current_user_id())); } catch (Exception $e) {}
                }
            }

            // Persiste anexos vinculados à mensagem
            if (!empty($anexos)) {
                $stmtAnexo = $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, message_id, user_id, arquivo_nome, arquivo_path, arquivo_mime, arquivo_tamanho) VALUES (?,?,?,?,?,?,?)");
                foreach ($anexos as $a) {
                    $stmtAnexo->execute(array($ticketId, $msgId, current_user_id(), $a['nome'], $a['path'], $a['mime'], $a['tamanho']));
                }
            }
            // Força $message a ter algo pra não quebrar o fluxo @menções abaixo
            if ($message === '') $message = $msgContent;

            // Auto mudar status para em_andamento se estava aberto
            $stmt = $pdo->prepare('SELECT status, title FROM tickets WHERE id = ?');
            $stmt->execute([$ticketId]);
            $t = $stmt->fetch();
            if ($t && $t['status'] === 'aberto') {
                $pdo->prepare('UPDATE tickets SET status="em_andamento", updated_at=NOW() WHERE id=?')
                    ->execute([$ticketId]);
            }

            // ── @Menções: notificar + enviar e-mail ──
            $ticketTitle = $t ? $t['title'] : 'Chamado #' . $ticketId;
            $senderName = current_user()['name'] ?? 'Alguém';

            // Extrair @PrimeiroNome da mensagem
            if (preg_match_all('/@([A-Za-zÀ-ÿ]+)/', $message, $matches)) {
                $mentionedNames = array_unique($matches[1]);
                $usersAll = $pdo->query("SELECT id, name, email FROM users WHERE is_active = 1")->fetchAll();
                $notifiedIds = array();

                foreach ($mentionedNames as $firstName) {
                    foreach ($usersAll as $u) {
                        $uFirst = explode(' ', $u['name'])[0];
                        if (mb_strtolower($uFirst, 'UTF-8') === mb_strtolower($firstName, 'UTF-8') && !in_array((int)$u['id'], $notifiedIds)) {
                            $uid = (int)$u['id'];
                            // Não notificar a si mesmo
                            if ($uid === current_user_id()) continue;

                            $notifiedIds[] = $uid;
                            $ticketUrl = url('modules/helpdesk/ver.php?id=' . $ticketId);

                            // 1. Notificação interna (sino)
                            notify($uid,
                                'Menção no chamado #' . $ticketId,
                                $senderName . ' mencionou você: "' . mb_substr($message, 0, 120, 'UTF-8') . (mb_strlen($message, 'UTF-8') > 120 ? '...' : '') . '"',
                                'info',
                                $ticketUrl,
                                '💬'
                            );

                            // 2. E-mail via Brevo (transactional)
                            if ($u['email']) {
                                helpdesk_enviar_email_mencao($u, $senderName, $ticketId, $ticketTitle, $message, $ticketUrl);
                            }

                            break; // primeiro match por nome é suficiente
                        }
                    }
                }
            }

            audit_log('ticket_message', 'ticket', $ticketId);

            // Feedback com nomes notificados
            if (!empty($notifiedIds)) {
                $notifNames = array();
                foreach ($notifiedIds as $nid) {
                    foreach ($usersAll as $u) {
                        if ((int)$u['id'] === $nid) { $notifNames[] = explode(' ', $u['name'])[0]; break; }
                    }
                }
                flash_set('success', 'Mensagem enviada. 🔔 ' . implode(', ', $notifNames) . ' foi notificado(a) por e-mail e na plataforma.');
            } else {
                flash_set('success', 'Mensagem enviada.');
            }
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    case 'delete_message':
        $msgId = (int)($_POST['message_id'] ?? 0);
        $ticketId = (int)($_POST['ticket_id'] ?? 0);

        if ($msgId) {
            // Verificar se é o autor ou admin
            $stmt = $pdo->prepare('SELECT user_id FROM ticket_messages WHERE id = ?');
            $stmt->execute(array($msgId));
            $msgRow = $stmt->fetch();

            if ($msgRow && ((int)$msgRow['user_id'] === current_user_id() || has_role('admin'))) {
                $pdo->prepare('DELETE FROM ticket_messages WHERE id = ?')->execute(array($msgId));
                audit_log('ticket_message_deleted', 'ticket', $ticketId, 'Msg #' . $msgId);
                flash_set('success', 'Mensagem apagada.');
            } else {
                flash_set('error', 'Sem permissão para apagar esta mensagem.');
            }
        }
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
        break;

    // ─── Amanda 08/06/2026: cases do cliente + resumo IA + tracking de envio ───
    case 'processos_do_chamado':
        header('Content-Type: application/json; charset=utf-8');
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        if (!$ticketId) { echo json_encode(array('error' => 'ticket_id obrigatório')); exit; }

        $tk = $pdo->prepare("SELECT id, client_id FROM tickets WHERE id = ?");
        $tk->execute(array($ticketId));
        $tkRow = $tk->fetch();
        if (!$tkRow) { echo json_encode(array('error' => 'Chamado não encontrado.')); exit; }
        $clientId = (int)($tkRow['client_id'] ?? 0);
        if (!$clientId) {
            echo json_encode(array('error' => '⚠️ Este chamado ainda não tem cliente vinculado. Vincule o cliente (campo "Cliente") na ficha do chamado pra usar esta função. Sem cliente, o sistema não consegue identificar os processos a listar.'));
            exit;
        }

        $cl = $pdo->prepare("SELECT id, name FROM clients WHERE id = ?");
        $cl->execute(array($clientId));
        $clRow = $cl->fetch();
        if (!$clRow) { echo json_encode(array('error' => 'Cliente não encontrado.')); exit; }

        // Cases ativos do cliente — agora trazemos ia_resumo e ia_resumo_em
        $stCases = $pdo->prepare(
            "SELECT id, title, case_number, court, status, ia_resumo, ia_resumo_em
             FROM cases
             WHERE client_id = ?
               AND status NOT IN ('arquivado','cancelado','concluido')
             ORDER BY updated_at DESC"
        );
        $stCases->execute(array($clientId));
        $cases = $stCases->fetchAll();

        // Último andamento visível ao cliente (fallback quando não tem resumo IA)
        $stUlt = $pdo->prepare(
            "SELECT id, data_andamento, descricao
             FROM case_andamentos
             WHERE case_id = ? AND visivel_cliente = 1
             ORDER BY data_andamento DESC, created_at DESC
             LIMIT 1"
        );

        // Detecta se há andamento novo após o resumo (desatualizado)
        $stMaxAnd = $pdo->prepare("SELECT MAX(created_at) FROM case_andamentos WHERE case_id = ?");

        // Já enviado neste chamado?
        $stEnviado = $pdo->prepare(
            "SELECT MAX(enviado_em) FROM ticket_processos_enviados
             WHERE ticket_id = ? AND case_id = ?"
        );

        $out = array();
        foreach ($cases as $c) {
            $stUlt->execute(array($c['id']));
            $ult = $stUlt->fetch();
            $ultimoAndamento = $ult ? array(
                'id'        => (int)$ult['id'],
                'data'      => $ult['data_andamento'] ? date('d/m/Y', strtotime($ult['data_andamento'])) : '',
                'descricao' => (string)$ult['descricao'],
            ) : null;

            $resumoDesatualizado = false;
            if (!empty($c['ia_resumo']) && !empty($c['ia_resumo_em'])) {
                $stMaxAnd->execute(array($c['id']));
                $maxAnd = (string)$stMaxAnd->fetchColumn();
                if ($maxAnd && strtotime($maxAnd) > strtotime($c['ia_resumo_em'])) {
                    $resumoDesatualizado = true;
                }
            }

            $stEnviado->execute(array($ticketId, $c['id']));
            $envEm = (string)$stEnviado->fetchColumn();

            $out[] = array(
                'id'                  => (int)$c['id'],
                'titulo'              => (string)$c['title'],
                'case_number'         => (string)($c['case_number'] ?? ''),
                'court'               => (string)($c['court'] ?? ''),
                'status'              => (string)($c['status'] ?? ''),
                'ia_resumo'           => !empty($c['ia_resumo']) ? (string)$c['ia_resumo'] : null,
                'ia_resumo_em'        => !empty($c['ia_resumo_em']) ? date('d/m/Y H:i', strtotime($c['ia_resumo_em'])) : null,
                'ia_resumo_desatualizado' => $resumoDesatualizado,
                'ultimo_andamento'    => $ultimoAndamento,
                'ja_enviado_em'       => $envEm ? date('d/m/Y H:i', strtotime($envEm)) : null,
            );
        }
        echo json_encode(array(
            'ok'      => true,
            'client'  => array('id' => $clientId, 'nome' => $clRow['name']),
            'cases'   => $out,
        ));
        exit;

    // ─── Gera resumo IA do caso (uso interno, mesma logica do operacional) ───
    case 'helpdesk_gerar_resumo_caso':
        header('Content-Type: application/json; charset=utf-8');
        $caseId = (int)($_POST['case_id'] ?? 0);
        if (!$caseId) { echo json_encode(array('error' => 'case_id obrigatório')); exit; }

        require_once APP_ROOT . '/core/functions_ia.php';
        $uid = current_user_id();

        // Caso
        $stC = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
        $stC->execute(array($caseId));
        $caso = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$caso) { echo json_encode(array('error' => 'Caso não encontrado.')); exit; }

        // Contexto: ultimos 30 andamentos + tarefas + docs pendentes (mesma logica do operacional)
        $stAnd = $pdo->prepare(
            "SELECT data_andamento, hora_andamento, tipo, descricao
             FROM case_andamentos WHERE case_id = ? ORDER BY data_andamento DESC, id DESC LIMIT 30"
        );
        $stAnd->execute(array($caseId));
        $ands = $stAnd->fetchAll(PDO::FETCH_ASSOC);

        $stTar = $pdo->prepare(
            "SELECT title, due_date FROM case_tasks
             WHERE case_id = ? AND tipo IS NOT NULL AND status != 'concluido' ORDER BY due_date ASC LIMIT 20"
        );
        $stTar->execute(array($caseId));
        $tarefas = $stTar->fetchAll(PDO::FETCH_ASSOC);

        $stDoc = $pdo->prepare(
            "SELECT descricao FROM documentos_pendentes WHERE case_id = ? AND status = 'pendente' ORDER BY id"
        );
        $stDoc->execute(array($caseId));
        $docsP = $stDoc->fetchAll(PDO::FETCH_COLUMN);

        $ctx = "PROCESSO: " . (($caso['title'] ?? '') ?: 'sem título') . "\n";
        if (!empty($caso['case_number'])) $ctx .= "CNJ: " . $caso['case_number'] . "\n";
        $ctx .= "Tipo: " . (($caso['case_type'] ?? '') ?: '—') . " | Status: " . (($caso['status'] ?? '') ?: '—') . "\n\n";
        $ctx .= "ANDAMENTOS (mais recentes primeiro):\n";
        if (!$ands) $ctx .= "  (sem andamentos registrados)\n";
        foreach ($ands as $a) {
            $dt = $a['data_andamento'] ? date('d/m/Y', strtotime($a['data_andamento'])) : '';
            $tx = trim(preg_replace('/\s+/', ' ', (string)$a['descricao']));
            if (mb_strlen($tx) > 250) $tx = mb_substr($tx,0,250) . '…';
            $ctx .= "  • {$dt} [{$a['tipo']}] {$tx}\n";
        }
        $ctx .= "\nTAREFAS PENDENTES:\n";
        if (!$tarefas) $ctx .= "  (nenhuma)\n";
        foreach ($tarefas as $t) {
            $dl = $t['due_date'] ? ' (até ' . date('d/m', strtotime($t['due_date'])) . ')' : '';
            $ctx .= "  • {$t['title']}{$dl}\n";
        }
        $ctx .= "\nDOCUMENTOS FALTANTES:\n";
        if (!$docsP) $ctx .= "  (nenhum)\n";
        foreach ($docsP as $d) $ctx .= "  • {$d}\n";

        $system = "Você é uma assistente jurídica do escritório Ferreira & Sá Advocacia. "
                . "Vai receber o estado de um processo (andamentos recentes, tarefas, documentos) "
                . "e deve produzir um RESUMO EXECUTIVO em 4 parágrafos curtos, na seguinte ordem:\n"
                . "1. **Situação atual**: onde o processo está hoje (1 frase direta).\n"
                . "2. **Último movimento relevante**: o que mais importa nos últimos andamentos (1-2 frases).\n"
                . "3. **Próximo passo previsto**: o que esperar ou o que o escritório precisa fazer (1-2 frases).\n"
                . "4. **Alertas**: prazo crítico, documento pendente, ponto de atenção (1 frase OU 'Nenhum alerta no momento').\n\n"
                . "REGRAS:\n"
                . "- Linguagem objetiva, jurídica mas clara, em português brasileiro.\n"
                . "- NÃO invente fatos que não estão nos andamentos.\n"
                . "- Use markdown (**negrito** nos rótulos como acima).\n"
                . "- Total: no máximo 12 linhas. Cada parágrafo: máximo 2 frases.";

        $r = ia_chamar(
            'resumo_caso',
            'claude-haiku-4-5',
            $system,
            array(array('role' => 'user', 'content' => $ctx)),
            array(
                'user_id'      => $uid,
                'max_tokens'   => 600,
                'temperature'  => 0.2,
                'contexto'     => 'case#' . $caseId . ' (via helpdesk)',
                'cache_system' => true,
                'bypass_user_whitelist' => true,  // uso interno autorizado pela Amanda
                'bypass_killswitch'     => true,  // helpdesk respondendo cliente
            )
        );

        if (!$r['ok']) {
            echo json_encode(array('error' => $r['erro'] ?: 'Falha na IA'));
            exit;
        }

        $pdo->prepare("UPDATE cases SET ia_resumo = ?, ia_resumo_em = NOW() WHERE id = ?")
            ->execute(array($r['texto'], $caseId));

        audit_log('IA_RESUMO_CASO', 'case', $caseId, 'via helpdesk tokens=' . $r['input_tokens'] . '/' . $r['output_tokens'] . ' R$' . $r['custo_brl']);

        echo json_encode(array(
            'ok'        => true,
            'texto'     => $r['texto'],
            'em'        => date('d/m/Y H:i'),
            'custo_brl' => $r['custo_brl'],
        ));
        exit;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('helpdesk'));
}

// ── Helper: enviar e-mail de menção via Brevo ──
function helpdesk_enviar_email_mencao($user, $senderName, $ticketId, $ticketTitle, $message, $ticketUrl) {
    try {
        $pdo = db();
        $cfg = array('key' => '', 'email' => 'contato@ferreiraesa.com.br', 'name' => 'Ferreira & Sá Advocacia');
        $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'brevo_api_key') $cfg['key'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_name') $cfg['name'] = $r['valor'];
        }
        if (!$cfg['key']) return; // Sem Brevo configurado

        $firstName = explode(' ', $user['name'])[0];
        $msgPreview = mb_substr(strip_tags($message), 0, 300, 'UTF-8');
        $msgPreview = nl2br(htmlspecialchars($msgPreview, ENT_QUOTES, 'UTF-8'));

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:#052228;padding:20px 24px;">
        <h1 style="color:#fff;font-size:16px;margin:0;">💬 Você foi mencionado(a) em um chamado</h1>
    </div>
    <div style="padding:24px;">
        <p style="font-size:14px;color:#374151;margin:0 0 16px;">
            Olá, <strong>' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '</strong>!
        </p>
        <p style="font-size:14px;color:#374151;margin:0 0 16px;">
            <strong>' . htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') . '</strong> mencionou você no chamado <strong>#' . $ticketId . ' — ' . htmlspecialchars($ticketTitle, ENT_QUOTES, 'UTF-8') . '</strong>:
        </p>
        <div style="background:#f0f4ff;border-left:4px solid #3B4FA0;padding:12px 16px;border-radius:0 8px 8px 0;margin:0 0 20px;font-size:14px;color:#1e3a5f;line-height:1.6;">
            ' . $msgPreview . '
        </div>
        <a href="' . htmlspecialchars($ticketUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#052228;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">
            Ver Chamado →
        </a>
    </div>
    <div style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#9ca3af;text-align:center;">
        Ferreira & Sá Advocacia — Conecta Hub
    </div>
</div>
</body></html>';

        $data = array(
            'sender' => array('name' => $cfg['name'], 'email' => $cfg['email']),
            'to' => array(array('email' => $user['email'], 'name' => $user['name'])),
            'subject' => '💬 ' . $senderName . ' mencionou você no chamado #' . $ticketId,
            'htmlContent' => $html,
        );

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        // Silenciar erros de e-mail para não bloquear a mensagem
    }
}
