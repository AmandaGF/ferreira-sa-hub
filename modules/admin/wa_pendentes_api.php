<?php
/**
 * API da tela de WhatsApp Backup Pendente Manual.
 * Acesso: SOMENTE admin/gestao.
 *
 * Actions:
 *   vincular_cliente  — atualiza zapi_conversas.client_id + tenta backup
 *   tentar_backup     — re-dispara o cron pros arquivos pendentes da conversa
 *   descartar         — marca backup_status='descartado' (não tenta mais)
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_utils.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

if (!has_min_role('gestao')) { echo json_encode(array('error' => 'Acesso restrito.')); exit; }
if (!validate_csrf())         { echo json_encode(array('error' => 'Token inválido.')); exit; }

$pdo = db();
$action = $_POST['action'] ?? '';
$convId = (int)($_POST['conv_id'] ?? 0);
if (!$convId) { echo json_encode(array('error' => 'conv_id obrigatório.')); exit; }

// Tenta processar os pendentes da conversa via Apps Script (mesma lógica do cron)
function backup_pendentes_da_conversa($pdo, $convId) {
    require_once APP_ROOT . '/core/google_drive.php';
    $st = $pdo->prepare(
        "SELECT m.id, m.tipo, m.arquivo_url, m.arquivo_nome, m.arquivo_mime, m.created_at, co.client_id
         FROM zapi_mensagens m INNER JOIN zapi_conversas co ON co.id = m.conversa_id
         WHERE m.conversa_id = ? AND m.backup_status = 'pendente_manual' AND m.arquivo_url IS NOT NULL AND m.arquivo_url != ''"
    );
    $st->execute(array($convId));
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$msgs) return array('salvos' => 0, 'falhas' => 0, 'sem_pasta' => false);

    // Pega case mais recente com pasta Drive do cliente
    $clientId = (int)$msgs[0]['client_id'];
    if (!$clientId) return array('salvos' => 0, 'falhas' => count($msgs), 'sem_pasta' => false, 'erro' => 'Conversa sem cliente vinculado');

    $stCase = $pdo->prepare(
        "SELECT id, title, drive_folder_url FROM cases
         WHERE client_id = ? AND drive_folder_url IS NOT NULL AND drive_folder_url != ''
         ORDER BY updated_at DESC, id DESC LIMIT 1"
    );
    $stCase->execute(array($clientId));
    $case = $stCase->fetch(PDO::FETCH_ASSOC);
    if (!$case) return array('salvos' => 0, 'falhas' => 0, 'sem_pasta' => true);

    $salvos = 0; $falhas = 0;
    $stUpdOk = $pdo->prepare("UPDATE zapi_mensagens SET arquivo_salvo_drive = 1, drive_file_id = ?, backup_status = 'manual' WHERE id = ?");
    $stUpdErr = $pdo->prepare("UPDATE zapi_mensagens SET backup_status = 'retry' WHERE id = ?");

    foreach ($msgs as $msg) {
        $nomeFinal = $msg['arquivo_nome'] ?: ('whatsapp_' . date('Ymd_His', strtotime($msg['created_at'])) . '_' . $msg['id']);
        if (!pathinfo($nomeFinal, PATHINFO_EXTENSION)) {
            $ext = 'bin';
            if     ($msg['tipo'] === 'imagem')    $ext = 'jpg';
            elseif ($msg['tipo'] === 'video')     $ext = 'mp4';
            elseif ($msg['tipo'] === 'audio')     $ext = 'ogg';
            elseif ($msg['arquivo_mime'])         $ext = preg_replace('/.*\//', '', $msg['arquivo_mime']);
            $nomeFinal .= '.' . $ext;
        }
        try {
            $r = upload_file_to_drive($case['drive_folder_url'], $nomeFinal, $msg['arquivo_url'], $msg['arquivo_mime'] ?? '');
            if (!empty($r['success'])) {
                $stUpdOk->execute(array($r['fileId'] ?? '', (int)$msg['id']));
                $salvos++;
            } else {
                $stUpdErr->execute(array((int)$msg['id']));
                $falhas++;
            }
        } catch (Exception $e) {
            $stUpdErr->execute(array((int)$msg['id']));
            $falhas++;
        }
    }
    return array('salvos' => $salvos, 'falhas' => $falhas, 'sem_pasta' => false, 'case_titulo' => $case['title']);
}

if ($action === 'vincular_cliente') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    if (!$clientId) { echo json_encode(array('error' => 'client_id obrigatório.')); exit; }
    try {
        $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")->execute(array($clientId, $convId));
        @audit_log('WA_PENDENTE_VINCULAR', 'zapi_conversas', $convId, "client#$clientId");
        // Tenta backup já com o vínculo novo
        $r = backup_pendentes_da_conversa($pdo, $convId);
        echo json_encode(array_merge(array('ok' => true, 'backup_tentado' => true), $r));
    } catch (Throwable $e) {
        echo json_encode(array('error' => 'Erro: ' . $e->getMessage()));
    }
    exit;
}

if ($action === 'tentar_backup') {
    try {
        @set_time_limit(120);
        $r = backup_pendentes_da_conversa($pdo, $convId);
        echo json_encode(array_merge(array('ok' => true), $r));
    } catch (Throwable $e) {
        echo json_encode(array('error' => 'Erro: ' . $e->getMessage()));
    }
    exit;
}

if ($action === 'descartar') {
    try {
        $st = $pdo->prepare("UPDATE zapi_mensagens SET backup_status = 'descartado' WHERE conversa_id = ? AND backup_status = 'pendente_manual'");
        $st->execute(array($convId));
        @audit_log('WA_PENDENTE_DESCARTAR', 'zapi_conversas', $convId, 'rows=' . $st->rowCount());
        echo json_encode(array('ok' => true, 'descartados' => $st->rowCount()));
    } catch (Throwable $e) {
        echo json_encode(array('error' => 'Erro: ' . $e->getMessage()));
    }
    exit;
}

echo json_encode(array('error' => 'Action desconhecida.'));
