<?php
/**
 * Ferreira & Sá Hub — API Gamificação / Ranking
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$userId = current_user_id();

// ── GET ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // ── Ranking Mensal ──────────────────────────────────────────
    if ($action === 'ranking_mensal') {
        $area = $_GET['area'] ?? 'comercial';
        if (!in_array($area, array('comercial', 'operacional'))) {
            echo json_encode(array('error' => 'Área inválida'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $colPontos = 'pontos_mes_' . $area;

        $stmt = $pdo->prepare(
            "SELECT g.user_id, u.name, g.{$colPontos} AS pontos,
                    g.contratos_mes, g.nivel, g.nivel_num, n.badge_emoji
             FROM gamificacao_totais g
             JOIN users u ON u.id = g.user_id AND u.is_active = 1
             LEFT JOIN gamificacao_niveis n ON n.nivel_num = g.nivel_num
             ORDER BY g.{$colPontos} DESC"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Atribuir posição
        $pos = 0;
        foreach ($rows as &$r) {
            $pos++;
            $r['posicao'] = $pos;
        }
        unset($r);

        // Meta do mês atual
        $mes = (int)date('m');
        $ano = (int)date('Y');
        $stmtMeta = $pdo->prepare(
            "SELECT * FROM gamificacao_config WHERE mes = ? AND ano = ? AND area = ? LIMIT 1"
        );
        $stmtMeta->execute(array($mes, $ano, $area));
        $meta = $stmtMeta->fetch();

        echo json_encode(array(
            'ranking' => $rows,
            'meta'    => $meta ?: null
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Ranking Carreira ────────────────────────────────────────
    if ($action === 'ranking_carreira') {
        $area = $_GET['area'] ?? 'comercial';
        if (!in_array($area, array('comercial', 'operacional'))) {
            echo json_encode(array('error' => 'Área inválida'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $colPontos = 'pontos_total_' . $area;

        $stmt = $pdo->prepare(
            "SELECT g.user_id, u.name, g.{$colPontos} AS pontos,
                    g.contratos_mes, g.nivel, g.nivel_num, n.badge_emoji
             FROM gamificacao_totais g
             JOIN users u ON u.id = g.user_id AND u.is_active = 1
             LEFT JOIN gamificacao_niveis n ON n.nivel_num = g.nivel_num
             ORDER BY g.{$colPontos} DESC"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $pos = 0;
        foreach ($rows as &$r) {
            $pos++;
            $r['posicao'] = $pos;
        }
        unset($r);

        echo json_encode(array('ranking' => $rows), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Histórico de Pontos ─────────────────────────────────────
    if ($action === 'historico') {
        $targetUser = (int)($_GET['user_id'] ?? $userId);

        $stmt = $pdo->prepare(
            "SELECT * FROM gamificacao_pontos
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $stmt->execute(array($targetUser));

        echo json_encode(array('historico' => $stmt->fetchAll()), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Meu Card ────────────────────────────────────────────────
    if ($action === 'meu_card') {
        $area = $_GET['area'] ?? 'comercial';
        if (!in_array($area, array('comercial', 'operacional'))) {
            echo json_encode(array('error' => 'Área inválida'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $colPontosMes   = 'pontos_mes_' . $area;
        $colPontosTotal = 'pontos_total_' . $area;

        $stmt = $pdo->prepare(
            "SELECT g.{$colPontosMes} AS pontos_mes,
                    g.{$colPontosTotal} AS pontos_total,
                    g.contratos_mes, g.contratos_total,
                    g.nivel, g.nivel_num, n.badge_emoji
             FROM gamificacao_totais g
             LEFT JOIN gamificacao_niveis n ON n.nivel_num = g.nivel_num
             WHERE g.user_id = ?
             LIMIT 1"
        );
        $stmt->execute(array($userId));
        $card = $stmt->fetch();

        if (!$card) {
            echo json_encode(array(
                'pontos_mes'      => 0,
                'pontos_total'    => 0,
                'contratos_mes'   => 0,
                'contratos_total' => 0,
                'nivel'           => 'Iniciante',
                'nivel_num'       => 0,
                'badge_emoji'     => '',
                'posicao'         => 0
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $card['posicao'] = gamificacao_posicao($userId, $area);

        echo json_encode($card, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Check Eventos (polling) ─────────────────────────────────
    if ($action === 'check_eventos') {
        $eventos = gamificacao_check_eventos(15);
        echo json_encode(array('eventos' => $eventos), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ação GET desconhecida
    echo json_encode(array('error' => 'Ação não encontrada'), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        http_response_code(403);
        echo json_encode(array('error' => 'Token CSRF inválido'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Salvar Configuração do Mês ──────────────────────────────
    if ($action === 'config_salvar') {
        if (!has_role('admin')) {
            http_response_code(403);
            echo json_encode(array('error' => 'Sem permissão'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $mes            = (int)($_POST['mes'] ?? 0);
        $ano            = (int)($_POST['ano'] ?? 0);
        $area           = $_POST['area'] ?? 'comercial';
        $metaPrincipal  = (int)($_POST['meta_principal'] ?? 0);
        $metaPontos     = (int)($_POST['meta_pontos'] ?? 0);
        $premio1        = clean_str($_POST['premio_1'] ?? '', 255);
        $premio2        = clean_str($_POST['premio_2'] ?? '', 255);
        $premio3        = clean_str($_POST['premio_3'] ?? '', 255);

        if (!$mes || !$ano || !in_array($area, array('comercial', 'operacional'))) {
            echo json_encode(array('error' => 'Parâmetros inválidos'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->prepare(
            "INSERT INTO gamificacao_config (mes, ano, area, meta_principal, meta_pontos, premio_1, premio_2, premio_3)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                meta_principal = VALUES(meta_principal),
                meta_pontos    = VALUES(meta_pontos),
                premio_1       = VALUES(premio_1),
                premio_2       = VALUES(premio_2),
                premio_3       = VALUES(premio_3)"
        )->execute(array($mes, $ano, $area, $metaPrincipal, $metaPontos, $premio1, $premio2, $premio3));

        echo json_encode(array('ok' => true, 'msg' => 'Configuração salva'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Marcar Prêmio como Entregue ─────────────────────────────
    if ($action === 'premio_entregue') {
        if (!has_role('admin')) {
            http_response_code(403);
            echo json_encode(array('error' => 'Sem permissão'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $premioId = (int)($_POST['premio_id'] ?? 0);
        if (!$premioId) {
            echo json_encode(array('error' => 'ID do prêmio inválido'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->prepare(
            "UPDATE gamificacao_premios SET entregue = 1, entregue_em = NOW() WHERE id = ?"
        )->execute(array($premioId));

        echo json_encode(array('ok' => true, 'msg' => 'Prêmio marcado como entregue'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Pontos Manuais ──────────────────────────────────────────
    if ($action === 'pontos_manual') {
        if (!has_role('admin')) {
            http_response_code(403);
            echo json_encode(array('error' => 'Sem permissão'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $targetUser = (int)($_POST['user_id'] ?? 0);
        $pontos     = (int)($_POST['pontos'] ?? 0);
        $area       = $_POST['area'] ?? 'comercial';
        $descricao  = clean_str($_POST['descricao'] ?? '', 500);

        if (!$targetUser || !$pontos) {
            echo json_encode(array('error' => 'Usuário e pontos obrigatórios'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!in_array($area, array('comercial', 'operacional'))) {
            echo json_encode(array('error' => 'Área inválida'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        gamificar($targetUser, 'pontos_manuais', null, null, $pontos);

        echo json_encode(array('ok' => true, 'msg' => 'Pontos atribuídos com sucesso'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ação POST desconhecida
    echo json_encode(array('error' => 'Ação não encontrada'), JSON_UNESCAPED_UNICODE);
    exit;
}

// Método não suportado
http_response_code(405);
echo json_encode(array('error' => 'Método não permitido'), JSON_UNESCAPED_UNICODE);
exit;
