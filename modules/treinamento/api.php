<?php
/**
 * Treinamento — API interna.
 * Actions: marcar_conteudo, marcar_missao, salvar_quiz, progresso, ranking_treinamento, admin_progresso_equipe.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$user = current_user();
$userId = (int)$user['id'];

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';
$mutantes = array('marcar_conteudo','marcar_missao','salvar_quiz','resetar_progresso');
if (in_array($action, $mutantes, true)) {
    if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido')); exit; }
}

// Helper: garante linha em treinamento_progresso
function prog_upsert(PDO $pdo, $userId, $slug) {
    $pdo->prepare("INSERT IGNORE INTO treinamento_progresso (user_id, modulo_slug) VALUES (?, ?)")
        ->execute(array($userId, $slug));
}

// Helper: verifica se os 3 passos foram feitos e marca concluido + credita pontos
function prog_verificar_conclusao(PDO $pdo, $userId, $slug) {
    $stmt = $pdo->prepare("SELECT * FROM treinamento_progresso WHERE user_id = ? AND modulo_slug = ?");
    $stmt->execute(array($userId, $slug));
    $p = $stmt->fetch();
    if (!$p) return false;
    if ((int)$p['concluido'] === 1) return true;
    if (!$p['conteudo_visto'] || !$p['missao_feita'] || !$p['quiz_concluido']) return false;

    // Concluir + creditar pontos
    $m = $pdo->prepare("SELECT pontos FROM treinamento_modulos WHERE slug = ?");
    $m->execute(array($slug));
    $pontos = (int)$m->fetchColumn();

    $pdo->prepare("UPDATE treinamento_progresso SET concluido = 1, concluido_em = NOW(), pontos_ganhos = ? WHERE user_id = ? AND modulo_slug = ?")
        ->execute(array($pontos, $userId, $slug));

    // Integra com gamificação geral do Hub
    if (function_exists('gamificar')) {
        try { gamificar($userId, 'modulo_concluido', null, 'treinamento_modulos', $pontos); } catch (Exception $e) {}

        // Bonus: se concluiu TODOS os módulos, premia
        $totais = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM treinamento_modulos WHERE ativo = 1) AS total,
            (SELECT COUNT(*) FROM treinamento_progresso WHERE user_id = ? AND concluido = 1) AS concluidos");
        $totais->execute(array($userId));
        $t = $totais->fetch();
        if ((int)$t['total'] > 0 && (int)$t['total'] === (int)$t['concluidos']) {
            try { gamificar($userId, 'treinamento_completo', null, 'treinamento_modulos', 200); } catch (Exception $e) {}
        }
    }
    return true;
}

// ── MARCAR CONTEÚDO ────────────────────────────
if ($action === 'marcar_conteudo') {
    $slug = $_POST['slug'] ?? '';
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) { echo json_encode(array('error'=>'Slug inválido')); exit; }
    prog_upsert($pdo, $userId, $slug);
    $pdo->prepare("UPDATE treinamento_progresso SET conteudo_visto = 1 WHERE user_id = ? AND modulo_slug = ?")
        ->execute(array($userId, $slug));
    prog_verificar_conclusao($pdo, $userId, $slug);
    echo json_encode(array('ok' => true));
    exit;
}

// ── MARCAR MISSÃO ──────────────────────────────
if ($action === 'marcar_missao') {
    $slug = $_POST['slug'] ?? '';
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) { echo json_encode(array('error'=>'Slug inválido')); exit; }
    prog_upsert($pdo, $userId, $slug);
    $pdo->prepare("UPDATE treinamento_progresso SET missao_feita = 1 WHERE user_id = ? AND modulo_slug = ?")
        ->execute(array($userId, $slug));
    prog_verificar_conclusao($pdo, $userId, $slug);
    echo json_encode(array('ok' => true));
    exit;
}

// ── SALVAR QUIZ ────────────────────────────────
if ($action === 'salvar_quiz') {
    $slug = $_POST['slug'] ?? '';
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) { echo json_encode(array('error'=>'Slug inválido')); exit; }
    $respostas = json_decode($_POST['respostas'] ?? '{}', true);
    if (!is_array($respostas) || empty($respostas)) { echo json_encode(array('error'=>'Sem respostas')); exit; }

    // Carrega gabarito
    $quizStmt = $pdo->prepare("SELECT id, resposta_correta, explicacao FROM treinamento_quiz WHERE modulo_slug = ?");
    $quizStmt->execute(array($slug));
    $gabarito = array();
    foreach ($quizStmt->fetchAll() as $q) { $gabarito[(int)$q['id']] = $q; }

    if (empty($gabarito)) { echo json_encode(array('error'=>'Quiz não configurado')); exit; }

    $total = count($gabarito);
    $acertos = 0;
    $detalhes = array();
    foreach ($gabarito as $qid => $q) {
        $escolhida = $respostas[$qid] ?? '';
        $correta = $q['resposta_correta'];
        $ok = ($escolhida === $correta);
        if ($ok) $acertos++;
        $detalhes[] = array(
            'id' => $qid,
            'escolhida' => $escolhida,
            'correta' => $correta,
            'acertou' => $ok,
            'explicacao' => $q['explicacao'],
        );
    }

    $percentual = round($acertos / $total * 100);
    $atingiu = $percentual >= 70;

    prog_upsert($pdo, $userId, $slug);
    $pdo->prepare("UPDATE treinamento_progresso
                   SET quiz_acertos = ?, quiz_tentativas = quiz_tentativas + 1,
                       quiz_concluido = CASE WHEN ? THEN 1 ELSE quiz_concluido END
                   WHERE user_id = ? AND modulo_slug = ?")
        ->execute(array($acertos, $atingiu ? 1 : 0, $userId, $slug));

    $concluido = false;
    $pontosGanhos = 0;
    $pendencias = array();
    if ($atingiu) {
        prog_verificar_conclusao($pdo, $userId, $slug);
        $p = $pdo->prepare("SELECT concluido, conteudo_visto, missao_feita, pontos_ganhos FROM treinamento_progresso WHERE user_id = ? AND modulo_slug = ?");
        $p->execute(array($userId, $slug));
        $row = $p->fetch();
        $concluido = (bool)($row['concluido'] ?? false);
        $pontosGanhos = (int)($row['pontos_ganhos'] ?? 0);
        // Se quiz passou mas módulo ainda não concluiu, lista o que falta
        if (!$concluido) {
            if (!(int)($row['conteudo_visto'] ?? 0)) $pendencias[] = 'conteudo';
            if (!(int)($row['missao_feita'] ?? 0))   $pendencias[] = 'missao';
        }
        // Nota máxima = bônus
        if ($acertos === $total && function_exists('gamificar')) {
            try { gamificar($userId, 'quiz_nota_maxima', null, 'treinamento_quiz', 20); } catch (Exception $e) {}
        }
    }

    echo json_encode(array(
        'ok' => true,
        'acertos' => $acertos,
        'total' => $total,
        'percentual' => $percentual,
        'quiz_passou' => $atingiu,
        'concluido' => $concluido,
        'pendencias' => $pendencias,  // ['conteudo', 'missao'] — o que falta pra concluir
        'pontos' => $pontosGanhos,
        'detalhes' => $detalhes,
    ));
    exit;
}

// ── PROGRESSO (retorna todos módulos com status do usuário) ──
if ($action === 'progresso') {
    $sql = "SELECT m.slug, m.titulo, m.icone, m.pontos,
                   COALESCE(p.conteudo_visto, 0) AS conteudo_visto,
                   COALESCE(p.missao_feita, 0) AS missao_feita,
                   COALESCE(p.quiz_concluido, 0) AS quiz_concluido,
                   COALESCE(p.concluido, 0) AS concluido,
                   COALESCE(p.pontos_ganhos, 0) AS pontos_ganhos
            FROM treinamento_modulos m
            LEFT JOIN treinamento_progresso p ON p.modulo_slug = m.slug AND p.user_id = ?
            WHERE m.ativo = 1
            ORDER BY m.ordem";
    $s = $pdo->prepare($sql); $s->execute(array($userId));
    echo json_encode(array('ok' => true, 'modulos' => $s->fetchAll()));
    exit;
}

// ── RANKING DO TREINAMENTO ───────────────────
if ($action === 'ranking_treinamento') {
    $rows = $pdo->query(
        "SELECT u.id, u.name, u.setor,
                COUNT(CASE WHEN p.concluido = 1 THEN 1 END) AS concluidos,
                COALESCE(SUM(p.pontos_ganhos), 0) AS pontos
         FROM users u
         LEFT JOIN treinamento_progresso p ON p.user_id = u.id
         WHERE u.is_active = 1
         GROUP BY u.id
         HAVING pontos > 0 OR concluidos > 0
         ORDER BY pontos DESC, concluidos DESC
         LIMIT 20"
    )->fetchAll();
    echo json_encode(array('ok' => true, 'ranking' => $rows));
    exit;
}

// ── ADMIN: progresso da equipe inteira ──────
if ($action === 'admin_progresso_equipe') {
    if (!has_min_role('gestao')) { echo json_encode(array('error' => 'Sem permissão')); exit; }
    $rows = $pdo->query(
        "SELECT u.id, u.name, u.role, u.setor,
                (SELECT COUNT(*) FROM treinamento_modulos WHERE ativo = 1) AS total,
                COUNT(CASE WHEN p.concluido = 1 THEN 1 END) AS concluidos,
                MAX(p.updated_at) AS ultimo_acesso,
                COALESCE(SUM(p.pontos_ganhos), 0) AS pontos
         FROM users u
         LEFT JOIN treinamento_progresso p ON p.user_id = u.id
         WHERE u.is_active = 1
         GROUP BY u.id
         ORDER BY concluidos DESC, pontos DESC"
    )->fetchAll();
    echo json_encode(array('ok' => true, 'equipe' => $rows));
    exit;
}

// ── ADMIN: resetar progresso de um usuário pra retreinamento ──
if ($action === 'resetar_progresso') {
    if (!has_min_role('gestao')) { echo json_encode(array('error' => 'Sem permissão')); exit; }
    $targetUser = (int)($_POST['user_id'] ?? 0);
    if (!$targetUser) { echo json_encode(array('error' => 'user_id inválido')); exit; }
    $pdo->prepare("DELETE FROM treinamento_progresso WHERE user_id = ?")->execute(array($targetUser));
    audit_log('treinamento_reset', 'user', $targetUser, 'Progresso de treinamento resetado');
    echo json_encode(array('ok' => true));
    exit;
}

echo json_encode(array('error' => 'Ação desconhecida: ' . $action));
