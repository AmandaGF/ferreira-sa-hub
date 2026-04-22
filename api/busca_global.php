<?php
/**
 * Busca global unificada — retorna resultados de clientes, processos,
 * leads, tarefas (do usuário) e wiki em um único endpoint.
 *
 * GET ?q=termo (mínimo 3 chars)
 * Response: { ok, grupos: { clientes:[], processos:[], leads:[], tarefas:[], wiki:[] } }
 */

require_once __DIR__ . '/../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$userId = current_user_id();

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 3) {
    echo json_encode(array('ok' => true, 'grupos' => new stdClass()));
    exit;
}

$like = '%' . $q . '%';
$likeDoc = '%' . preg_replace('/\D/', '', $q) . '%';
$grupos = array();

// ── CLIENTES ──
try {
    $stmt = $pdo->prepare(
        "SELECT id, name AS titulo, cpf AS subtitulo, phone
         FROM clients
         WHERE name LIKE ? OR cpf LIKE ? OR phone LIKE ?
         ORDER BY (name LIKE ?) DESC, name
         LIMIT 5"
    );
    $stmt->execute(array($like, $likeDoc, $likeDoc, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['clientes'] = array();
        foreach ($rows as $r) {
            $grupos['clientes'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'],
                'subtitulo' => $r['subtitulo'] ? 'CPF ' . $r['subtitulo'] : ($r['phone'] ? 'Tel ' . $r['phone'] : ''),
                'url'       => 'modules/clientes/ver.php?id=' . (int)$r['id'],
                'icon'      => '👤',
            );
        }
    }
} catch (Exception $e) {}

// ── PROCESSOS ── (busca também por nome do cliente e partes)
try {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT c.id, c.title AS titulo, c.case_number AS subtitulo, cl.name AS cliente_nome, c.updated_at
         FROM cases c
         LEFT JOIN clients cl ON cl.id = c.client_id
         LEFT JOIN case_partes cp ON cp.case_id = c.id
         WHERE c.title LIKE ?
            OR c.case_number LIKE ?
            OR cl.name LIKE ?
            OR cp.nome LIKE ?
         ORDER BY (c.title LIKE ?) DESC, c.updated_at DESC
         LIMIT 8"
    );
    $stmt->execute(array($like, $like, $like, $like, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['processos'] = array();
        foreach ($rows as $r) {
            $sub = $r['subtitulo'] ?: '';
            if ($r['cliente_nome']) $sub = ($sub ? $sub . ' • ' : '') . $r['cliente_nome'];
            $grupos['processos'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'] ?: 'Processo #' . $r['id'],
                'subtitulo' => $sub,
                'url'       => 'modules/operacional/caso_ver.php?id=' . (int)$r['id'],
                'icon'      => '⚖️',
            );
        }
    }
} catch (Exception $e) {}

// ── LEADS (PIPELINE) ──
try {
    $stmt = $pdo->prepare(
        "SELECT id, name AS titulo, phone AS subtitulo, stage
         FROM pipeline_leads
         WHERE (name LIKE ? OR phone LIKE ?) AND stage NOT IN ('finalizado','perdido','arquivado')
         ORDER BY (name LIKE ?) DESC, updated_at DESC
         LIMIT 5"
    );
    $stmt->execute(array($like, $likeDoc, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['leads'] = array();
        foreach ($rows as $r) {
            $grupos['leads'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'] ?: 'Lead #' . $r['id'],
                'subtitulo' => ($r['subtitulo'] ? 'Tel ' . $r['subtitulo'] : '') . ($r['stage'] ? ' • ' . str_replace('_', ' ', $r['stage']) : ''),
                'url'       => 'modules/pipeline/lead_ver.php?id=' . (int)$r['id'],
                'icon'      => '🎯',
            );
        }
    }
} catch (Exception $e) {}

// ── TAREFAS ── (ampliada: busca em título, descrição, cliente vinculado e caso; todas as tarefas do sistema)
try {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT t.id, t.title AS titulo, t.descricao, t.due_date, t.status, t.assigned_to, t.case_id,
                c.title AS case_title, cl.name AS cliente_nome, u.name AS responsavel_nome
         FROM case_tasks t
         LEFT JOIN cases c ON c.id = t.case_id
         LEFT JOIN clients cl ON cl.id = c.client_id
         LEFT JOIN users u ON u.id = t.assigned_to
         WHERE t.title LIKE ?
            OR t.descricao LIKE ?
            OR cl.name LIKE ?
            OR c.title LIKE ?
         ORDER BY (t.assigned_to = ?) DESC, (t.status != 'concluido') DESC, (t.title LIKE ?) DESC, t.due_date ASC
         LIMIT 8"
    );
    $stmt->execute(array($like, $like, $like, $like, $userId, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['tarefas'] = array();
        foreach ($rows as $r) {
            $sub = '';
            if ($r['status'] === 'concluido' || $r['status'] === 'feito') $sub = '✓ Concluída';
            elseif ($r['due_date']) $sub = 'Prazo: ' . date('d/m', strtotime($r['due_date']));
            if ($r['case_title']) $sub = ($sub ? $sub . ' • ' : '') . $r['case_title'];
            elseif ($r['cliente_nome']) $sub = ($sub ? $sub . ' • ' : '') . $r['cliente_nome'];
            if ($r['responsavel_nome'] && (int)$r['assigned_to'] !== $userId) {
                $sub = ($sub ? $sub . ' • ' : '') . 'de ' . explode(' ', $r['responsavel_nome'])[0];
            }
            $url = 'modules/tarefas/';
            if ($r['case_id']) $url = 'modules/operacional/caso_ver.php?id=' . (int)$r['case_id'] . '#tarefa-' . (int)$r['id'];
            $grupos['tarefas'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'],
                'subtitulo' => $sub,
                'url'       => $url,
                'icon'      => '📋',
            );
        }
    }
} catch (Exception $e) {}

// ── CHAMADOS (helpdesk) ──
try {
    $stmt = $pdo->prepare(
        "SELECT h.id, h.titulo, h.status, h.prioridade, h.created_at,
                cl.name AS cliente_nome, cs.title AS case_title
         FROM helpdesk_tickets h
         LEFT JOIN clients cl ON cl.id = h.client_id
         LEFT JOIN cases cs ON cs.id = h.caso_id
         WHERE h.titulo LIKE ?
            OR h.descricao LIKE ?
            OR cl.name LIKE ?
            OR cs.title LIKE ?
         ORDER BY h.created_at DESC
         LIMIT 5"
    );
    $stmt->execute(array($like, $like, $like, $like));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['chamados'] = array();
        foreach ($rows as $r) {
            $sub = $r['status'] ? ucfirst($r['status']) : '';
            if ($r['cliente_nome']) $sub = ($sub ? $sub . ' • ' : '') . $r['cliente_nome'];
            elseif ($r['case_title']) $sub = ($sub ? $sub . ' • ' : '') . $r['case_title'];
            $grupos['chamados'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'] ?: 'Chamado #' . $r['id'],
                'subtitulo' => $sub,
                'url'       => 'modules/helpdesk/ver.php?id=' . (int)$r['id'],
                'icon'      => '🎫',
            );
        }
    }
} catch (Exception $e) {}

// ── ANDAMENTOS (descrição) — acha texto solto em qualquer andamento ──
try {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.case_id, a.descricao, a.data_andamento, a.tipo,
                c.title AS case_title, cl.name AS cliente_nome
         FROM case_andamentos a
         LEFT JOIN cases c ON c.id = a.case_id
         LEFT JOIN clients cl ON cl.id = c.client_id
         WHERE a.descricao LIKE ?
         ORDER BY a.data_andamento DESC, a.id DESC
         LIMIT 5"
    );
    $stmt->execute(array($like));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['andamentos'] = array();
        foreach ($rows as $r) {
            $preview = mb_substr(preg_replace('/\s+/', ' ', (string)$r['descricao']), 0, 80, 'UTF-8');
            $sub = $r['case_title'] ?: ($r['cliente_nome'] ?: '');
            if ($r['data_andamento']) $sub = date('d/m/Y', strtotime($r['data_andamento'])) . ($sub ? ' • ' . $sub : '');
            $grupos['andamentos'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $preview,
                'subtitulo' => $sub,
                'url'       => 'modules/operacional/caso_ver.php?id=' . (int)$r['case_id'] . '#andamento-' . (int)$r['id'],
                'icon'      => '📝',
            );
        }
    }
} catch (Exception $e) {}

// ── WIKI ──
try {
    $stmt = $pdo->prepare(
        "SELECT id, titulo, categoria AS subtitulo
         FROM wiki_artigos
         WHERE titulo LIKE ? AND ativo = 1
         ORDER BY (titulo LIKE ?) DESC, titulo
         LIMIT 3"
    );
    $stmt->execute(array($like, $q . '%'));
    $rows = $stmt->fetchAll();
    if ($rows) {
        $grupos['wiki'] = array();
        foreach ($rows as $r) {
            $grupos['wiki'][] = array(
                'id'        => (int)$r['id'],
                'titulo'    => $r['titulo'],
                'subtitulo' => $r['subtitulo'] ?: '',
                'url'       => 'modules/wiki/ver.php?id=' . (int)$r['id'],
                'icon'      => '📚',
            );
        }
    }
} catch (Exception $e) {}

echo json_encode(array('ok' => true, 'grupos' => $grupos ?: new stdClass()));
