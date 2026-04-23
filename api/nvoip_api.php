<?php
/**
 * api/nvoip_api.php — endpoints da integração Nvoip (telefonia VoIP)
 *
 * Actions:
 *   POST realizar_chamada   — inicia chamada (cria ligacoes_historico)
 *   GET  consultar_chamada  — polling (2s); processa gravação ao finalizar
 *   POST encerrar_chamada   — desliga chamada manualmente
 *   GET  historico          — histórico por client_id | lead_id | case_id | meu
 *   GET  saldo              — saldo da conta (admin)
 *   GET  audio              — stream da gravação local (com auth)
 *   POST salvar_config      — admin salva chaves nvoip_* (napikey, numbersip, user_token)
 *   POST salvar_ramais      — admin grava users.nvoip_ramal
 *   POST testar_conexao     — admin força gerar token pra validar credenciais
 */

require_once __DIR__ . '/../core/middleware.php';
require_once __DIR__ . '/../core/functions_nvoip.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo    = db();
$userId = current_user_id();
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$readOnly = array('consultar_chamada', 'historico', 'saldo', 'audio');
if (!in_array($action, $readOnly, true)) {
    if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido')); exit; }
}

switch ($action) {

    // ─────────────────────────────────────────────────────────────
    case 'realizar_chamada':
        if (!nvoip_configurada()) { echo json_encode(array('error' => 'Nvoip não configurada. Peça ao admin.')); exit; }
        $telefone = trim($_POST['telefone'] ?? '');
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        $leadId   = (int)($_POST['lead_id']   ?? 0) ?: null;
        $caseId   = (int)($_POST['case_id']   ?? 0) ?: null;
        if (!$telefone) { echo json_encode(array('error' => 'Telefone obrigatório')); exit; }

        $resp = nvoip_realizar_chamada($telefone, $userId);
        if (($resp['state'] ?? '') === 'success' && !empty($resp['callId'])) {
            $ramal = nvoip_get_ramal_usuario($userId);
            try {
                $pdo->prepare("INSERT INTO ligacoes_historico
                    (call_id, client_id, lead_id, case_id, atendente_id, ramal, telefone_destino, status, iniciada_em)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'calling', NOW())")
                    ->execute(array($resp['callId'], $clientId, $leadId, $caseId, $userId, $ramal, $telefone));
                if (function_exists('audit_log')) audit_log('nvoip_ligar', 'ligacoes_historico', (int)$pdo->lastInsertId(), $telefone);
            } catch (Exception $e) {}
        }
        echo json_encode($resp);
        exit;

    // ─────────────────────────────────────────────────────────────
    case 'consultar_chamada':
        $callId = trim($_GET['call_id'] ?? '');
        if (!$callId) { echo json_encode(array('error' => 'call_id obrigatório')); exit; }

        // Se já está finalizada no nosso banco, não pergunta pra Nvoip de novo
        $row = $pdo->prepare("SELECT status, duracao_segundos, gravacao_local FROM ligacoes_historico WHERE call_id = ? LIMIT 1");
        $row->execute(array($callId));
        $loc = $row->fetch();
        if ($loc && in_array($loc['status'], array('finished','failed','noanswer','busy'), true)) {
            echo json_encode(array(
                'state' => $loc['status'],
                'talkingDurationSeconds' => (int)$loc['duracao_segundos'],
                'gravacao_local' => $loc['gravacao_local'] ?: null,
                'fromCache' => true,
            ));
            exit;
        }

        $resp = nvoip_consultar_chamada($callId);
        if (!$resp) { echo json_encode(array('error' => 'Falha ao consultar Nvoip')); exit; }
        $state = $resp['state'] ?? 'calling';

        // Status calling → sem mudança
        if ($state === 'calling' || $state === 'established') {
            $pdo->prepare("UPDATE ligacoes_historico SET status = ? WHERE call_id = ?")->execute(array($state, $callId));
        } elseif (in_array($state, array('finished','failed','noanswer','busy'), true)) {
            $dur = (int)($resp['talkingDurationSeconds'] ?? 0);
            $pdo->prepare("UPDATE ligacoes_historico SET status = ?, duracao_segundos = ?, encerrada_em = NOW() WHERE call_id = ?")
                ->execute(array($state, $dur, $callId));

            // Se encerrou com sucesso e tem linkAudio, processa gravação em background
            if ($state === 'finished' && !empty($resp['linkAudio'])) {
                // Processa inline (é rápido — ~2-3s pra download + transcrição curta)
                // Pra chamadas longas, pode virar job assíncrono no futuro.
                @set_time_limit(180);
                try { nvoip_processar_gravacao($callId, $resp['linkAudio']); } catch (Exception $e) {}
            }
        }
        echo json_encode($resp);
        exit;

    // ─────────────────────────────────────────────────────────────
    case 'encerrar_chamada':
        $callId = trim($_POST['call_id'] ?? '');
        if (!$callId) { echo json_encode(array('error' => 'call_id obrigatório')); exit; }
        $resp = nvoip_encerrar_chamada($callId);
        echo json_encode($resp ?: array('ok' => true));
        exit;

    // ─────────────────────────────────────────────────────────────
    case 'historico':
        $clientId = (int)($_GET['client_id'] ?? 0);
        $leadId   = (int)($_GET['lead_id']   ?? 0);
        $caseId   = (int)($_GET['case_id']   ?? 0);
        $meu      = !empty($_GET['meu']);
        $limit    = min(100, max(1, (int)($_GET['limit'] ?? 20)));

        $where = array(); $params = array();
        if ($clientId) { $where[] = 'l.client_id = ?'; $params[] = $clientId; }
        if ($leadId)   { $where[] = 'l.lead_id = ?';   $params[] = $leadId; }
        if ($caseId)   { $where[] = 'l.case_id = ?';   $params[] = $caseId; }
        if ($meu)      { $where[] = 'l.atendente_id = ?'; $params[] = $userId; }
        if (!$where) { echo json_encode(array('error' => 'Informe client_id, lead_id, case_id ou meu=1')); exit; }

        $sql = "SELECT l.id, l.call_id, l.telefone_destino, l.duracao_segundos, l.status,
                       l.gravacao_local, l.transcricao, l.resumo_ia,
                       l.iniciada_em, l.encerrada_em,
                       u.name AS atendente_nome
                FROM ligacoes_historico l
                LEFT JOIN users u ON u.id = l.atendente_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.iniciada_em DESC LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        echo json_encode(array('ok' => true, 'ligacoes' => $rows));
        exit;

    // ─────────────────────────────────────────────────────────────
    case 'saldo':
        if (!has_min_role('admin')) { echo json_encode(array('error' => 'Só admin')); exit; }
        $resp = nvoip_consultar_saldo();
        echo json_encode($resp ?: array('error' => 'Falha ao consultar saldo'));
        exit;

    // ─────────────────────────────────────────────────────────────
    case 'audio':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { http_response_code(400); echo 'id obrigatório'; exit; }
        $row = $pdo->prepare("SELECT l.atendente_id, l.client_id, l.gravacao_local
                              FROM ligacoes_historico l WHERE l.id = ?");
        $row->execute(array($id));
        $lig = $row->fetch();
        if (!$lig || !$lig['gravacao_local']) { http_response_code(404); echo 'Gravação não encontrada'; exit; }

        // Autorização: admin/gestao OU é o atendente
        if (!has_min_role('gestao') && (int)$lig['atendente_id'] !== $userId) {
            http_response_code(403); echo 'Sem permissão'; exit;
        }

        $path = APP_ROOT . '/files/ligacoes/' . basename($lig['gravacao_local']);
        if (!file_exists($path)) { http_response_code(404); echo 'Arquivo não existe'; exit; }

        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        header('Accept-Ranges: bytes');
        readfile($path);
        exit;

    // ─────────────────────────────────────────────────────────────
    case 'salvar_config':
        if (!has_min_role('admin')) { echo json_encode(array('error' => 'Só admin')); exit; }
        $napi = trim($_POST['napikey']    ?? '');
        $sip  = trim($_POST['numbersip']  ?? '');
        $ut   = trim($_POST['user_token'] ?? '');
        // Só salva campos preenchidos (preserva os que já estão salvos se não mandar)
        if ($napi !== '') nvoip_cfg_set('nvoip_napikey', $napi);
        if ($sip  !== '') nvoip_cfg_set('nvoip_numbersip', $sip);
        if ($ut   !== '') nvoip_cfg_set('nvoip_user_token', $ut);
        // Ao trocar credenciais, zera tokens antigos
        nvoip_cfg_set('nvoip_access_token', '');
        nvoip_cfg_set('nvoip_refresh_token', '');
        nvoip_cfg_set('nvoip_token_expiry', '');
        if (function_exists('audit_log')) audit_log('nvoip_salvar_config', 'configuracoes', 0, 'napi=' . ($napi?'sim':'-'));
        echo json_encode(array('ok' => true));
        exit;

    // ─────────────────────────────────────────────────────────────
    case 'salvar_ramais':
        if (!has_min_role('admin')) { echo json_encode(array('error' => 'Só admin')); exit; }
        $ramais = $_POST['ramais'] ?? array(); // [{user_id, ramal}, ...]
        if (!is_array($ramais)) $ramais = array();
        $up = $pdo->prepare("UPDATE users SET nvoip_ramal = ? WHERE id = ?");
        $cnt = 0;
        foreach ($ramais as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            $ram = trim($r['ramal'] ?? '');
            if ($uid) { $up->execute(array($ram ?: null, $uid)); $cnt++; }
        }
        echo json_encode(array('ok' => true, 'atualizados' => $cnt));
        exit;

    // ─────────────────────────────────────────────────────────────
    case 'testar_conexao':
        if (!has_min_role('admin')) { echo json_encode(array('error' => 'Só admin')); exit; }
        // Zera token atual e tenta gerar novo — valida credenciais
        nvoip_cfg_set('nvoip_access_token', '');
        nvoip_cfg_set('nvoip_token_expiry', '');
        $tk = nvoip_generate_token();
        if ($tk) {
            echo json_encode(array('ok' => true, 'mensagem' => 'Conexão OK — token gerado (' . substr($tk, 0, 10) . '...)'));
        } else {
            echo json_encode(array('error' => 'Falha ao gerar token. Confira numbersip e user_token.'));
        }
        exit;
}

echo json_encode(array('error' => 'Ação inválida'));
