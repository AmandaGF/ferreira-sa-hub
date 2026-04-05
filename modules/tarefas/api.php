<?php
/**
 * Kanban de Tarefas — API CRUD
 * Cascade: prazo → prazos_processuais + agenda_eventos
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$userId = current_user_id();

// ── GET ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'listar';

    if ($action === 'listar') {
        $responsavel = (int)($_GET['responsavel'] ?? 0);
        $tipo = $_GET['tipo'] ?? '';
        $prioridade = $_GET['prioridade'] ?? '';
        $caseFilter = (int)($_GET['case_id'] ?? 0);

        $where = array("t.tipo IS NOT NULL AND t.tipo != ''");
        $params = array();

        if ($responsavel) { $where[] = 't.assigned_to = ?'; $params[] = $responsavel; }
        if ($tipo) { $where[] = 't.tipo = ?'; $params[] = $tipo; }
        if ($prioridade) { $where[] = 't.prioridade = ?'; $params[] = $prioridade; }
        if ($caseFilter) { $where[] = 't.case_id = ?'; $params[] = $caseFilter; }

        // Colaborador vê só suas
        if (has_role('colaborador')) { $where[] = 't.assigned_to = ?'; $params[] = $userId; }

        $sql = "SELECT t.*, cs.title as case_title, cs.case_number, cs.case_type,
                       c.name as client_name, u.name as assigned_name
                FROM case_tasks t
                LEFT JOIN cases cs ON cs.id = t.case_id
                LEFT JOIN clients c ON c.id = cs.client_id
                LEFT JOIN users u ON u.id = t.assigned_to
                WHERE " . implode(' AND ', $where) . "
                ORDER BY FIELD(t.prioridade,'urgente','alta','normal','baixa'), t.due_date ASC, t.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT t.*, cs.title as case_title, c.name as client_name
            FROM case_tasks t LEFT JOIN cases cs ON cs.id=t.case_id LEFT JOIN clients c ON c.id=cs.client_id WHERE t.id=?");
        $stmt->execute(array($id));
        $task = $stmt->fetch();
        echo json_encode($task ?: array('error' => 'Não encontrada'));
        exit;
    }

    // Buscar processos (autocomplete)
    if ($action === 'busca_caso') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo '[]'; exit; }
        $stmt = $pdo->prepare(
            "SELECT cs.id, cs.title, cs.case_number, c.name as client_name
             FROM cases cs LEFT JOIN clients c ON c.id=cs.client_id
             WHERE cs.title LIKE ? OR cs.case_number LIKE ? OR c.name LIKE ? LIMIT 15"
        );
        $stmt->execute(array('%'.$q.'%','%'.$q.'%','%'.$q.'%'));
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'calcular_prazo') {
        $dataDisp = $_GET['data_disp'] ?? '';
        $qtd = (int)($_GET['qtd'] ?? 15);
        $unidade = ($_GET['unidade'] ?? 'dias') === 'meses' ? 'meses' : 'dias';
        $comarca = $_GET['comarca'] ?? null;
        if (!$comarca) $comarca = null;

        if (!$dataDisp || $qtd < 1) {
            echo json_encode(array('erro' => 'Dados incompletos'));
            exit;
        }

        $r = calcular_prazo_completo($dataDisp, $qtd, $unidade, $comarca);

        echo json_encode(array(
            'publicacao_fmt'    => date('d/m/Y', strtotime($r['publicacao'])),
            'inicio_fmt'        => date('d/m/Y', strtotime($r['inicio_contagem'])),
            'data_fatal'        => $r['data_fatal'],
            'fatal_fmt'         => date('d/m/Y', strtotime($r['data_fatal'])),
            'dia_semana_fatal'  => $r['dia_semana_fatal'],
            'data_seguranca'    => $r['data_seguranca'],
            'seguranca_fmt'     => date('d/m/Y', strtotime($r['data_seguranca'])),
            'dia_semana_seg'    => $r['dia_semana_seg'],
            'dias_ate_prazo'    => $r['dias_ate_prazo'],
            'suspensoes_count'  => count($r['suspensoes']),
        ));
        exit;
    }

    if ($action === 'buscar_comarca') {
        $caseId = (int)($_GET['case_id'] ?? 0);
        $comarca = '';
        if ($caseId) {
            $stmt = $pdo->prepare("SELECT comarca FROM cases WHERE id = ?");
            $stmt->execute(array($caseId));
            $comarca = $stmt->fetchColumn() ?: '';
        }
        echo json_encode(array('comarca' => $comarca));
        exit;
    }

    echo json_encode(array('error' => 'Ação GET inválida'));
    exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

if (!validate_csrf()) {
    echo json_encode(array('error' => 'Token CSRF inválido', 'csrf' => generate_csrf_token()));
    exit;
}
$newCsrf = generate_csrf_token();
$action = $_POST['action'] ?? '';

// ── CRIAR / EDITAR ──
if ($action === 'salvar') {
    $id          = (int)($_POST['id'] ?? 0);
    $caseId      = (int)($_POST['case_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $tipo        = $_POST['tipo'] ?? '';
    $tipoOutro   = trim($_POST['tipo_outro'] ?? '');
    $subtipo     = $_POST['subtipo'] ?? null;
    $descricao   = trim($_POST['descricao'] ?? '');
    $assignedTo  = (int)($_POST['assigned_to'] ?? $userId) ?: null;
    $dueDate     = $_POST['due_date'] ?? null;
    $prazoAlerta = $_POST['prazo_alerta'] ?? null;
    $prioridade  = $_POST['prioridade'] ?? 'normal';
    $status      = $_POST['status'] ?? 'a_fazer';

    if ($dueDate === '') $dueDate = null;
    if ($prazoAlerta === '') $prazoAlerta = null;
    if ($subtipo === '') $subtipo = null;

    if (!$title) { echo json_encode(array('error' => 'Título obrigatório', 'csrf' => $newCsrf)); exit; }
    if (!$caseId) { echo json_encode(array('error' => 'Processo obrigatório', 'csrf' => $newCsrf)); exit; }

    $tiposValidos = array('peticionar','juntar_documento','prazo','oficio','acordo','outros','');
    if (!in_array($tipo, $tiposValidos)) $tipo = '';
    $prioridadesValidas = array('urgente','alta','normal','baixa');
    if (!in_array($prioridade, $prioridadesValidas)) $prioridade = 'normal';
    $statusValidos = array('a_fazer','em_andamento','aguardando','concluido');
    if (!in_array($status, $statusValidos)) $status = 'a_fazer';

    // Se tipo=prazo, subtipo e due_date são obrigatórios
    if ($tipo === 'prazo') {
        if (!$subtipo) { echo json_encode(array('error' => 'Subtipo do prazo obrigatório', 'csrf' => $newCsrf)); exit; }
        if (!$dueDate) { echo json_encode(array('error' => 'Data fatal do prazo obrigatória', 'csrf' => $newCsrf)); exit; }
        if (!$prazoAlerta && $dueDate) {
            $prazoAlerta = date('Y-m-d', strtotime($dueDate . ' -3 days'));
        }
    }

    $completedAt = ($status === 'concluido') ? date('Y-m-d H:i:s') : null;

    if ($id) {
        // Editar
        $pdo->prepare(
            "UPDATE case_tasks SET case_id=?, title=?, tipo=?, tipo_outro=?, subtipo=?, descricao=?,
             assigned_to=?, due_date=?, prazo_alerta=?, prioridade=?, status=?, completed_at=COALESCE(?,completed_at)
             WHERE id=?"
        )->execute(array($caseId, $title, $tipo, $tipoOutro, $subtipo, $descricao,
            $assignedTo, $dueDate, $prazoAlerta, $prioridade, $status, $completedAt, $id));

        audit_log('TAREFA_EDITADA', 'task', $id, $title);
        echo json_encode(array('ok' => true, 'id' => $id, 'csrf' => $newCsrf));
    } else {
        // Criar
        $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM case_tasks WHERE case_id=?");
        $sort->execute(array($caseId));
        $nextOrder = (int)$sort->fetchColumn();

        $pdo->prepare(
            "INSERT INTO case_tasks (case_id, title, tipo, tipo_outro, subtipo, descricao,
             assigned_to, due_date, prazo_alerta, prioridade, status, sort_order, completed_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
        )->execute(array($caseId, $title, $tipo, $tipoOutro, $subtipo, $descricao,
            $assignedTo, $dueDate, $prazoAlerta, $prioridade, $status, $nextOrder, $completedAt));
        $taskId = (int)$pdo->lastInsertId();

        // ═══ CASCADE: Se tipo=prazo → criar prazos_processuais + agenda_eventos ═══
        if ($tipo === 'prazo' && $dueDate) {
            // Buscar dados do caso
            $caseRow = $pdo->prepare("SELECT title, client_id FROM cases WHERE id=?");
            $caseRow->execute(array($caseId));
            $caseData = $caseRow->fetch();
            $caseTitle = $caseData ? $caseData['title'] : 'Processo #' . $caseId;
            $clientId = $caseData ? (int)$caseData['client_id'] : null;

            $tituloEvento = ($subtipo ?: 'Prazo') . ' — ' . $caseTitle;

            // 1. Criar prazo processual
            $prazoId = null;
            try {
                $pdo->prepare(
                    "INSERT INTO prazos_processuais (case_id, tipo, prazo_fatal, prazo_alerta, concluido, created_at)
                     VALUES (?,?,?,?,0,NOW())"
                )->execute(array($caseId, $subtipo, $dueDate, $prazoAlerta));
                $prazoId = (int)$pdo->lastInsertId();
            } catch (Exception $e) {
                // Tabela pode não existir — criar
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS prazos_processuais (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        case_id INT NOT NULL,
                        tipo VARCHAR(50),
                        prazo_fatal DATE NOT NULL,
                        prazo_alerta DATE,
                        concluido TINYINT(1) DEFAULT 0,
                        cumprido_em DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_case (case_id),
                        INDEX idx_fatal (prazo_fatal)
                    )");
                    $pdo->prepare(
                        "INSERT INTO prazos_processuais (case_id, tipo, prazo_fatal, prazo_alerta, concluido, created_at)
                         VALUES (?,?,?,?,0,NOW())"
                    )->execute(array($caseId, $subtipo, $dueDate, $prazoAlerta));
                    $prazoId = (int)$pdo->lastInsertId();
                } catch (Exception $e2) { error_log('[tarefas] Erro criar prazo: ' . $e2->getMessage()); }
            }

            // 2. Criar evento na agenda
            $agendaId = null;
            try {
                $pdo->prepare(
                    "INSERT INTO agenda_eventos (titulo, tipo, modalidade, data_inicio, data_fim, dia_todo,
                     case_id, client_id, responsavel_id, status, created_by, lembrete_portal, lembrete_email)
                     VALUES (?,'prazo','nao_aplicavel',?,?,1,?,?,?,'agendado',?,1,1)"
                )->execute(array($tituloEvento, $dueDate . ' 09:00:00', $dueDate . ' 18:00:00',
                    $caseId, $clientId, $assignedTo, $userId));
                $agendaId = (int)$pdo->lastInsertId();
            } catch (Exception $e) { error_log('[tarefas] Erro criar evento: ' . $e->getMessage()); }

            // 3. Atualizar task com IDs gerados
            $pdo->prepare("UPDATE case_tasks SET prazo_id=?, agenda_id=? WHERE id=?")
                ->execute(array($prazoId, $agendaId, $taskId));
        }

        // Notificar responsável se diferente do criador
        if ($assignedTo && $assignedTo !== $userId) {
            notify($assignedTo, 'Nova tarefa', $title, 'info',
                url('modules/tarefas/'), '');
        }

        audit_log('TAREFA_CRIADA', 'task', $taskId, $tipo . ': ' . $title);
        echo json_encode(array('ok' => true, 'id' => $taskId, 'csrf' => $newCsrf));
    }
    exit;
}

// ── MOVER (drag-and-drop) ──
if ($action === 'mover') {
    $id = (int)($_POST['id'] ?? 0);
    $novoStatus = $_POST['status'] ?? '';
    $statusValidos = array('a_fazer','em_andamento','aguardando','concluido');
    if (!$id || !in_array($novoStatus, $statusValidos)) {
        echo json_encode(array('error' => 'Dados inválidos', 'csrf' => $newCsrf));
        exit;
    }

    $completedAt = ($novoStatus === 'concluido') ? date('Y-m-d H:i:s') : null;
    $pdo->prepare("UPDATE case_tasks SET status=?, completed_at=COALESCE(?,completed_at) WHERE id=?")
        ->execute(array($novoStatus, $completedAt, $id));

    // ═══ CASCADE: Se concluído e tipo=prazo → atualizar prazo e agenda ═══
    if ($novoStatus === 'concluido') {
        $task = $pdo->prepare("SELECT prazo_id, agenda_id FROM case_tasks WHERE id=?");
        $task->execute(array($id));
        $t = $task->fetch();
        if ($t) {
            if ($t['prazo_id']) {
                try { $pdo->prepare("UPDATE prazos_processuais SET concluido=1, cumprido_em=NOW() WHERE id=?")
                    ->execute(array($t['prazo_id'])); } catch (Exception $e) {}
            }
            if ($t['agenda_id']) {
                try { $pdo->prepare("UPDATE agenda_eventos SET status='realizado', updated_at=NOW() WHERE id=?")
                    ->execute(array($t['agenda_id'])); } catch (Exception $e) {}
            }
        }
    }

    audit_log('TAREFA_MOVIDA', 'task', $id, $novoStatus);
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

// ── EXCLUIR ──
if ($action === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'ID inválido', 'csrf' => $newCsrf)); exit; }
    $pdo->prepare("DELETE FROM case_tasks WHERE id=?")->execute(array($id));
    audit_log('TAREFA_EXCLUIDA', 'task', $id);
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
