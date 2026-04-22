<?php
/**
 * Tarefa por áudio — API.
 * Actions:
 *   transcrever: recebe áudio multipart → Groq Whisper + Claude Haiku → retorna preview
 *   salvar: cria case_task com os dados do preview (após usuário revisar)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if (!validate_csrf()) { echo json_encode(array('error' => 'Token inválido', 'csrf_expired' => true)); exit; }

require_once __DIR__ . '/../../core/functions_groq.php';
require_once __DIR__ . '/../../core/functions_bot_ia.php';

$pdo = db();
$action = $_POST['action'] ?? '';
$userId = current_user_id();

// ═══ TRANSCREVER + EXTRAIR ═══
if ($action === 'transcrever') {
    if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
        echo json_encode(array('error' => 'Áudio não recebido')); exit;
    }
    // Salva temp com extensão correta
    $ext = 'webm';
    $mime = $_FILES['audio']['type'] ?? 'audio/webm';
    if (strpos($mime, 'mp4') !== false) $ext = 'm4a';
    elseif (strpos($mime, 'ogg') !== false) $ext = 'ogg';
    elseif (strpos($mime, 'wav') !== false) $ext = 'wav';
    $tmpPath = sys_get_temp_dir() . '/tarefa_audio_' . uniqid('', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES['audio']['tmp_name'], $tmpPath)) {
        echo json_encode(array('error' => 'Falha ao salvar áudio temporário')); exit;
    }

    // 1. Transcrever com Groq Whisper
    $resp = groq_transcribe_file($tmpPath, $mime);
    @unlink($tmpPath);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Transcrição falhou: ' . ($resp['erro'] ?? '?'))); exit;
    }
    $transcricao = $resp['text'];
    if ($transcricao === '') {
        echo json_encode(array('error' => 'Áudio vazio ou inaudível')); exit;
    }

    // 2. Extrair campos com Claude Haiku
    $hoje = date('Y-m-d');
    $amanha = date('Y-m-d', strtotime('+1 day'));
    $systemPrompt = "Você recebe a transcrição de um áudio onde um advogado dita uma tarefa a ser cadastrada no sistema jurídico do escritório.\n\n"
        . "Retorne APENAS um objeto JSON válido (sem comentários, sem markdown, sem ```) com estes campos:\n"
        . "{\n"
        . '  "titulo": "resumo curto e objetivo da tarefa (max 100 chars)",' . "\n"
        . '  "descricao": "detalhes/contexto extraídos do áudio (pode ser string vazia)",' . "\n"
        . '  "prazo": "YYYY-MM-DD ou null se não houver prazo claro",' . "\n"
        . '  "prioridade": "normal | alta | urgente",' . "\n"
        . '  "cliente_nome": "nome do cliente mencionado (string) ou null",' . "\n"
        . '  "responsavel_nome": "primeiro nome do responsável mencionado ou null",' . "\n"
        . '  "tipo": "prazo | peticao | audiencia | reuniao | diligencia | outro"' . "\n"
        . "}\n\n"
        . "Interprete datas RELATIVAS pra ABSOLUTAS. Hoje é $hoje (amanhã é $amanha).\n"
        . "'Sexta' = próxima sexta. 'Semana que vem' = segunda-feira da próxima semana.\n"
        . "'Urgente/hoje mesmo' → prioridade=urgente. 'Até amanhã' → prioridade=alta.\n"
        . "Se não identificar um campo, use null (exceto titulo/descricao que são strings).";

    $claudeResp = bot_ia_chamar_claude($systemPrompt, array(
        array('role' => 'user', 'content' => 'Transcrição:\n\n' . $transcricao),
    ));
    if (!$claudeResp) {
        echo json_encode(array(
            'error' => 'Claude não respondeu',
            'transcricao' => $transcricao
        )); exit;
    }
    // Tenta parsear JSON (Claude pode envolver em ``` apesar do prompt)
    $clean = trim($claudeResp);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
    $clean = preg_replace('/\s*```$/', '', $clean);
    $extraido = json_decode($clean, true);
    if (!is_array($extraido)) {
        echo json_encode(array(
            'error' => 'Claude retornou formato inválido: ' . mb_substr($claudeResp, 0, 200),
            'transcricao' => $transcricao
        )); exit;
    }

    // 3. Buscar caso sugerido pelo nome do cliente
    $casosSugeridos = array();
    if (!empty($extraido['cliente_nome'])) {
        $like = '%' . preg_replace('/\s+/', '%', trim($extraido['cliente_nome'])) . '%';
        $sCaso = $pdo->prepare(
            "SELECT cs.id, cs.title, cs.case_number, cl.name AS client_name
             FROM cases cs
             JOIN clients cl ON cl.id = cs.client_id
             WHERE cl.name LIKE ? AND cs.status NOT IN ('arquivado','cancelado','concluido','finalizado')
             ORDER BY cs.updated_at DESC LIMIT 5"
        );
        $sCaso->execute(array($like));
        $casosSugeridos = $sCaso->fetchAll();
    }

    // 4. Buscar responsável sugerido pelo primeiro nome
    $respSugerido = null;
    if (!empty($extraido['responsavel_nome'])) {
        $sUser = $pdo->prepare(
            "SELECT id, name, wa_display_name FROM users
             WHERE is_active = 1 AND (name LIKE ? OR wa_display_name LIKE ?)
             ORDER BY (CASE WHEN name LIKE ? THEN 0 ELSE 1 END), id ASC LIMIT 1"
        );
        $likeNome = $extraido['responsavel_nome'] . '%';
        $sUser->execute(array($likeNome, $likeNome, $likeNome));
        $u = $sUser->fetch();
        if ($u) $respSugerido = $u;
    }

    echo json_encode(array(
        'ok' => true,
        'transcricao' => $transcricao,
        'extraido' => $extraido,
        'casos_sugeridos' => $casosSugeridos,
        'responsavel_sugerido' => $respSugerido,
    ));
    exit;
}

// ═══ SALVAR ═══
if ($action === 'salvar') {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $caseId = (int)($_POST['case_id'] ?? 0);
    $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: $userId;
    $prazo = $_POST['prazo'] ?? '';
    $prioridade = $_POST['prioridade'] ?? 'normal';
    $tipo = $_POST['tipo'] ?? 'outro';
    $transcricao = trim($_POST['transcricao'] ?? '');

    if ($titulo === '') { echo json_encode(array('error' => 'Título obrigatório')); exit; }
    if (!$caseId) { echo json_encode(array('error' => 'Selecione o processo vinculado')); exit; }
    // valida case
    $chk = $pdo->prepare("SELECT id, client_id FROM cases WHERE id = ?");
    $chk->execute(array($caseId));
    $caso = $chk->fetch();
    if (!$caso) { echo json_encode(array('error' => 'Processo inválido')); exit; }

    if (!in_array($prioridade, array('normal','alta','urgente'), true)) $prioridade = 'normal';
    if ($prazo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo)) $prazo = null;
    $prazoAlerta = $prazo ? date('Y-m-d', strtotime($prazo . ' -3 days')) : null;

    // Monta descricao final com transcrição como rodapé (pra rastreabilidade)
    $descricaoFinal = $descricao;
    if ($transcricao !== '') {
        $descricaoFinal .= ($descricaoFinal !== '' ? "\n\n" : '') . "🎙️ Transcrição original:\n" . $transcricao;
    }

    $pdo->prepare(
        "INSERT INTO case_tasks (case_id, title, descricao, tipo, due_date, prazo_alerta, status, prioridade, assigned_to, created_at)
         VALUES (?,?,?,?,?,?,'a_fazer',?,?,NOW())"
    )->execute(array($caseId, mb_substr($titulo, 0, 200), $descricaoFinal, $tipo, $prazo, $prazoAlerta, $prioridade, $assignedTo));
    $taskId = (int)$pdo->lastInsertId();

    audit_log('tarefa_audio_criada', 'case_tasks', $taskId, 'Via ditado: ' . mb_substr($titulo, 0, 80));

    // Notificar responsável (se não for o próprio criador)
    if ($assignedTo && $assignedTo !== $userId) {
        try {
            if (function_exists('notify')) {
                notify($assignedTo, '🎙️ Nova tarefa por áudio', $titulo, module_url('operacional', 'caso_ver.php?id=' . $caseId), ($prioridade === 'urgente'));
            }
            if (function_exists('push_notify')) {
                push_notify($assignedTo, '🎙️ Nova tarefa', $titulo, '/conecta/modules/operacional/caso_ver.php?id=' . $caseId, $prioridade === 'urgente');
            }
        } catch (Exception $e) {}
    }

    echo json_encode(array('ok' => true, 'task_id' => $taskId, 'case_id' => $caseId));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
