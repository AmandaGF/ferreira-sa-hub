<?php
/**
 * Agenda — API (CRUD de eventos)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Balcão Virtual TJRJ: agendamento permitido só entre 11:00 e 17:00.
// Retorna [ok, msg] — ok=false se horário fora da janela.
function _balcao_valida_horario($datetime_str) {
    $ts = strtotime((string)$datetime_str);
    if ($ts === false) return array(true, '');
    $mins = ((int)date('H', $ts)) * 60 + (int)date('i', $ts);
    if ($mins < 11 * 60 || $mins > 17 * 60) {
        return array(false, 'Balcão Virtual: horário permitido entre 11:00 e 17:00 (recebido ' . date('H:i', $ts) . ').');
    }
    return array(true, '');
}

// ── GET: buscar eventos (AJAX) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'] ?? '';

    // Listar eventos de um intervalo
    if ($action === 'listar') {
        $inicio = $_GET['inicio'] ?? date('Y-m-01');
        $fim    = $_GET['fim'] ?? date('Y-m-t');
        $responsavel = isset($_GET['responsavel']) ? (int)$_GET['responsavel'] : 0;

        $sql = "SELECT e.*, c.name as client_name, c.phone as client_phone,
                       cs.title as case_title, cs.case_number,
                       u.name as responsavel_name,
                       (SELECT COUNT(*) FROM case_andamentos ca
                        WHERE ca.case_id = e.case_id
                          AND ca.created_at > e.created_at
                          AND ca.tipo NOT IN ('oficio','chamado')) AS andamentos_novos
                FROM agenda_eventos e
                LEFT JOIN clients c ON c.id = e.client_id
                LEFT JOIN cases cs ON cs.id = e.case_id
                LEFT JOIN users u ON u.id = e.responsavel_id
                WHERE e.data_inicio <= ? AND (e.data_fim >= ? OR e.data_inicio >= ?)
                  AND e.status != 'cancelado'";
        $params = array($fim . ' 23:59:59', $inicio . ' 00:00:00', $inicio . ' 00:00:00');

        if ($responsavel) {
            $sql .= " AND e.responsavel_id = ?";
            $params[] = $responsavel;
        }

        $sql .= " ORDER BY e.data_inicio ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resultados = $stmt->fetchAll();

        // Incluir tarefas (case_tasks) na agenda
        $incluirTarefas = ($_GET['incluir_tarefas'] ?? '1') !== '0';
        if ($incluirTarefas) { try {
            $hoje = date('Y-m-d');
            $hojeNoIntervalo = ($hoje >= $inicio && $hoje <= $fim);

            // Busca todas as tarefas ativas (não concluídas)
            $sqlT = "SELECT ct.id as task_id, ct.title, ct.tipo as task_tipo, ct.status as task_status,
                            ct.due_date, ct.prioridade, ct.assigned_to,
                            ct.case_id, cs.title as case_title, cs.case_number,
                            cl.name as client_name, cl.phone as client_phone,
                            u.name as responsavel_name
                     FROM case_tasks ct
                     LEFT JOIN cases cs ON cs.id = ct.case_id
                     LEFT JOIN clients cl ON cl.id = cs.client_id
                     LEFT JOIN users u ON u.id = ct.assigned_to
                     WHERE ct.status != 'concluido'
                       AND ct.tipo IS NOT NULL AND ct.tipo != ''";
            $paramsT = array();

            if ($responsavel) {
                $sqlT .= " AND ct.assigned_to = ?";
                $paramsT[] = $responsavel;
            }

            $sqlT .= " ORDER BY ct.due_date ASC";
            $stmtT = $pdo->prepare($sqlT);
            $stmtT->execute($paramsT);
            $todasTarefas = $stmtT->fetchAll();

            // Filtrar e posicionar tarefas no calendario
            foreach ($todasTarefas as $t) {
                $dataExibir = $t['due_date'];
                $atrasada = false;

                if (!$dataExibir) {
                    // Sem prazo: mostra em hoje (se hoje estiver no intervalo)
                    if (!$hojeNoIntervalo) continue;
                    $dataExibir = $hoje;
                } elseif ($dataExibir < $hoje) {
                    // Atrasada: mostra em hoje (se hoje estiver no intervalo)
                    if (!$hojeNoIntervalo) continue;
                    $atrasada = true;
                    $dataExibir = $hoje;
                } else {
                    // Com prazo futuro: só mostra se o prazo estiver no intervalo
                    if ($dataExibir < $inicio || $dataExibir > $fim) continue;
                }

                $tituloExibir = $t['title'];
                if ($atrasada) {
                    $tituloExibir = 'ATRASADA: ' . $t['title'] . ' (prazo: ' . date('d/m', strtotime($t['due_date'])) . ')';
                } elseif (!$t['due_date']) {
                    $tituloExibir = $t['title'] . ' (sem prazo)';
                }

                $resultados[] = array(
                    'id' => 'task_' . $t['task_id'],
                    'is_task' => true,
                    'task_id' => $t['task_id'],
                    'titulo' => $tituloExibir,
                    'tipo' => 'tarefa',
                    'task_tipo' => $t['task_tipo'],
                    'task_status' => $t['task_status'],
                    'prioridade' => $t['prioridade'],
                    'atrasada' => $atrasada,
                    'modalidade' => 'nao_aplicavel',
                    'data_inicio' => $dataExibir . ' 09:00:00',
                    'data_fim' => $dataExibir . ' 09:00:00',
                    'dia_todo' => 1,
                    'local' => null,
                    'meet_link' => null,
                    'descricao' => null,
                    'client_id' => null,
                    'client_name' => $t['client_name'],
                    'client_phone' => $t['client_phone'],
                    'case_id' => $t['case_id'],
                    'case_title' => $t['case_title'],
                    'case_number' => $t['case_number'],
                    'responsavel_id' => $t['assigned_to'],
                    'responsavel_name' => $t['responsavel_name'],
                    'msg_cliente' => null,
                    'status' => 'agendado',
                    'google_event_id' => null,
                    'participantes' => null,
                    'created_at' => null,
                    'updated_at' => null,
                );
            }
        } catch (Exception $e) { /* tabela case_tasks pode não ter todos os campos */ } }

        echo json_encode($resultados);
        exit;
    }

    // Buscar clientes (autocomplete)
    if ($action === 'busca_cliente') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo '[]'; exit; }
        $stmt = $pdo->prepare("SELECT id, name, phone FROM clients WHERE name LIKE ? ORDER BY name LIMIT 15");
        $stmt->execute(array('%' . $q . '%'));
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // Buscar processos (autocomplete)
    if ($action === 'busca_caso') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo '[]'; exit; }
        $stmt = $pdo->prepare(
            "SELECT cs.id, cs.title, cs.case_number, c.name as client_name
             FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id
             WHERE cs.title LIKE ? OR cs.case_number LIKE ? OR c.name LIKE ?
             ORDER BY cs.title LIMIT 15"
        );
        $stmt->execute(array('%'.$q.'%', '%'.$q.'%', '%'.$q.'%'));
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // Buscar evento por ID
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(array('error' => 'ID inválido')); exit; }
        $stmt = $pdo->prepare(
            "SELECT e.*, c.name as client_name, c.phone as client_phone, cs.title as case_title, cs.case_number, u.name as responsavel_name
             FROM agenda_eventos e
             LEFT JOIN clients c ON c.id = e.client_id
             LEFT JOIN cases cs ON cs.id = e.case_id
             LEFT JOIN users u ON u.id = e.responsavel_id
             WHERE e.id = ?"
        );
        $stmt->execute(array($id));
        $ev = $stmt->fetch();
        if (!$ev) { echo json_encode(array('error' => 'Evento não encontrado')); exit; }
        echo json_encode($ev);
        exit;
    }

    echo json_encode(array('error' => 'Ação GET inválida'));
    exit;
}

// ── POST: criar/editar/excluir ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }

header('Content-Type: application/json; charset=utf-8');

if (!validate_csrf()) {
    echo json_encode(array('error' => 'Token CSRF inválido', 'csrf' => generate_csrf_token()));
    exit;
}

// Após validar, o token foi regenerado — incluir novo em todas as respostas
$newCsrf = generate_csrf_token();
$action = $_POST['action'] ?? '';

// ── CRIAR / EDITAR ──
if ($action === 'salvar') {
    $id            = (int)($_POST['id'] ?? 0);
    $titulo        = trim($_POST['titulo'] ?? '');
    $tipo          = $_POST['tipo'] ?? 'reuniao_cliente';
    $modalidade    = $_POST['modalidade'] ?? 'nao_aplicavel';
    $dataInicio    = $_POST['data_inicio'] ?? '';
    $dataFim       = $_POST['data_fim'] ?? '';
    $diaTodo       = isset($_POST['dia_todo']) ? 1 : 0;
    $local         = trim($_POST['local'] ?? '');
    $meetLink      = trim($_POST['meet_link'] ?? '');
    $descricao     = trim($_POST['descricao'] ?? '');
    $clientId      = (int)($_POST['client_id'] ?? 0) ?: null;
    $caseId        = (int)($_POST['case_id'] ?? 0) ?: null;
    $responsavelId = (int)($_POST['responsavel_id'] ?? current_user_id());
    $msgCliente    = trim($_POST['msg_cliente'] ?? '');
    $lembreteEmail = isset($_POST['lembrete_email']) ? 1 : 0;
    $lembreteWa    = isset($_POST['lembrete_whatsapp']) ? 1 : 0;
    $lembretePortal= isset($_POST['lembrete_portal']) ? 1 : 0;
    $lembreteCliente = isset($_POST['lembrete_cliente']) ? 1 : 0;

    $tiposValidos = array('audiencia','reuniao_cliente','prazo','onboarding','reuniao_interna','mediacao_cejusc','balcao_virtual','ligacao');
    $modalidadesValidas = array('presencial','online','nao_aplicavel');

    if (!$titulo) { echo json_encode(array('error' => 'Título é obrigatório')); exit; }
    if (!$dataInicio) { echo json_encode(array('error' => 'Data de início é obrigatória')); exit; }
    if (!in_array($tipo, $tiposValidos)) $tipo = 'reuniao_cliente';
    if (!in_array($modalidade, $modalidadesValidas)) $modalidade = 'nao_aplicavel';
    if (!$dataFim) $dataFim = $dataInicio;

    if ($tipo === 'balcao_virtual' && !$diaTodo) {
        list($ok, $msg) = _balcao_valida_horario($dataInicio);
        if (!$ok) { echo json_encode(array('error' => $msg, 'csrf' => $newCsrf)); exit; }
    }

    if ($id) {
        // Editar — todos os usuários logados podem editar compromissos
        $stmt = $pdo->prepare(
            "UPDATE agenda_eventos SET titulo=?, tipo=?, modalidade=?, data_inicio=?, data_fim=?, dia_todo=?,
             local=?, meet_link=?, descricao=?, client_id=?, case_id=?, responsavel_id=?,
             msg_cliente=?, lembrete_email=?, lembrete_whatsapp=?, lembrete_portal=?, lembrete_cliente=?,
             updated_at=NOW() WHERE id=?"
        );
        $stmt->execute(array(
            $titulo, $tipo, $modalidade, $dataInicio, $dataFim, $diaTodo,
            $local, $meetLink, $descricao, $clientId, $caseId, $responsavelId,
            $msgCliente, $lembreteEmail, $lembreteWa, $lembretePortal, $lembreteCliente,
            $id
        ));
        audit_log('AGENDA_EDITADO', 'agenda', $id, $titulo);
        echo json_encode(array('ok' => true, 'id' => $id, 'msg' => 'Evento atualizado', 'csrf' => $newCsrf));
    } else {
        // Criar
        try {
            // Tipos visíveis ao cliente automaticamente na Central VIP
            $tiposVisiveis = array('audiencia', 'reuniao_cliente', 'onboard');
            $visivelCliente = in_array($tipo, $tiposVisiveis) ? 1 : 0;

            $stmt = $pdo->prepare(
                "INSERT INTO agenda_eventos (titulo, tipo, modalidade, data_inicio, data_fim, dia_todo,
                 local, meet_link, descricao, client_id, case_id, responsavel_id,
                 msg_cliente, lembrete_email, lembrete_whatsapp, lembrete_portal, lembrete_cliente,
                 visivel_cliente, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'agendado',?)"
            );
            $stmt->execute(array(
                $titulo, $tipo, $modalidade, $dataInicio, $dataFim, $diaTodo,
                $local ?: null, $meetLink ?: null, $descricao ?: null, $clientId, $caseId, $responsavelId,
                $msgCliente ?: null, $lembreteEmail, $lembreteWa, $lembretePortal, $lembreteCliente,
                $visivelCliente, current_user_id()
            ));
            $newId = (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            echo json_encode(array('error' => 'Erro BD: ' . $e->getMessage(), 'csrf' => $newCsrf));
            exit;
        }

        // Notificar responsável se diferente do criador
        if ($responsavelId && $responsavelId !== current_user_id()) {
            try {
                notify($responsavelId, 'Novo compromisso', $titulo . ' em ' . date('d/m/Y H:i', strtotime($dataInicio)),
                    'info', url('modules/agenda/?evento=' . $newId), '📅');
            } catch (Exception $e) { /* silenciar */ }
        }

        // Registrar andamento no processo quando vinculado a um caso.
        // prazo e reuniao_interna ficam fora: prazo tem cascade próprio (prazos_processuais);
        // reuniao_interna é de staff e não entra no timeline do processo.
        $tiposAndamento = array('audiencia','reuniao_cliente','onboarding','mediacao_cejusc','balcao_virtual','ligacao');
        if ($caseId && in_array($tipo, $tiposAndamento, true)) {
            try {
                $rotulos = array(
                    'audiencia'       => 'Audiência',
                    'reuniao_cliente' => 'Reunião com cliente',
                    'onboarding'      => 'Onboarding',
                    'mediacao_cejusc' => 'Mediação/CEJUSC',
                    'balcao_virtual'  => 'Balcão Virtual',
                    'ligacao'         => 'Ligação/Retorno',
                );
                $rotulo = $rotulos[$tipo] ?? 'Compromisso';
                $dtEv  = strtotime($dataInicio);
                $dataHumana = date('d/m/Y \à\s H:i', $dtEv);
                $descAnd  = "📅 {$rotulo} agendada: {$titulo}\n";
                $descAnd .= "🗓 Data: {$dataHumana}\n";
                if ($modalidade === 'online') {
                    $descAnd .= "🎥 Modalidade: Online" . ($meetLink ? " — {$meetLink}" : '') . "\n";
                } elseif ($modalidade === 'presencial') {
                    $descAnd .= "🏛 Modalidade: Presencial" . ($local ? " — {$local}" : '') . "\n";
                } elseif ($local) {
                    $descAnd .= "📍 Local: {$local}\n";
                }

                // Link de orientação sobre audiência — mesmo link da mensagem WhatsApp
                // enviada ao cliente (ver msgsPadrao.audiencia em modules/agenda/index.php).
                if ($tipo === 'audiencia') {
                    $descAnd .= "\nℹ️ Orientações sobre a audiência: https://www.ferreiraesa.com.br/audiencias/";
                }

                $tipoAnd = ($tipo === 'audiencia') ? 'audiencia' : 'observacao';
                $visAnd  = $visivelCliente; // herda da lógica da agenda

                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, visivel_cliente, created_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())"
                )->execute(array(
                    $caseId,
                    date('Y-m-d', $dtEv),
                    $tipoAnd,
                    trim($descAnd),
                    $visAnd,
                    current_user_id()
                ));
            } catch (Exception $e) { /* não bloqueia a criação do evento */ }
        }

        audit_log('AGENDA_CRIADO', 'agenda', $newId, $titulo);
        echo json_encode(array('ok' => true, 'id' => $newId, 'msg' => 'Evento criado', 'csrf' => $newCsrf));
    }
    exit;
}

// ── ANEXAR DOCUMENTO A COMPROMISSO ──
if ($action === 'anexar_documento') {
    $id = (int)($_POST['id'] ?? 0);
    $caseId = (int)($_POST['case_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');
    $visivel = (int)($_POST['visivel_cliente'] ?? 0);

    if (!$id || !$caseId || !$clientId || !$titulo) {
        echo json_encode(array('error' => 'Dados incompletos', 'csrf' => $newCsrf));
        exit;
    }

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(array('error' => 'Arquivo obrigatório', 'csrf' => $newCsrf));
        exit;
    }

    $file = $_FILES['arquivo'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = array('pdf','jpg','jpeg','png','webp','gif','doc','docx');
    if (!in_array($ext, $allowedExt)) {
        echo json_encode(array('error' => 'Formato não permitido (PDF, imagem ou Word).', 'csrf' => $newCsrf));
        exit;
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(array('error' => 'Arquivo maior que 10MB', 'csrf' => $newCsrf));
        exit;
    }

    $uploadDir = dirname(APP_ROOT) . '/salavip/uploads/ged/';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

    $filename = uniqid('ged_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(array('error' => 'Erro ao salvar arquivo', 'csrf' => $newCsrf));
        exit;
    }

    try {
        $desc = $observacao ?: ('Documento anexado ao compromisso #' . $id);

        $pdo->prepare(
            "INSERT INTO salavip_ged (cliente_id, processo_id, titulo, descricao, categoria, arquivo_path, arquivo_nome, visivel_cliente, compartilhado_por, compartilhado_em)
             VALUES (?, ?, ?, ?, 'Compromisso', ?, ?, ?, ?, NOW())"
        )->execute(array($clientId, $caseId, $titulo, $desc, $filename, $file['name'], $visivel, current_user_id()));

        audit_log('AGENDA_ANEXO', 'agenda', $id, "Anexo: $titulo (visível cliente: " . ($visivel ? 'sim' : 'não') . ')');
        echo json_encode(array('ok' => true, 'msg' => 'Documento anexado', 'visivel_cliente' => $visivel ? true : false, 'csrf' => $newCsrf));
    } catch (Exception $e) {
        @unlink($filepath);
        echo json_encode(array('error' => 'Erro BD: ' . $e->getMessage(), 'csrf' => $newCsrf));
    }
    exit;
}

// ── MARCAR STATUS COM ANEXO (balcão virtual) ──
if ($action === 'status_com_anexo') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'realizado';
    $caseId = (int)($_POST['case_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    $observacao = trim($_POST['observacao'] ?? '');

    if (!$id || !$caseId || !$clientId) {
        echo json_encode(array('error' => 'Dados incompletos', 'csrf' => $newCsrf));
        exit;
    }

    $semImagem = isset($_POST['sem_imagem']) && $_POST['sem_imagem'] === '1';
    $temArquivo = isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK;

    if (!$semImagem && !$temArquivo) {
        echo json_encode(array('error' => 'Anexe um arquivo ou marque "Balcão sem imagem".', 'csrf' => $newCsrf));
        exit;
    }

    $filename = null;
    $filepath = null;

    if ($temArquivo) {
        $file = $_FILES['arquivo'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = array('pdf','jpg','jpeg','png','webp','gif');
        if (!in_array($ext, $allowedExt)) {
            echo json_encode(array('error' => 'Formato não permitido. Use PDF ou imagem.', 'csrf' => $newCsrf));
            exit;
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(array('error' => 'Arquivo maior que 10MB', 'csrf' => $newCsrf));
            exit;
        }

        $uploadDir = dirname(APP_ROOT) . '/salavip/uploads/ged/';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

        $filename = uniqid('ged_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $filepath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            echo json_encode(array('error' => 'Erro ao salvar arquivo', 'csrf' => $newCsrf));
            exit;
        }
    }

    try {
        $stmtEv = $pdo->prepare("SELECT e.titulo, e.data_inicio, cs.title as case_title FROM agenda_eventos e LEFT JOIN cases cs ON cs.id = e.case_id WHERE e.id = ?");
        $stmtEv->execute(array($id));
        $ev = $stmtEv->fetch();
        $titulo = 'Balcão Virtual — ' . date('d/m/Y', strtotime($ev['data_inicio'] ?? 'now'));

        if ($temArquivo) {
            $desc = 'Comprovante do balcão virtual realizado no processo ' . ($ev['case_title'] ?? '') . '.' . ($observacao ? "\n\n" . $observacao : '');
            $pdo->prepare(
                "INSERT INTO salavip_ged (cliente_id, processo_id, titulo, descricao, categoria, arquivo_path, arquivo_nome, visivel_cliente, compartilhado_por, compartilhado_em)
                 VALUES (?, ?, ?, ?, 'Balcão Virtual', ?, ?, 1, ?, NOW())"
            )->execute(array($clientId, $caseId, $titulo, $desc, $filename, $file['name'], current_user_id()));
        }

        $pdo->prepare("UPDATE agenda_eventos SET status=?, updated_at=NOW() WHERE id=?")->execute(array($status, $id));

        try {
            $andDesc = $semImagem
                ? 'Balcão Virtual realizado (por telefone, sem comprovante em imagem).' . ($observacao ? "\n\nObs: " . $observacao : '')
                : 'Balcão Virtual realizado com sucesso. Comprovante anexado e disponibilizado na Central VIP.' . ($observacao ? "\n\nObs: " . $observacao : '');
            $pdo->prepare(
                "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, visivel_cliente, created_by, created_at)
                 VALUES (?, CURDATE(), 'Balcão Virtual', ?, 1, ?, NOW())"
            )->execute(array($caseId, $andDesc, current_user_id()));
        } catch (Exception $e) {}

        $logMsg = $semImagem ? 'Balcão virtual realizado por telefone (sem imagem)' : 'Balcão virtual realizado com comprovante';
        audit_log('AGENDA_BALCAO_REALIZADO', 'agenda', $id, $logMsg);
        echo json_encode(array('ok' => true, 'msg' => $logMsg, 'csrf' => $newCsrf));
    } catch (Exception $e) {
        @unlink($filepath);
        echo json_encode(array('error' => 'Erro BD: ' . $e->getMessage(), 'csrf' => $newCsrf));
    }
    exit;
}

// ── MARCAR STATUS ──
if ($action === 'status') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $statusValidos = array('agendado','realizado','cancelado','remarcado','nao_compareceu');
    if (!$id || !in_array($status, $statusValidos)) {
        echo json_encode(array('error' => 'Dados inválidos'));
        exit;
    }

    $canEdit = has_min_role('gestao');
    if (!$canEdit) {
        $stmt = $pdo->prepare("SELECT responsavel_id FROM agenda_eventos WHERE id = ?");
        $stmt->execute(array($id));
        $ev = $stmt->fetch();
        if (!$ev || (int)$ev['responsavel_id'] !== current_user_id()) {
            echo json_encode(array('error' => 'Sem permissão'));
            exit;
        }
    }

    $pdo->prepare("UPDATE agenda_eventos SET status=?, updated_at=NOW() WHERE id=?")->execute(array($status, $id));
    audit_log('AGENDA_STATUS', 'agenda', $id, 'Status: ' . $status);
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

// ── REMARCAR (atualizar data/hora) ──
if ($action === 'remarcar') {
    $id = (int)($_POST['id'] ?? 0);
    $novaData = $_POST['nova_data'] ?? '';
    $novaHora = $_POST['nova_hora'] ?? '';
    if (!$id || !$novaData || !$novaHora) {
        echo json_encode(array('error' => 'Dados incompletos'));
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData) || !preg_match('/^\d{2}:\d{2}$/', $novaHora)) {
        echo json_encode(array('error' => 'Formato de data/hora inválido'));
        exit;
    }
    $stmt = $pdo->prepare("SELECT tipo FROM agenda_eventos WHERE id = ?");
    $stmt->execute(array($id));
    $evTipo = $stmt->fetchColumn();
    if ($evTipo === 'balcao_virtual') {
        list($ok, $msg) = _balcao_valida_horario($novaData . ' ' . $novaHora . ':00');
        if (!$ok) { echo json_encode(array('error' => $msg, 'csrf' => $newCsrf)); exit; }
    }
    $pdo->prepare("UPDATE agenda_eventos SET data_inicio=?, hora_inicio=?, status='agendado', updated_at=NOW() WHERE id=?")
        ->execute(array($novaData, $novaHora . ':00', $id));
    audit_log('AGENDA_REMARCAR', 'agenda', $id, 'Remarcado para ' . $novaData . ' ' . $novaHora);
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

// ── REMARCAR (criar novo evento) ──
if ($action === 'remarcar_novo') {
    $id = (int)($_POST['id'] ?? 0);
    $novaData = $_POST['nova_data'] ?? '';
    $novaHora = $_POST['nova_hora'] ?? '';
    if (!$id || !$novaData || !$novaHora) {
        echo json_encode(array('error' => 'Dados incompletos'));
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData) || !preg_match('/^\d{2}:\d{2}$/', $novaHora)) {
        echo json_encode(array('error' => 'Formato de data/hora inválido'));
        exit;
    }

    // Buscar evento original
    $stmt = $pdo->prepare("SELECT * FROM agenda_eventos WHERE id = ?");
    $stmt->execute(array($id));
    $original = $stmt->fetch();
    if (!$original) {
        echo json_encode(array('error' => 'Evento não encontrado'));
        exit;
    }

    if (($original['tipo'] ?? '') === 'balcao_virtual' && empty($original['dia_todo'])) {
        list($ok, $msg) = _balcao_valida_horario($novaData . ' ' . $novaHora . ':00');
        if (!$ok) { echo json_encode(array('error' => $msg, 'csrf' => $newCsrf)); exit; }
    }

    // 1. Marcar original como remarcado
    $pdo->prepare("UPDATE agenda_eventos SET status='remarcado', updated_at=NOW() WHERE id=?")
        ->execute(array($id));

    // 2. Criar novo evento com título "REMARCAÇÃO — ..."
    $tituloOriginal = $original['titulo'];
    // Evitar acumular prefixos se já é uma remarcação
    $tituloNovo = (strpos($tituloOriginal, 'REMARCA') === 0) ? $tituloOriginal : ('REMARCAÇÃO — ' . $tituloOriginal);

    // Calcular duração original para manter data_fim proporcional
    $dtIni = new DateTime($original['data_inicio']);
    $dtFim = $original['data_fim'] ? new DateTime($original['data_fim']) : null;
    $duracao = $dtFim ? $dtIni->diff($dtFim) : null;
    $novaInicio = $novaData . ' ' . $novaHora . ':00';
    $novaFim = null;
    if ($duracao) {
        $novaFimDt = new DateTime($novaInicio);
        $novaFimDt->add($duracao);
        $novaFim = $novaFimDt->format('Y-m-d H:i:s');
    }

    $pdo->prepare(
        "INSERT INTO agenda_eventos (titulo, tipo, modalidade, data_inicio, data_fim, dia_todo,
         local, meet_link, descricao, client_id, case_id, responsavel_id,
         msg_cliente, lembrete_email, lembrete_whatsapp, lembrete_portal, lembrete_cliente,
         status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'agendado',?)"
    )->execute(array(
        $tituloNovo, $original['tipo'], $original['modalidade'],
        $novaInicio, $novaFim, $original['dia_todo'],
        $original['local'], $original['meet_link'],
        'Remarcação do evento #' . $id . '. ' . ($original['descricao'] ?: ''),
        $original['client_id'], $original['case_id'], $original['responsavel_id'],
        $original['msg_cliente'],
        $original['lembrete_email'], $original['lembrete_whatsapp'],
        $original['lembrete_portal'], $original['lembrete_cliente'],
        current_user_id()
    ));
    $novoId = (int)$pdo->lastInsertId();

    audit_log('AGENDA_REMARCACAO', 'agenda', $novoId, 'Remarcação do evento #' . $id . ' para ' . $novaData . ' ' . $novaHora);
    echo json_encode(array('ok' => true, 'novo_id' => $novoId, 'csrf' => $newCsrf));
    exit;
}

// ── EXCLUIR ──
if ($action === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'ID inválido')); exit; }

    if (!has_min_role('gestao')) {
        $stmt = $pdo->prepare("SELECT responsavel_id FROM agenda_eventos WHERE id = ?");
        $stmt->execute(array($id));
        $ev = $stmt->fetch();
        if (!$ev || (int)$ev['responsavel_id'] !== current_user_id()) {
            echo json_encode(array('error' => 'Sem permissão'));
            exit;
        }
    }

    $pdo->prepare("UPDATE agenda_eventos SET status='cancelado', updated_at=NOW() WHERE id=?")->execute(array($id));
    audit_log('AGENDA_CANCELADO', 'agenda', $id, '');
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

// ── GERAR MEET ──
if ($action === 'gerar_meet') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'ID do evento obrigatório')); exit; }

    // Buscar evento
    $stmt = $pdo->prepare("SELECT * FROM agenda_eventos WHERE id = ?");
    $stmt->execute(array($id));
    $ev = $stmt->fetch();
    if (!$ev) { echo json_encode(array('error' => 'Evento não encontrado')); exit; }

    // Se já tem meet_link, retornar
    if ($ev['meet_link']) {
        echo json_encode(array('ok' => true, 'meet_link' => $ev['meet_link'], 'already' => true));
        exit;
    }

    // URL do webhook Google Apps Script
    $webhookUrl = 'https://script.google.com/macros/s/AKfycbzSOi9FIJCdRcInFwxAMy2sgOAqxnI7L5XwXXzMUGw1jmRqV4HcH5231itDYAhy8Qac/exec';

    // Buscar nome do cliente e e-mail
    $clientName = '';
    $clientEmail = '';
    if ($ev['client_id']) {
        $cs = $pdo->prepare("SELECT name, email FROM clients WHERE id = ?");
        $cs->execute(array($ev['client_id']));
        $cr = $cs->fetch();
        if ($cr) { $clientName = $cr['name']; $clientEmail = $cr['email'] ?: ''; }
    }

    // Buscar e-mail do responsável
    $participantes = array();
    if ($ev['responsavel_id']) {
        $rs = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $rs->execute(array($ev['responsavel_id']));
        $rr = $rs->fetch();
        if ($rr && $rr['email']) $participantes[] = $rr['email'];
    }
    // Incluir e-mails extras enviados pelo frontend
    $extrasEmails = isset($_POST['participantes']) ? $_POST['participantes'] : '';
    if ($extrasEmails) {
        foreach (explode(',', $extrasEmails) as $em) {
            $em = trim($em);
            if ($em && strpos($em, '@') !== false && !in_array($em, $participantes)) {
                $participantes[] = $em;
            }
        }
    }

    // Montar payload
    $payload = json_encode(array(
        'key'           => 'fsa-meet-2026',
        'titulo'        => $ev['titulo'] . ($clientName ? ' — ' . $clientName : ''),
        'inicio'        => date('c', strtotime($ev['data_inicio'])),
        'fim'           => $ev['data_fim'] ? date('c', strtotime($ev['data_fim'])) : null,
        'descricao'     => $ev['descricao'] ?: 'Ferreira & Sá Advocacia',
        'participantes' => $participantes,
    ), JSON_UNESCAPED_UNICODE);

    // Chamar webhook com retry (Google pode dar Rate Limit)
    $respData = null;
    $lastErr = '';
    for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $lastErr = 'cURL: ' . $curlErr;
            error_log('[agenda/gerar_meet] Tentativa ' . $tentativa . ' cURL error: ' . $curlErr);
            sleep(2);
            continue;
        }

        $respData = json_decode($response, true);
        if ($respData && isset($respData['ok']) && $respData['ok']) {
            break; // Sucesso
        }

        $lastErr = isset($respData['error']) ? $respData['error'] : 'Resposta inesperada';
        error_log('[agenda/gerar_meet] Tentativa ' . $tentativa . ': ' . $lastErr);

        // Se for Rate Limit, esperar e tentar de novo
        if (strpos($lastErr, 'Rate Limit') !== false || strpos($lastErr, 'rate limit') !== false || $httpCode === 429) {
            sleep(3);
            continue;
        }

        break; // Outro erro, não tentar de novo
    }

    if (!$respData || !isset($respData['ok']) || !$respData['ok']) {
        echo json_encode(array('error' => 'Google Apps Script: ' . $lastErr, 'csrf' => $newCsrf));
        exit;
    }

    $meetLink = $respData['meet_link'] ?? '';
    $googleEventId = $respData['event_id'] ?? '';

    if (!$meetLink) {
        echo json_encode(array('error' => 'Google não retornou link do Meet'));
        exit;
    }

    // Salvar no banco
    $pdo->prepare("UPDATE agenda_eventos SET meet_link = ?, google_event_id = ?, updated_at = NOW() WHERE id = ?")
        ->execute(array($meetLink, $googleEventId, $id));

    audit_log('MEET_GERADO', 'agenda', $id, $meetLink);
    echo json_encode(array('ok' => true, 'meet_link' => $meetLink, 'google_event_id' => $googleEventId, 'csrf' => $newCsrf));
    exit;
}

// ── ENVIAR CONVITE (adicionar participantes ao evento Google) ──
if ($action === 'enviar_convite') {
    $id = (int)($_POST['id'] ?? 0);
    $emails = trim($_POST['emails'] ?? '');
    if (!$id || !$emails) { echo json_encode(array('error' => 'ID e e-mails obrigatórios', 'csrf' => $newCsrf)); exit; }

    $stmt = $pdo->prepare("SELECT * FROM agenda_eventos WHERE id = ?");
    $stmt->execute(array($id));
    $ev = $stmt->fetch();
    if (!$ev) { echo json_encode(array('error' => 'Evento não encontrado', 'csrf' => $newCsrf)); exit; }

    if (!$ev['google_event_id']) {
        echo json_encode(array('error' => 'Evento não tem Google Calendar vinculado. Gere o Meet primeiro.', 'csrf' => $newCsrf));
        exit;
    }

    // Buscar nome do cliente
    $clientName = '';
    if ($ev['client_id']) {
        $cs = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $cs->execute(array($ev['client_id']));
        $cr = $cs->fetch();
        if ($cr) $clientName = $cr['name'];
    }

    $emailList = array();
    foreach (explode(',', $emails) as $em) {
        $em = trim($em);
        if ($em && strpos($em, '@') !== false) $emailList[] = $em;
    }
    if (!$emailList) { echo json_encode(array('error' => 'Nenhum e-mail válido', 'csrf' => $newCsrf)); exit; }

    $webhookUrl = 'https://script.google.com/macros/s/AKfycbzSOi9FIJCdRcInFwxAMy2sgOAqxnI7L5XwXXzMUGw1jmRqV4HcH5231itDYAhy8Qac/exec';

    $payload = json_encode(array(
        'key'           => 'fsa-meet-2026',
        'action'        => 'add_guests',
        'event_id'      => $ev['google_event_id'],
        'participantes' => $emailList,
    ), JSON_UNESCAPED_UNICODE);

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    curl_close($ch);

    $respData = json_decode($response, true);
    if (!$respData || !isset($respData['ok']) || !$respData['ok']) {
        $errMsg = isset($respData['error']) ? $respData['error'] : 'Erro ao enviar convites';
        echo json_encode(array('error' => $errMsg, 'csrf' => $newCsrf));
        exit;
    }

    // Salvar participantes no banco
    $existingPart = $ev['participantes'] ? $ev['participantes'] : '';
    $allPart = $existingPart ? $existingPart . ',' . implode(',', $emailList) : implode(',', $emailList);
    $pdo->prepare("UPDATE agenda_eventos SET participantes = ?, updated_at = NOW() WHERE id = ?")->execute(array($allPart, $id));

    audit_log('CONVITE_ENVIADO', 'agenda', $id, implode(', ', $emailList));
    echo json_encode(array('ok' => true, 'enviados' => count($emailList), 'csrf' => $newCsrf));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
