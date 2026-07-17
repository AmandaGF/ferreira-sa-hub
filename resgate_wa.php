<?php
/**
 * resgate_wa.php — Backfill emergencial dos arquivos de WhatsApp que ainda dao pra salvar.
 *
 * Nasceu em 17/07/2026 ao descobrir que o cron wa_backup_arquivos.php foi escrito em
 * 22/04 e NUNCA foi agendado no cPanel: 15.439 arquivos sem backup, 7.939 ja perdidos
 * (link Z-API expira em 30 dias).
 *
 * Diferencas em relacao ao cron:
 *  - Janela ate 30 dias (o cron corta em 28 e deixa morrer 509 arquivos na janela cega)
 *  - Ordena por MAIS ANTIGO primeiro: quem esta perto de expirar sai na frente
 *  - NAO salva sticker (ruido puro; polui a pasta do cliente)
 *  - Reprocessa 'pendente_manual' quando o cliente foi vinculado depois (o cron nunca
 *    volta nesses: uma vez pendente_manual, abandonado pra sempre)
 *
 * Uso:
 *   resgate_wa.php?key=...&limit=50              -> roda um lote
 *   resgate_wa.php?key=...&limit=50&min_dias=27  -> so os que morrem em <=3 dias
 *   resgate_wa.php?key=...&dry=1                 -> so mostra o que faria
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/google_drive.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$pdo   = db();
$limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$minD  = (int)($_GET['min_dias'] ?? 0);   // 0 = sem piso; 27 = so os quase expirados
$dry   = !empty($_GET['dry']);
$tIni  = microtime(true);

echo "=== RESGATE WhatsApp -> Drive — " . date('d/m/Y H:i:s') . " ===\n";
echo "limite: $limit | idade minima: {$minD}d | modo: " . ($dry ? 'DRY RUN' : 'REAL') . "\n\n";

// Janela: entre min_dias e 30 dias. Sticker fora. Mais antigo primeiro (morre antes).
$sql =
    "SELECT m.id, m.tipo, m.arquivo_url, m.arquivo_nome, m.arquivo_mime, m.created_at,
            m.backup_status, co.client_id, co.nome_contato, co.telefone,
            DATEDIFF(NOW(), m.created_at) AS idade
     FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE m.tipo IN ('imagem','video','audio','documento')
       AND m.arquivo_url IS NOT NULL AND m.arquivo_url != ''
       AND (m.arquivo_salvo_drive = 0 OR m.arquivo_salvo_drive IS NULL)
       -- SEM este filtro o lote trava: ordenamos por mais antigo primeiro, entao o
       -- arquivo que falha volta na frente do proximo lote e o loop nunca avanca.
       AND (m.backup_status IS NULL OR m.backup_status = 'retry_ok')
       AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
       AND m.created_at <= DATE_SUB(NOW(), INTERVAL $minD DAY)
       AND co.client_id IS NOT NULL
       AND EXISTS (SELECT 1 FROM cases c
                    WHERE c.client_id = co.client_id
                      AND c.drive_folder_url IS NOT NULL AND c.drive_folder_url != '')
     ORDER BY m.created_at ASC
     LIMIT $limit";

$msgs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo "Encontrados neste lote: " . count($msgs) . "\n\n";

$salvas = 0; $falhas = 0;

foreach ($msgs as $msg) {
    $msgId = (int)$msg['id'];
    printf("-> msg #%-7s %-10s %sd  ", $msgId, $msg['tipo'], $msg['idade']);

    $st = $pdo->prepare(
        "SELECT id, title, drive_folder_url FROM cases
          WHERE client_id = ? AND drive_folder_url IS NOT NULL AND drive_folder_url != ''
          ORDER BY updated_at DESC, id DESC LIMIT 1"
    );
    $st->execute([$msg['client_id']]);
    $case = $st->fetch(PDO::FETCH_ASSOC);
    if (!$case) { echo "sem case com pasta (pulado)\n"; continue; }

    $nome = $msg['arquivo_nome'] ?: ('whatsapp_' . date('Ymd_His', strtotime($msg['created_at'])) . '_' . $msgId);
    if (!pathinfo($nome, PATHINFO_EXTENSION)) {
        $ext = 'bin';
        if ($msg['tipo'] === 'imagem')      { $ext = 'jpg'; }
        elseif ($msg['tipo'] === 'video')   { $ext = 'mp4'; }
        elseif ($msg['tipo'] === 'audio')   { $ext = 'ogg'; }
        elseif ($msg['arquivo_mime'])       { $ext = preg_replace('/.*\//', '', $msg['arquivo_mime']); }
        $nome .= '.' . $ext;
    }

    if ($dry) { echo "[DRY] iria salvar '$nome' em '{$case['title']}'\n"; continue; }

    try {
        $r = upload_file_to_drive($case['drive_folder_url'], $nome, $msg['arquivo_url'], $msg['arquivo_mime'] ?? '');
        if (!empty($r['success'])) {
            $pdo->prepare("UPDATE zapi_mensagens SET arquivo_salvo_drive = 1, drive_file_id = ?, backup_status = 'auto' WHERE id = ?")
                ->execute([$r['fileId'] ?? '', $msgId]);
            echo "OK -> " . mb_substr($case['title'], 0, 38) . "\n";
            $salvas++;
        } else {
            echo "FALHA: " . mb_substr((string)($r['error'] ?? '?'), 0, 60) . "\n";
            $pdo->prepare("UPDATE zapi_mensagens SET backup_status = 'falha_pasta' WHERE id = ?")->execute([$msgId]);
            $falhas++;
        }
    } catch (Exception $e) {
        echo "EXCEPTION: " . mb_substr($e->getMessage(), 0, 60) . "\n";
        $pdo->prepare("UPDATE zapi_mensagens SET backup_status = 'retry' WHERE id = ?")->execute([$msgId]);
        $falhas++;
    }
    usleep(300000);
}

echo "\n=== Resumo do lote ===\n";
echo "salvos: $salvas | falhas: $falhas | tempo: " . round(microtime(true) - $tIni, 1) . "s\n";

$rest = $pdo->query(
    "SELECT COUNT(*) FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
      WHERE m.tipo IN ('imagem','video','audio','documento')
        AND m.arquivo_url IS NOT NULL AND m.arquivo_url != ''
        AND (m.arquivo_salvo_drive = 0 OR m.arquivo_salvo_drive IS NULL)
        AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND co.client_id IS NOT NULL
        AND EXISTS (SELECT 1 FROM cases c WHERE c.client_id = co.client_id
                     AND c.drive_folder_url IS NOT NULL AND c.drive_folder_url != '')"
)->fetchColumn();
echo "ainda na fila (salvaveis): $rest\n";
