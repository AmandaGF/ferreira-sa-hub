<?php
/**
 * Pesquisa GERID — vínculo empregatício.
 *
 * Pedido pra pesquisar no GERID/INSS Digital se uma parte (pai/mãe) possui
 * vínculo empregatício (útil pra direcionar pensão alimentícia ao empregador).
 * Pode ser criado daqui ou pelo botão na pasta do processo (caso_ver).
 * Ao criar: avisa o Luiz Eduardo + abre tarefa na pasta. Resultado é registrado aqui.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('gerid');

$pdo = db();
$pageTitle = 'Pesquisa GERID — Vínculo';

// Self-heal
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gerid_pesquisas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NULL, client_id INT UNSIGNED NULL,
        parte_nome VARCHAR(160) NOT NULL, parte_cpf VARCHAR(20) NULL, parente ENUM('pai','mae','outro') NULL,
        observacao TEXT NULL, status ENUM('pendente','concluida') NOT NULL DEFAULT 'pendente',
        tem_vinculo TINYINT(1) NULL, resultado TEXT NULL, task_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        pesquisado_por INT UNSIGNED NULL, pesquisado_em DATETIME NULL,
        INDEX idx_status (status), INDEX idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
// Self-heal 29/06/2026: printscreen do GERID/INSS que o Luiz anexa ao concluir
// + colunas de tracking de tratamento pela Amanda (29/06 r2)
foreach (array(
    "ALTER TABLE gerid_pesquisas ADD COLUMN printscreen_nome VARCHAR(255) NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN printscreen_path VARCHAR(255) NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN printscreen_mime VARCHAR(80) NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN tratado_em DATETIME NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN tratado_por INT UNSIGNED NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN task_review_id INT UNSIGNED NULL",
) as $_sqlAlter) { try { $pdo->exec($_sqlAlter); } catch (Exception $e) {} }

// Download autenticado do printscreen
if (isset($_GET['baixar'])) {
    $id = (int)$_GET['baixar'];
    $st = $pdo->prepare("SELECT printscreen_path, printscreen_nome, printscreen_mime FROM gerid_pesquisas WHERE id=?");
    $st->execute(array($id));
    $row = $st->fetch();
    $path = $row && $row['printscreen_path'] ? APP_ROOT . '/files/gerid/' . basename($row['printscreen_path']) : '';
    if (!$path || !is_file($path)) { http_response_code(404); die('Arquivo não encontrado.'); }
    header('Content-Type: ' . ($row['printscreen_mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/', '_', $row['printscreen_nome'] ?: 'gerid_print') . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path); exit;
}

/** id do Luiz Eduardo (quem faz a pesquisa no GERID). */
function gerid_luiz_id($pdo) {
    try {
        $id = $pdo->query("SELECT id FROM users WHERE is_active=1 AND name LIKE 'Luiz Eduardo%' ORDER BY id LIMIT 1")->fetchColumn();
        return $id ? (int)$id : 0;
    } catch (Exception $e) { return 0; }
}

// AJAX: busca cliente (form avulso)
if (($_GET['ajax'] ?? '') === 'buscar_cliente') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? ''); if (mb_strlen($q) < 2) { echo '[]'; exit; }
    $st = $pdo->prepare("SELECT id, name, cpf FROM clients WHERE name LIKE ? ORDER BY name LIMIT 15");
    $st->execute(array('%' . $q . '%'));
    echo json_encode($st->fetchAll()); exit;
}

// Amanda 09/07/2026: AJAX — historico de pesquisas GERID pra um CPF (dedup cross-case)
if (($_GET['ajax'] ?? '') === 'historico_cpf') {
    header('Content-Type: application/json; charset=utf-8');
    $cpfDig = preg_replace('/\D/', '', $_GET['cpf'] ?? '');
    if (strlen($cpfDig) < 11) { echo '[]'; exit; }
    $excluirCaseId = (int)($_GET['case_id'] ?? 0);
    $st = $pdo->prepare(
        "SELECT g.id, g.case_id, g.parte_nome, g.parte_cpf, g.status, g.tem_vinculo, g.resultado,
                g.printscreen_path, g.created_at,
                c.title AS case_title, c.case_number,
                cl.name AS case_client_name
         FROM gerid_pesquisas g
         LEFT JOIN cases c    ON c.id  = g.case_id
         LEFT JOIN clients cl ON cl.id = c.client_id
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(g.parte_cpf,'.',''),'-',''),'/',''),' ','') = ?
           AND (? = 0 OR g.case_id IS NULL OR g.case_id <> ?)
         ORDER BY g.created_at DESC
         LIMIT 5"
    );
    $st->execute(array($cpfDig, $excluirCaseId, $excluirCaseId));
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Endpoint AJAX leve: marcar/desmarcar tratado (resposta JSON, sem redirect)
    if (($_POST['acao'] ?? '') === 'toggle_tratado') {
        header('Content-Type: application/json; charset=utf-8');
        if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF.')); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(array('ok' => false, 'erro' => 'id.')); exit; }
        $st = $pdo->prepare("SELECT tratado_em, task_review_id FROM gerid_pesquisas WHERE id = ?");
        $st->execute(array($id));
        $row = $st->fetch();
        if (!$row) { echo json_encode(array('ok' => false, 'erro' => 'não encontrado.')); exit; }
        if ($row['tratado_em']) {
            // Estava tratado → volta pra não-tratado
            $pdo->prepare("UPDATE gerid_pesquisas SET tratado_em = NULL, tratado_por = NULL WHERE id = ?")
                ->execute(array($id));
            $tratado = false;
        } else {
            // Estava não-tratado → marca tratado
            $pdo->prepare("UPDATE gerid_pesquisas SET tratado_em = NOW(), tratado_por = ? WHERE id = ?")
                ->execute(array(current_user_id(), $id));
            // Fecha a tarefa de review se existir
            if (!empty($row['task_review_id'])) {
                try { $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=NOW() WHERE id=?")->execute(array((int)$row['task_review_id'])); } catch (Exception $e) {}
            }
            $tratado = true;
        }
        audit_log('gerid_toggle_tratado', 'gerid', $id, $tratado ? 'tratado' : 'destratado');
        echo json_encode(array('ok' => true, 'tratado' => $tratado, 'em' => $tratado ? date('d/m/Y H:i') : null));
        exit;
    }

    if (!validate_csrf()) { flash_set('error', 'Sessão expirada.'); redirect(module_url('gerid')); }
    $acao = $_POST['acao'] ?? '';

    // 29/06/2026 Amanda: excluir pesquisa (duplicada ou erro). Marca task vinculada
    // como cancelada, fecha task_review se houver, registra andamento de remoção.
    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { flash_set('error', 'ID inválido.'); redirect(module_url('gerid')); }
        $g = $pdo->prepare("SELECT * FROM gerid_pesquisas WHERE id=?");
        $g->execute(array($id));
        $row = $g->fetch();
        if (!$row) { flash_set('error', 'Pesquisa não encontrada.'); redirect(module_url('gerid')); }

        // Permissão: criador, Luiz Eduardo (pesquisador), admin ou gestão
        $userId = current_user_id();
        $role = current_user_role();
        $podeExcluir = in_array($role, array('admin','gestao'), true)
                    || (int)$row['created_by'] === $userId
                    || (int)$row['pesquisado_por'] === $userId;
        if (!$podeExcluir) { flash_set('error', 'Sem permissão pra excluir esta pesquisa.'); redirect(module_url('gerid')); }

        // Cancela tarefas vinculadas (pesquisa + review)
        try {
            if (!empty($row['task_id'])) {
                $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=NOW(), descricao=CONCAT(IFNULL(descricao,''), '\n\n[CANCELADA: pesquisa GERID excluída]') WHERE id=?")
                    ->execute(array((int)$row['task_id']));
            }
            if (!empty($row['task_review_id'])) {
                $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=NOW(), descricao=CONCAT(IFNULL(descricao,''), '\n\n[CANCELADA: pesquisa GERID excluída]') WHERE id=?")
                    ->execute(array((int)$row['task_review_id']));
            }
        } catch (Exception $e) {}

        // Andamento de remoção
        if (!empty($row['case_id'])) {
            try {
                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at)
                     VALUES (?, ?, 'gerid', ?, ?, 0, NOW())"
                )->execute(array(
                    (int)$row['case_id'], date('Y-m-d'),
                    '🗑️ Pesquisa GERID de ' . $row['parte_nome'] . ' excluída'
                    . ($row['status'] === 'concluida' ? ' (já estava concluída)' : ' (estava pendente)') . '.',
                    $userId,
                ));
            } catch (Exception $e) {}
        }

        // Remove printscreen do disco se houver
        if (!empty($row['printscreen_path'])) {
            $f = APP_ROOT . '/files/gerid/' . basename($row['printscreen_path']);
            if (is_file($f)) @unlink($f);
        }

        $pdo->prepare("DELETE FROM gerid_pesquisas WHERE id=?")->execute(array($id));
        audit_log('gerid_excluir', 'gerid', $id, $row['parte_nome']);
        flash_set('success', 'Pesquisa GERID excluída.');
        redirect(module_url('gerid'));
    }

    if ($acao === 'solicitar') {
        $parteNome = clean_str($_POST['parte_nome'] ?? '', 160);
        $parteCpf  = clean_str($_POST['parte_cpf'] ?? '', 20);
        $parente   = in_array(($_POST['parente'] ?? ''), array('pai','mae','outro'), true) ? $_POST['parente'] : null;
        $obs       = clean_str($_POST['observacao'] ?? '', 2000);
        $caseId    = (int)($_POST['case_id'] ?? 0) ?: null;
        $clientId  = (int)($_POST['client_id'] ?? 0) ?: null;
        if ($parteNome === '') { flash_set('error', 'Informe o nome completo da parte a pesquisar.'); redirect(module_url('gerid')); }

        $pdo->prepare("INSERT INTO gerid_pesquisas (case_id, client_id, parte_nome, parte_cpf, parente, observacao, created_by)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute(array($caseId, $clientId, $parteNome, $parteCpf ?: null, $parente, $obs ?: null, current_user_id()));
        $pesqId = (int)$pdo->lastInsertId();

        // referência do processo (pra mensagem/tarefa)
        $procRef = '';
        if ($caseId) {
            try { $c = $pdo->prepare("SELECT case_number, title, responsible_user_id FROM cases WHERE id=?"); $c->execute(array($caseId)); $cr = $c->fetch(); if ($cr) $procRef = $cr['case_number'] ?: $cr['title']; } catch (Exception $e) {}
        }
        $corpo = 'Pesquisar vínculo de ' . $parteNome . ($parteCpf ? ' (CPF ' . $parteCpf . ')' : '') . ($parente ? ' [' . $parente . ']' : '') . ($procRef ? ' — processo ' . $procRef : '') . '.';

        // avisa o Luiz Eduardo (ou gestão se não achar)
        $luiz = gerid_luiz_id($pdo);
        $titulo = '🔎 Pesquisa GERID de vínculo';
        if ($luiz) {
            notify($luiz, $titulo, $corpo, 'pendencia', url('modules/gerid/'), '🔎');
            if (function_exists('push_notify')) { try { push_notify($luiz, $titulo, $corpo, '/conecta/modules/gerid/', false); } catch (Exception $e) {} }
        } elseif (function_exists('notify_gestao')) {
            notify_gestao($titulo, $corpo, 'pendencia', url('modules/gerid/'), '🔎');
        }

        // tarefa na pasta do processo
        if ($caseId) {
            $pdo->prepare("INSERT INTO case_tasks (case_id, title, tipo, descricao, assigned_to, due_date, prioridade, status, sort_order, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                ->execute(array($caseId, '🔎 Pesquisar vínculo no GERID — ' . $parteNome, 'outros',
                                $corpo . ' Verificar se possui vínculo empregatício e qual o empregador.',
                                $luiz ?: null, date('Y-m-d', strtotime('+2 days')), 'alta', 'a_fazer', 0));
            $taskId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE gerid_pesquisas SET task_id=? WHERE id=?")->execute(array($taskId, $pesqId));

            // 29/06/2026 Amanda: andamento INTERNO (visivel_cliente=0) registrando o
            // pedido, pra equipe ver na linha do tempo do processo e nao solicitar de
            // novo sem querer (caso Wallace Renan: 2 pedidos iguais no mesmo dia).
            try {
                $descAnd = '🔎 Solicitada pesquisa GERID de vínculo empregatício de ' . $parteNome
                         . ($parteCpf ? ' (CPF ' . $parteCpf . ')' : '')
                         . ($parente ? ' [' . $parente . ']' : '')
                         . '. Luiz Eduardo notificado'
                         . ($obs ? '. Obs: ' . $obs : '') . '.';
                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at)
                     VALUES (?, ?, 'gerid', ?, ?, 0, NOW())"
                )->execute(array($caseId, date('Y-m-d'), $descAnd, current_user_id()));
            } catch (Exception $e) {}
        }
        audit_log('gerid_solicitar', 'gerid', $pesqId, $parteNome);

        flash_set('success', 'Pedido de pesquisa GERID registrado. O Luiz Eduardo foi avisado' . ($caseId ? ' e uma tarefa foi aberta na pasta.' : '.'));
        $vc = (int)($_POST['voltar_caso'] ?? 0);
        redirect($vc ? module_url('operacional', 'caso_ver.php?id=' . $vc) : module_url('gerid'));
    }

    if ($acao === 'resultado') {
        $id  = (int)($_POST['id'] ?? 0);
        $tem = !empty($_POST['tem_vinculo']) ? 1 : 0;
        $res = clean_str($_POST['resultado'] ?? '', 2000);
        $g = $pdo->prepare("SELECT * FROM gerid_pesquisas WHERE id=?"); $g->execute(array($id)); $row = $g->fetch();
        if ($row) {
            // Amanda 09/07/2026: guarda o estado ANTES do UPDATE pra decidir se dispara
            // emails/notificacoes. Ela reclamou 4 emails identicos pra mesma pesquisa —
            // cada clique em Registrar re-disparava o envio pra equipe. Fix: so envia
            // no TRANSIÇÃO real (pendente → tem_vinculo), nao em re-saves.
            $_geridEraPendente = ($row['status'] === 'pendente');
            $_geridTinhaVinculo = (int)($row['tem_vinculo'] ?? 0);
            // Upload printscreen INSS/GERID (opcional, PNG/JPG/PDF até 10MB)
            $psNome = null; $psPath = null; $psMime = null;
            if (!empty($_FILES['printscreen']) && (int)$_FILES['printscreen']['error'] === UPLOAD_ERR_OK) {
                $tmpUp = $_FILES['printscreen']['tmp_name'];
                $orig  = $_FILES['printscreen']['name'];
                $mime  = $_FILES['printscreen']['type'] ?: (function_exists('mime_content_type') ? mime_content_type($tmpUp) : 'application/octet-stream');
                $size  = (int)$_FILES['printscreen']['size'];
                $allowed = array('image/png','image/jpeg','image/jpg','application/pdf');
                if ($size <= 10 * 1024 * 1024 && in_array($mime, $allowed, true)) {
                    $dirUp = APP_ROOT . '/files/gerid';
                    if (!is_dir($dirUp)) @mkdir($dirUp, 0755, true);
                    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                    $stored = 'gerid_' . uniqid('', true) . '_' . $safe;
                    if (move_uploaded_file($tmpUp, $dirUp . '/' . $stored)) {
                        @chmod($dirUp . '/' . $stored, 0644);
                        $psNome = $orig; $psPath = $stored; $psMime = $mime;
                    }
                }
            }
            // Amanda 09/07/2026: UPDATE ATOMICO com "reserva do envio de email".
            // Bug real: double-click no botao Registrar disparava 2 POSTs quase
            // simultaneos. Ambos liam $row['status']='pendente' antes do primeiro
            // UPDATE completar → dispararam 2 emails identicos.
            //
            // Fix: 1o UPDATE serve de LOCK — inclui WHERE que exige status atual
            // != status final desejado (ou tem_vinculo != novo). Se rowCount=0,
            // significa que o 1o request ja processou. O 2o vira noop nos envios.
            $stUp = $pdo->prepare(
                "UPDATE gerid_pesquisas
                 SET status='concluida', tem_vinculo=?, resultado=?,
                     pesquisado_por=?, pesquisado_em=NOW(),
                     printscreen_nome=COALESCE(?, printscreen_nome),
                     printscreen_path=COALESCE(?, printscreen_path),
                     printscreen_mime=COALESCE(?, printscreen_mime)
                 WHERE id = ?
                   AND (status != 'concluida' OR tem_vinculo != ? OR tem_vinculo IS NULL)"
            );
            $stUp->execute(array($tem, $res ?: null, current_user_id(), $psNome, $psPath, $psMime, $id, $tem));
            $_geridMudou = $stUp->rowCount() > 0;
            // Se rowCount == 0, foi request duplicado (double-click) OU re-save
            // sem alterar valor. Nao envia emails/notif/tarefa denovo.
            if (!$_geridMudou) {
                $_geridEraPendente = false; // desliga guards que dependem disso
                $_geridTinhaVinculo = (int)$tem; // finge que ja era o mesmo → nao dispara
            }
            // fecha a tarefa
            if (!empty($row['task_id'])) {
                try { $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=NOW() WHERE id=?")->execute(array($row['task_id'])); } catch (Exception $e) {}
            }
            // andamento na pasta
            if (!empty($row['case_id'])) {
                try {
                    $pdo->prepare("INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at) VALUES (?,?,?,?,?,0,NOW())")
                        ->execute(array($row['case_id'], date('Y-m-d'), 'gerid',
                            'Pesquisa GERID (' . $row['parte_nome'] . '): ' . ($tem ? 'POSSUI vínculo empregatício' : 'sem vínculo localizado') . ($res ? ' — ' . $res : '')));
                } catch (Exception $e) {}
            }
            // avisa quem pediu (notify + email + cria TAREFA na pasta pra revisar resultado)
            // GUARD: so na TRANSIÇÃO pendente→concluida ou mudou tem_vinculo (nao re-save)
            $_geridTrocouResultado = ($_geridEraPendente || $_geridTinhaVinculo !== (int)$tem);
            if (!empty($row['created_by']) && (int)$row['created_by'] !== current_user_id() && $_geridTrocouResultado) {
                $solicitante = (int)$row['created_by'];
                $detalheRes = ($tem ? 'POSSUI vínculo' : 'sem vínculo') . ($res ? ' — ' . $res : '');

                // Amanda 03/07: envio de e-mail pro solicitante (Brevo)
                try {
                    $stSol = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
                    $stSol->execute(array($solicitante));
                    $sol = $stSol->fetch(PDO::FETCH_ASSOC);
                    if ($sol && !empty($sol['email'])) {
                        $meuNome = function_exists('user_display_name') ? user_display_name() : 'Equipe';
                        $chipCor = $tem ? '#dc2626' : '#059669';
                        $chipTxt = $tem ? 'POSSUI VÍNCULO' : 'SEM VÍNCULO';
                        $linkPasta = !empty($row['case_id'])
                            ? 'https://ferreiraesa.com.br/conecta/modules/operacional/caso_ver.php?id=' . (int)$row['case_id']
                            : 'https://ferreiraesa.com.br/conecta/modules/gerid/';
                        $body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">'
                              . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">'
                              . '<div style="background:linear-gradient(135deg,#0f2140,#1a3358);padding:22px;text-align:center;">'
                              . '<h1 style="color:#c9a94e;font-size:19px;margin:0;font-family:Georgia,serif;">🔎 Resultado da pesquisa GERID</h1>'
                              . '<p style="color:#94a3b8;font-size:11px;margin:4px 0 0;">Ferreira &amp; Sá Advocacia</p></div>'
                              . '<div style="padding:24px;color:#374151;line-height:1.6;">'
                              . '<p style="margin:0 0 14px;font-size:15px;">Olá, <strong>' . htmlspecialchars(explode(' ', (string)$sol['name'])[0], ENT_QUOTES, 'UTF-8') . '</strong>!</p>'
                              . '<p style="margin:0 0 18px;font-size:14px;"><strong>' . htmlspecialchars($meuNome, ENT_QUOTES, 'UTF-8') . '</strong> concluiu a pesquisa de vínculo empregatício (GERID) que você solicitou.</p>'
                              . '<div style="background:#f9fafb;border-left:4px solid ' . $chipCor . ';border-radius:6px;padding:14px 16px;margin:16px 0;">'
                              . '<p style="margin:0 0 6px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;font-weight:700;">Parte pesquisada</p>'
                              . '<p style="margin:0 0 12px;font-size:15px;font-weight:700;color:#0f2140;">' . htmlspecialchars((string)$row['parte_nome'], ENT_QUOTES, 'UTF-8')
                              . ($row['parte_cpf'] ? ' <span style="font-weight:400;color:#6b7280;font-size:13px;">(CPF ' . htmlspecialchars((string)$row['parte_cpf'], ENT_QUOTES, 'UTF-8') . ')</span>' : '') . '</p>'
                              . '<p style="margin:0 0 4px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;font-weight:700;">Resultado</p>'
                              . '<p style="margin:0;font-size:15px;font-weight:800;color:' . $chipCor . ';">' . $chipTxt . '</p>'
                              . ($res ? '<p style="margin:8px 0 0;font-size:13.5px;color:#374151;">' . nl2br(htmlspecialchars((string)$res, ENT_QUOTES, 'UTF-8')) . '</p>' : '')
                              . '</div>'
                              . '<div style="text-align:center;margin:24px 0 8px;">'
                              . '<a href="' . htmlspecialchars($linkPasta, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#0e7490,#0891b2);color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">' . (!empty($row['case_id']) ? 'Abrir pasta do processo →' : 'Ver todas as pesquisas GERID →') . '</a></div>'
                              . '<p style="margin:20px 0 0;font-size:12px;color:#94a3b8;">Uma tarefa de revisão foi criada na pasta do processo pra você decidir os próximos passos (ofício ao empregador, penhora de salário, etc.).</p>'
                              . '</div>'
                              . '<div style="background:#f9fafb;padding:12px 20px;font-size:11px;color:#9ca3af;text-align:center;">Ferreira &amp; Sá Advocacia — Sistema Conecta</div>'
                              . '</div></body></html>';
                        send_brevo_email_simple($sol['email'], $sol['name'], '🔎 GERID: ' . $row['parte_nome'] . ' — ' . $chipTxt, $body);
                    }
                } catch (Exception $emailErr) { /* envio silencioso — não bloqueia fluxo */ }

                notify($solicitante, '🔎 Resultado da pesquisa GERID',
                    $row['parte_nome'] . ': ' . $detalheRes,
                    'info', url('modules/gerid/'), '🔎');

                // 29/06/2026 Amanda: criar tarefa de REVIEW na pasta pra Amanda
                // tomar providência (decidir próximo passo: pedir penhora de salário,
                // mandar oficio ao empregador, etc.). Só cria quando há case vinculado.
                if (!empty($row['case_id'])) {
                    try {
                        $tituloRev = '👀 Revisar resultado GERID — ' . $row['parte_nome']
                                   . ($tem ? ' (POSSUI vínculo)' : ' (sem vínculo)');
                        $descRev = 'Luiz Eduardo concluiu a pesquisa GERID de ' . $row['parte_nome']
                                 . ($row['parte_cpf'] ? ' (CPF ' . $row['parte_cpf'] . ')' : '')
                                 . '. Resultado: ' . $detalheRes
                                 . '. Decidir próximo passo (ofício ao empregador, penhora salário, arquivar etc.).';
                        $pdo->prepare("INSERT INTO case_tasks (case_id, title, tipo, descricao, assigned_to, due_date, prioridade, status, sort_order, created_at)
                                       VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                            ->execute(array((int)$row['case_id'], $tituloRev, 'outros', $descRev,
                                            $solicitante, date('Y-m-d', strtotime('+3 days')),
                                            $tem ? 'alta' : 'media', 'a_fazer', 0));
                        $taskRevId = (int)$pdo->lastInsertId();
                        $pdo->prepare("UPDATE gerid_pesquisas SET task_review_id = ? WHERE id = ?")
                            ->execute(array($taskRevId, $id));
                    } catch (Exception $e) {}
                }
            }

            // Amanda 09/07/2026: quando resultado for POSITIVO (POSSUI vinculo),
            // notifica todo o escritorio por email — independente de quem
            // solicitou. Ideia: acelerar decisao de proximos passos (ofício ao
            // empregador, penhora de salario, execução).
            // GUARD: só dispara na TRANSIÇÃO pendente→com_vinculo (bug 09/07:
            // Amanda recebeu 4 emails iguais por re-cliques em 'Registrar').
            if ((int)$tem === 1 && ($_geridEraPendente || $_geridTinhaVinculo === 0)) {
                try {
                    // Amanda 09/07/2026: lista de user_ids que NAO recebem o email
                    // geral de GERID positivo. Pra adicionar/remover, editar aqui.
                    $_geridOptOutIds = array(
                        3,  // Rodrigo de Almeida Gustavo (r.almeidagustavo@gmail.com)
                        10, // Amanda Teste (amandaferreira@ferreiraesa.com.br) — duplicata
                        14, // Admin Hub (admin.hub@ferreiraesa.com.br) — usuario tecnico
                    );
                    $_optOutPh = implode(',', array_fill(0, count($_geridOptOutIds), '?'));
                    $stEq = db()->prepare("SELECT id, name, email FROM users
                                            WHERE is_active = 1
                                              AND email IS NOT NULL AND email <> ''
                                              AND id NOT IN ($_optOutPh)");
                    $stEq->execute($_geridOptOutIds);
                    $equipe = $stEq->fetchAll(PDO::FETCH_ASSOC);
                    $pesqNome = function_exists('user_display_name') ? user_display_name() : 'A equipe';
                    $solicNome = '';
                    if (!empty($row['created_by'])) {
                        $stSN = db()->prepare("SELECT name FROM users WHERE id = ?");
                        $stSN->execute(array((int)$row['created_by']));
                        $solicNome = (string)$stSN->fetchColumn();
                    }
                    $linkPastaEq = !empty($row['case_id'])
                        ? 'https://ferreiraesa.com.br/conecta/modules/operacional/caso_ver.php?id=' . (int)$row['case_id'] . '#gerid'
                        : 'https://ferreiraesa.com.br/conecta/modules/gerid/';
                    $bodyEq = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">'
                            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">'
                            . '<div style="background:linear-gradient(135deg,#065f46,#059669);padding:22px;text-align:center;">'
                            . '<h1 style="color:#fff;font-size:20px;margin:0;font-family:Georgia,serif;">✅ Vínculo empregatício encontrado!</h1>'
                            . '<p style="color:#d1fae5;font-size:12px;margin:4px 0 0;">Pesquisa GERID · Ferreira &amp; Sá Advocacia</p></div>'
                            . '<div style="padding:24px;color:#374151;line-height:1.6;">'
                            . '<p style="margin:0 0 14px;font-size:15px;">O escritório tem um resultado positivo pra comemorar 🎉</p>'
                            . '<div style="background:#ecfdf5;border-left:4px solid #059669;border-radius:6px;padding:14px 16px;margin:16px 0;">'
                            . '<p style="margin:0 0 6px;font-size:12px;color:#059669;text-transform:uppercase;letter-spacing:.06em;font-weight:700;">Parte pesquisada</p>'
                            . '<p style="margin:0 0 14px;font-size:16px;font-weight:700;color:#065f46;">' . htmlspecialchars((string)$row['parte_nome'], ENT_QUOTES, 'UTF-8')
                            . ($row['parte_cpf'] ? ' <span style="font-weight:400;color:#6b7280;font-size:13px;">(CPF ' . htmlspecialchars((string)$row['parte_cpf'], ENT_QUOTES, 'UTF-8') . ')</span>' : '') . '</p>'
                            . '<p style="margin:0 0 6px;font-size:12px;color:#059669;text-transform:uppercase;letter-spacing:.06em;font-weight:700;">Resultado</p>'
                            . '<p style="margin:0 0 10px;font-size:16px;font-weight:800;color:#065f46;">✅ POSSUI VÍNCULO EMPREGATÍCIO</p>'
                            . ($res ? '<p style="margin:0;font-size:13.5px;color:#374151;">' . nl2br(htmlspecialchars((string)$res, ENT_QUOTES, 'UTF-8')) . '</p>' : '')
                            . '</div>'
                            . '<table style="width:100%;font-size:13px;color:#4b5563;margin-bottom:16px;">'
                            . ($solicNome ? '<tr><td style="padding:4px 0;color:#94a3b8;width:130px;">Solicitado por</td><td style="padding:4px 0;font-weight:600;color:#0f172a;">' . htmlspecialchars($solicNome, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
                            . '<tr><td style="padding:4px 0;color:#94a3b8;">Pesquisado por</td><td style="padding:4px 0;font-weight:600;color:#0f172a;">' . htmlspecialchars($pesqNome, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                            . '<tr><td style="padding:4px 0;color:#94a3b8;">Concluído em</td><td style="padding:4px 0;font-weight:600;color:#0f172a;">' . date('d/m/Y H:i') . '</td></tr>'
                            . '</table>'
                            . '<div style="text-align:center;margin:24px 0 8px;">'
                            . '<a href="' . htmlspecialchars($linkPastaEq, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#065f46,#059669);color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">' . (!empty($row['case_id']) ? 'Abrir pasta do processo →' : 'Ver todas as pesquisas GERID →') . '</a></div>'
                            . '<p style="margin:20px 0 0;font-size:12px;color:#94a3b8;">Comunicado enviado a toda equipe pra alinhar próximos passos: ofício ao empregador, penhora de salário, execução, etc.</p>'
                            . '</div>'
                            . '<div style="background:#f9fafb;padding:12px 20px;font-size:11px;color:#9ca3af;text-align:center;">Ferreira &amp; Sá Advocacia — Sistema Conecta</div>'
                            . '</div></body></html>';
                    $assunto = '✅ GERID POSITIVO: ' . $row['parte_nome'] . ' possui vínculo empregatício';
                    $enviados = 0;
                    foreach ($equipe as $u) {
                        try {
                            if (send_brevo_email_simple($u['email'], $u['name'], $assunto, $bodyEq)) $enviados++;
                        } catch (Throwable $e) { /* envio silencioso por destinatario */ }
                    }
                    audit_log('gerid_email_equipe', 'gerid', (int)$id, $enviados . '/' . count($equipe) . ' emails de vinculo positivo enviados');
                } catch (Throwable $e) { /* nao bloqueia fluxo */ }

                // Amanda 09/07/2026: gera oficio de desconto em folha via IA
                // (Claude Sonnet + web_search). Killswitch em configuracoes.
                // So dispara na transicao real (mesma guarda dos emails).
                try {
                    require_once APP_ROOT . '/core/functions_gerid_oficio.php';
                    if (function_exists('gerid_oficio_auto_ativo') && gerid_oficio_auto_ativo()) {
                        gerid_gerar_oficio_desconto($pdo, (int)$id);
                    }
                } catch (Throwable $e) { /* nao bloqueia fluxo */ }
            }

            audit_log('gerid_resultado', 'gerid', $id, $tem ? 'com vinculo' : 'sem vinculo');
            flash_set('success', 'Resultado registrado.');
        }
        redirect(module_url('gerid'));
    }

    redirect(module_url('gerid'));
}

// Amanda 09/07/2026: busca livre por nome (parte OU nosso cliente) OU num processo
$_qBusca = trim($_GET['q'] ?? '');
$_qLike = '%' . $_qBusca . '%';
$_qLikeDig = '%' . preg_replace('/\D/', '', $_qBusca) . '%'; // pra matchear CPF/CNJ sem mascara
$_whereBusca = '';
$_paramsBusca = array();
if ($_qBusca !== '') {
    // Busca em: parte pesquisada, CPF/CNJ (dígitos), nosso cliente (via g.client_id OU via case.client_id),
    // numero do processo, titulo do caso
    $_whereBusca = " AND (
        g.parte_nome LIKE ?
        OR g.parte_cpf LIKE ?
        OR REPLACE(REPLACE(REPLACE(g.parte_cpf,'.',''),'-',''),'/','') LIKE ?
        OR cl.name LIKE ?
        OR cc.name LIKE ?
        OR c.case_number LIKE ?
        OR REPLACE(REPLACE(REPLACE(c.case_number,'.',''),'-',''),'/','') LIKE ?
        OR c.title LIKE ?
    )";
    $_paramsBusca = array($_qLike, $_qLike, $_qLikeDig, $_qLike, $_qLike, $_qLike, $_qLikeDig, $_qLike);
}

$_stP = $pdo->prepare(
    "SELECT g.*, cl.name AS client_name, cc.name AS case_client_name,
            c.case_number, c.title AS case_title,
            u.name AS reg_por, u.email AS reg_email
     FROM gerid_pesquisas g
     LEFT JOIN clients cl ON cl.id=g.client_id
     LEFT JOIN cases c ON c.id=g.case_id
     LEFT JOIN clients cc ON cc.id=c.client_id
     LEFT JOIN users u ON u.id=g.created_by
     WHERE g.status='pendente' $_whereBusca
     ORDER BY g.created_at ASC LIMIT 300"
);
$_stP->execute($_paramsBusca);
$pendentes = $_stP->fetchAll();

$_stC = $pdo->prepare(
    "SELECT g.*, cl.name AS client_name, cc.name AS case_client_name,
            c.case_number, c.title AS case_title,
            u.name AS reg_por, u.email AS reg_email, p.name AS pesq_por
     FROM gerid_pesquisas g
     LEFT JOIN clients cl ON cl.id=g.client_id
     LEFT JOIN cases c ON c.id=g.case_id
     LEFT JOIN clients cc ON cc.id=c.client_id
     LEFT JOIN users u ON u.id=g.created_by
     LEFT JOIN users p ON p.id=g.pesquisado_por
     WHERE g.status='concluida' $_whereBusca
     ORDER BY g.pesquisado_em DESC LIMIT 200"
);
$_stC->execute($_paramsBusca);
$concluidas = $_stC->fetchAll();
$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.gd-card { background:#fff;border-radius:12px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:14px; }
.gd-input,.gd-text { width:100%;border:1px solid #ddd;border-radius:8px;padding:8px 10px;font-size:.9rem;font-family:inherit; }
.gd-text { min-height:48px;resize:vertical; }
.gd-row { display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px; }
.gd-row > div { flex:1;min-width:180px; }
.gd-label { font-size:.78rem;font-weight:700;color:#444;display:block;margin-bottom:4px; }
.gd-btn { background:#0e7490;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;font-size:.9rem; }
.gd-item { border-left:4px solid #0e7490; }
.gd-chip { display:inline-block;padding:2px 9px;border-radius:999px;font-size:.72rem;font-weight:700; }
.gd-sim { background:#dcfce7;color:#15803d; } .gd-nao { background:#fee2e2;color:#b91c1c; }
.gd-pend { background:#fef3c7;color:#92400e; }
.gd-results { position:relative; } .gd-rbox { position:absolute;z-index:30;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:0 0 8px 8px;max-height:200px;overflow:auto;display:none;box-shadow:0 6px 14px rgba(0,0,0,.08); }
.gd-rbox div { padding:8px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:.85rem; } .gd-rbox div:hover { background:#ecfeff; }
.gd-empty { text-align:center;padding:30px;color:#999; }
</style>

<div class="page-header" style="margin-bottom:.6rem;">
  <h1 style="margin:0;">🔎 Pesquisa GERID — Vínculo Empregatício</h1>
  <p style="color:#777;margin:4px 0 0;">Peça pra descobrir, via GERID/INSS Digital, se a parte (pai/mãe) tem vínculo de emprego. O Luiz Eduardo é avisado e a pesquisa é registrada aqui.</p>
</div>

<!-- Amanda 09/07/2026: busca por parte, nosso cliente, CPF ou numero de processo -->
<form method="get" action="<?= module_url('gerid') ?>" style="margin:.5rem 0 1rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;max-width:760px;">
  <input type="text" name="q" value="<?= e($_qBusca) ?>" placeholder="🔍 Buscar por parte, nosso cliente, CPF ou nº do processo…" style="flex:1;min-width:280px;border:1px solid #ddd;border-radius:8px;padding:9px 12px;font-size:.92rem;">
  <button type="submit" class="gd-btn" style="padding:9px 18px;">Buscar</button>
  <?php if ($_qBusca !== ''): ?>
    <a href="<?= module_url('gerid') ?>" style="font-size:.82rem;color:#b91c1c;text-decoration:none;font-weight:600;">✕ limpar</a>
    <span style="font-size:.78rem;color:#666;background:#fef3c7;padding:4px 10px;border-radius:999px;border:1px solid #fbbf24;">Filtrando por: <strong><?= e($_qBusca) ?></strong> — <?= count($pendentes) + count($concluidas) ?> resultado(s)</span>
  <?php endif; ?>
</form>

<details class="gd-card" style="max-width:760px;">
  <summary style="font-weight:700;color:#0e7490;cursor:pointer;">➕ Nova pesquisa (avulsa)</summary>
  <form method="post" action="<?= module_url('gerid') ?>" style="margin-top:12px;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="acao" value="solicitar">
    <input type="hidden" name="client_id" id="gdClientId">
    <div class="gd-row">
      <div><label class="gd-label">Nome completo da parte *</label><input type="text" class="gd-input" name="parte_nome" required></div>
      <div><label class="gd-label">CPF</label><input type="text" class="gd-input" name="parte_cpf" id="gdCpf" placeholder="000.000.000-00" onblur="gdChecarCpf(this.value)"></div>
    </div>
    <!-- Amanda 09/07/2026: alerta de CPF ja pesquisado (dedup cross-case) -->
    <div id="gdAvisoDup" style="display:none;background:#fef3c7;border:1.5px solid #fbbf24;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:.8rem;color:#78350f;"></div>
    <div class="gd-row">
      <div><label class="gd-label">É o(a)…</label>
        <select class="gd-input" name="parente"><option value="">—</option><option value="pai">Pai</option><option value="mae">Mãe</option><option value="outro">Outro</option></select>
      </div>
      <div><label class="gd-label">Cliente (opcional)</label>
        <div class="gd-results"><input type="text" class="gd-input" id="gdBuscaCli" placeholder="Vincular a um cliente…" autocomplete="off" onkeyup="gdBuscarCli(this.value)"><div class="gd-rbox" id="gdCliBox"></div></div>
        <div id="gdCliSel" style="font-size:.82rem;margin-top:4px;"></div>
      </div>
    </div>
    <div class="gd-row"><div style="flex:1 1 100%;"><label class="gd-label">Observação</label><textarea class="gd-text" name="observacao" placeholder="Algum detalhe…"></textarea></div></div>
    <button type="submit" class="gd-btn">🔎 Solicitar pesquisa</button>
  </form>
</details>

<h3 style="margin:18px 0 8px;">⏳ Pendentes (<?= count($pendentes) ?>)</h3>
<?php if (!$pendentes): ?><div class="gd-empty">Nenhuma pesquisa pendente. 🎉</div><?php else: foreach ($pendentes as $g):
  $proc = $g['case_number'] ?: $g['case_title']; ?>
<div class="gd-card gd-item">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
    <div style="font-weight:700;color:#0e7490;flex:1;">👤 <?= e($g['parte_nome']) ?><?= $g['parte_cpf'] ? ' · CPF ' . e($g['parte_cpf']) : '' ?><?= $g['parente'] ? ' · ' . e($g['parente']) : '' ?></div>
    <form method="post" action="<?= module_url('gerid') ?>" onsubmit="return confirm('Excluir definitivamente esta pesquisa GERID? Tarefas vinculadas serão canceladas.');" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="acao" value="excluir">
      <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
      <button type="submit" title="Excluir pesquisa (cancela tarefas vinculadas)" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:6px;padding:3px 8px;font-size:.7rem;font-weight:700;cursor:pointer;">🗑️ Excluir</button>
    </form>
  </div>
  <div style="color:#666;font-size:.83rem;margin-top:3px;">
    <?php if ($proc): ?>
        <?php if (!empty($g['case_id'])): ?>
            📄 <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$g['case_id']) ?>" style="color:inherit;text-decoration:underline;text-decoration-style:dotted;" title="Abrir pasta do processo"><?= e($proc) ?></a> ·
        <?php else: ?>
            📄 <?= e($proc) ?> ·
        <?php endif; ?>
    <?php endif; ?>
    <?php $_nossoCli = $g['client_name'] ?: ($g['case_client_name'] ?? ''); ?>
    <?= $_nossoCli ? '<span style="color:#0e7490;font-weight:600;">👥 Nosso cliente: ' . e($_nossoCli) . '</span> · ' : '' ?>
    pedido por <?= e($g['reg_por'] ?: '—') ?> em <?= date('d/m/Y', strtotime($g['created_at'])) ?>
  </div>
  <?php if ($g['observacao']): ?><div style="font-size:.83rem;margin-top:5px;color:#444;"><?= e($g['observacao']) ?></div><?php endif; ?>
  <form method="post" action="<?= module_url('gerid') ?>" enctype="multipart/form-data" style="margin-top:10px;border-top:1px solid #f0f0f0;padding-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;" onsubmit="gdSubmitLock(this)">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="resultado"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
    <span style="font-size:.82rem;font-weight:700;">Resultado:</span>
    <label style="font-size:.85rem;display:flex;align-items:center;gap:5px;"><input type="radio" name="tem_vinculo" value="1" required> ✅ Tem vínculo</label>
    <label style="font-size:.85rem;display:flex;align-items:center;gap:5px;"><input type="radio" name="tem_vinculo" value="0"> ❌ Sem vínculo</label>
    <input type="text" name="resultado" class="gd-input" style="flex:1;min-width:200px;width:auto;" placeholder="Empregador / detalhes (opcional)">
    <label style="font-size:.75rem;display:flex;align-items:center;gap:5px;color:#475569;background:#f1f5f9;border:1px dashed #94a3b8;border-radius:6px;padding:5px 9px;cursor:pointer;">📸 Print INSS:
      <input type="file" name="printscreen" accept="image/*,.pdf" style="font-size:.74rem;max-width:170px;">
    </label>
    <button type="submit" class="gd-btn" style="padding:7px 12px;">Registrar</button>
  </form>
</div>
<?php endforeach; endif; ?>

<h3 style="margin:22px 0 8px;">✅ Concluídas</h3>
<?php if (!$concluidas): ?><div class="gd-empty">Nenhuma ainda.</div><?php else: ?>
<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">
  <thead><tr style="background:#fafafa;font-size:.72rem;text-transform:uppercase;color:#888;"><th style="padding:9px 11px;text-align:left;">Parte</th><th style="padding:9px 11px;text-align:left;">Nosso cliente</th><th style="padding:9px 11px;text-align:left;">Processo</th><th style="padding:9px 11px;text-align:left;">Vínculo</th><th style="padding:9px 11px;text-align:left;">Detalhe</th><th style="padding:9px 11px;text-align:left;">Solicitado por</th><th style="padding:9px 11px;text-align:left;">Pesquisado por</th><th style="padding:9px 11px;text-align:left;">Tratado?</th><th style="padding:9px 11px;text-align:center;width:60px;"></th></tr></thead>
  <tbody>
  <?php foreach ($concluidas as $g): ?>
    <tr style="border-bottom:1px solid #f0f0f0;font-size:.85rem;" id="gd-row-<?= (int)$g['id'] ?>">
      <td style="padding:9px 11px;"><?= e($g['parte_nome']) ?><?= $g['parte_cpf'] ? '<br><span style="color:#999;font-size:.78rem;">' . e($g['parte_cpf']) . '</span>' : '' ?></td>
      <td style="padding:9px 11px;">
        <?php $_nossoCliT = $g['client_name'] ?: ($g['case_client_name'] ?? ''); ?>
        <?php if ($_nossoCliT): ?>
          <span style="color:#0e7490;font-weight:600;"><?= e($_nossoCliT) ?></span>
        <?php else: ?>
          <span style="color:#999;">—</span>
        <?php endif; ?>
      </td>
      <td style="padding:9px 11px;">
        <?php $_procTxt = $g['case_number'] ?: ($g['case_title'] ?: '—'); ?>
        <?php if (!empty($g['case_id']) && $_procTxt !== '—'): ?>
          <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$g['case_id']) ?>" style="color:#0c4a6e;text-decoration:underline;text-decoration-style:dotted;font-weight:600;" title="Abrir pasta do processo"><?= e($_procTxt) ?></a>
        <?php else: ?>
          <?= e($_procTxt) ?>
        <?php endif; ?>
      </td>
      <td style="padding:9px 11px;"><span class="gd-chip <?= $g['tem_vinculo'] ? 'gd-sim' : 'gd-nao' ?>"><?= $g['tem_vinculo'] ? 'POSSUI' : 'Sem vínculo' ?></span></td>
      <td style="padding:9px 11px;"><?= e($g['resultado'] ?: '—') ?>
        <?php if (!empty($g['printscreen_path'])): ?>
          <br><a href="?baixar=<?= (int)$g['id'] ?>" target="_blank" rel="noopener" style="font-size:.72rem;color:#0c4a6e;text-decoration:none;font-weight:600;">📸 Ver print INSS</a>
        <?php endif; ?>
      </td>
      <td style="padding:9px 11px;"><?= e($g['reg_por'] ?: '—') ?><br><span style="color:#999;font-size:.78rem;"><?= $g['created_at'] ? date('d/m/Y', strtotime($g['created_at'])) : '' ?></span></td>
      <td style="padding:9px 11px;"><?= e($g['pesq_por'] ?: '—') ?><br><span style="color:#999;font-size:.78rem;"><?= $g['pesquisado_em'] ? date('d/m/Y', strtotime($g['pesquisado_em'])) : '' ?></span></td>
      <td style="padding:9px 11px;">
        <button type="button" id="gd-trat-<?= (int)$g['id'] ?>" onclick="gdToggleTratado(<?= (int)$g['id'] ?>)"
                class="gd-chip <?= $g['tratado_em'] ? 'gd-sim' : 'gd-pend' ?>"
                style="border:none;cursor:pointer;font-family:inherit;">
          <?= $g['tratado_em'] ? '✓ Tratado' : '⏳ Não tratado' ?>
        </button>
        <?php if ($g['tratado_em']): ?>
          <br><span style="color:#999;font-size:.7rem;"><?= date('d/m H:i', strtotime($g['tratado_em'])) ?></span>
        <?php endif; ?>
      </td>
      <td style="padding:9px 11px;text-align:center;">
        <form method="post" action="<?= module_url('gerid') ?>" onsubmit="return confirm('Excluir definitivamente esta pesquisa GERID concluída? Esta ação não pode ser desfeita.');" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="acao" value="excluir">
          <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <button type="submit" title="Excluir pesquisa" style="background:none;color:#b91c1c;border:none;cursor:pointer;font-size:.95rem;padding:2px 6px;">🗑️</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<script>
var GD_URL = '<?= module_url('gerid') ?>';
var gdT=null;
function gdBuscarCli(q){
  clearTimeout(gdT); var box=document.getElementById('gdCliBox');
  if(q.length<2){box.style.display='none';return;}
  gdT=setTimeout(function(){
    fetch(GD_URL+'?ajax=buscar_cliente&q='+encodeURIComponent(q)).then(function(r){return r.json();}).then(function(arr){
      var h=''; arr.forEach(function(c){ h+='<div onclick="gdSelCli('+c.id+',&quot;'+(c.name||'').replace(/"/g,'')+'&quot;)">'+(c.name||'')+(c.cpf?' · '+c.cpf:'')+'</div>'; });
      box.innerHTML=h||'<div style="color:#999;cursor:default;">Nenhum</div>'; box.style.display='block';
    });
  },250);
}
// Amanda 09/07/2026: guard contra double-click no botao Registrar do resultado.
// Backend tem UPDATE atomico como protecao real, mas isso reduz risco.
function gdSubmitLock(f) {
  var btn = f.querySelector('button[type="submit"]');
  if (!btn) return true;
  if (btn.disabled) return false; // ja processando
  btn.disabled = true;
  var textoAntigo = btn.textContent;
  btn.textContent = '⏳ Registrando...';
  // Failsafe: reabilita apos 20s caso o browser fique preso
  setTimeout(function(){ if (btn.disabled) { btn.disabled = false; btn.textContent = textoAntigo; } }, 20000);
  return true;
}
function gdSelCli(id,name){ document.getElementById('gdClientId').value=id; document.getElementById('gdBuscaCli').value=''; document.getElementById('gdCliBox').style.display='none'; document.getElementById('gdCliSel').innerHTML='👥 '+name+' <a href="javascript:void(0)" onclick="gdLimparCli()" style="color:#b91c1c;">×</a>'; }
function gdLimparCli(){ document.getElementById('gdClientId').value=''; document.getElementById('gdCliSel').innerHTML=''; }

// Amanda 09/07/2026: checa se CPF ja foi pesquisado antes (dedup cross-case)
function gdChecarCpf(cpf) {
  var aviso = document.getElementById('gdAvisoDup');
  var dig = (cpf || '').replace(/\D/g, '');
  if (dig.length < 11) { aviso.style.display = 'none'; return; }
  fetch(GD_URL + '?ajax=historico_cpf&cpf=' + encodeURIComponent(dig))
    .then(function(r) { return r.json(); })
    .then(function(arr) {
      if (!arr || !arr.length) { aviso.style.display = 'none'; return; }
      var html = '<strong>⚠️ Este CPF já foi pesquisado antes!</strong><p style="margin:.4rem 0 .3rem;font-size:.78rem;">Encontramos <strong>' + arr.length + '</strong> pesquisa(s) anterior(es):</p><ul style="margin:.3rem 0 .5rem;padding-left:1.2rem;font-size:.76rem;">';
      arr.forEach(function(h) {
        var status = '';
        if (h.status === 'concluida' && h.tem_vinculo == 1)      status = '<strong style="color:#15803d;">✅ POSSUI</strong>';
        else if (h.status === 'concluida')                        status = '<strong style="color:#64748b;">❌ SEM vínculo</strong>';
        else                                                      status = '<strong style="color:#a16207;">⏳ pendente</strong>';
        var caseLink = h.case_id
          ? ('caso <a href="<?= url('modules/operacional/caso_ver.php?id=') ?>' + h.case_id + '#gerid" target="_blank" style="color:#0e7490;font-weight:600;text-decoration:underline;">' + (h.case_title || ('#' + h.case_id)) + '</a>' + (h.case_client_name ? ' <em>(cliente: ' + h.case_client_name + ')</em>' : ''))
          : 'pesquisa avulsa';
        var d = h.created_at ? h.created_at.substring(0, 10).split('-').reverse().join('/') : '';
        var res = h.resultado ? '<br><em style="color:#451a03;">"' + (h.resultado.length > 120 ? h.resultado.substring(0, 120) + '…' : h.resultado) + '"</em>' : '';
        html += '<li style="margin-bottom:.25rem;">' + status + ' — ' + caseLink + ' · pedido ' + d + res + '</li>';
      });
      html += '</ul><p style="margin:.4rem 0 0;font-size:.74rem;">Se ainda quiser pesquisar de novo (ex: verificar se mudou de emprego), pode continuar. Caso contrário, use o resultado anterior.</p>';
      aviso.innerHTML = html;
      aviso.style.display = 'block';
    })
    .catch(function() { aviso.style.display = 'none'; });
}

var GD_CSRF = '<?= $csrf ?>';
function gdToggleTratado(id) {
  var btn = document.getElementById('gd-trat-' + id);
  if (!btn) return;
  btn.disabled = true; var textoAntigo = btn.textContent; btn.textContent = '⏳ ...';
  var fd = new FormData();
  fd.append('acao', 'toggle_tratado');
  fd.append('csrf_token', GD_CSRF);
  fd.append('id', id);
  fetch(GD_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r) {
      if (r.status === 401) { if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada(); throw new Error('401'); }
      return r.json();
    })
    .then(function(j) {
      btn.disabled = false;
      if (!j.ok) { btn.textContent = textoAntigo; alert('Erro: ' + (j.erro || '?')); return; }
      if (j.tratado) {
        btn.className = 'gd-chip gd-sim';
        btn.style.border = 'none'; btn.style.cursor = 'pointer'; btn.style.fontFamily = 'inherit';
        btn.textContent = '✓ Tratado';
        // Mostra data abaixo
        var par = btn.parentNode;
        var dataLine = par.querySelector('.gd-trat-em');
        if (!dataLine) {
          dataLine = document.createElement('span');
          dataLine.className = 'gd-trat-em';
          dataLine.style.cssText = 'color:#999;font-size:.7rem;display:block;margin-top:2px;';
          par.appendChild(dataLine);
        }
        dataLine.textContent = j.em;
      } else {
        btn.className = 'gd-chip gd-pend';
        btn.style.border = 'none'; btn.style.cursor = 'pointer'; btn.style.fontFamily = 'inherit';
        btn.textContent = '⏳ Não tratado';
        var dataLine = btn.parentNode.querySelector('.gd-trat-em');
        if (dataLine) dataLine.remove();
      }
    })
    .catch(function(e) {
      btn.disabled = false; btn.textContent = textoAntigo;
    });
}
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
