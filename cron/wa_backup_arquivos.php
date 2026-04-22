<?php
/**
 * wa_backup_arquivos.php
 *
 * Cron diário que salva no Drive todos os arquivos WhatsApp recebidos
 * nos últimos 28 dias que AINDA NÃO foram salvos (arquivo_salvo_drive=0).
 * Evita perda de fotos/áudios/documentos — os links da Z-API expiram em 30 dias.
 *
 * Estratégia:
 *  - Prioriza conversas com client_id vinculado
 *  - Pra cada cliente, escolhe o case MAIS RECENTE com drive_folder_url
 *  - Sem client_id ou sem case com pasta → marca com flag 'pendente_manual'
 *    (aparece num relatório pra você decidir depois)
 *
 * URL pra cron via curl:
 *   curl -s "https://ferreiraesa.com.br/conecta/cron/wa_backup_arquivos.php?key=fsa-hub-deploy-2026"
 *
 * Agendamento recomendado no cPanel:
 *   30 2 * * * curl -s "https://ferreiraesa.com.br/conecta/cron/wa_backup_arquivos.php?key=fsa-hub-deploy-2026"
 *   (todo dia às 2:30 da madrugada)
 */

header('Content-Type: text/plain; charset=utf-8');

if (php_sapi_name() !== 'cli') {
    if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
        http_response_code(403);
        exit('Chave inválida.');
    }
}

require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/google_drive.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$pdo = db();
$tIni = microtime(true);

echo "=== WhatsApp Backup Arquivos — " . date('Y-m-d H:i:s') . " ===\n\n";

// Self-heal: coluna pra marcar pendente manual
try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN backup_status VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}

// Busca mensagens pendentes de backup
// Filtros:
//  - Tipo de arquivo (imagem/video/audio/documento/sticker)
//  - Últimos 28 dias (antes de Z-API expirar em 30d)
//  - Ainda não salvas
//  - Tem arquivo_url preenchido
$stmt = $pdo->prepare(
    "SELECT m.id, m.tipo, m.conteudo, m.arquivo_url, m.arquivo_nome, m.arquivo_mime, m.created_at,
            co.client_id, co.canal
     FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE m.tipo IN ('imagem','video','audio','documento','sticker')
       AND m.arquivo_url IS NOT NULL
       AND m.arquivo_url != ''
       AND (m.arquivo_salvo_drive = 0 OR m.arquivo_salvo_drive IS NULL)
       AND (m.backup_status IS NULL OR m.backup_status = 'retry')
       AND m.created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
     ORDER BY m.created_at ASC
     LIMIT 100"
);
$stmt->execute();
$msgs = $stmt->fetchAll();

echo "Mensagens pra backup: " . count($msgs) . "\n\n";

$salvas = 0;
$pendentesManual = 0;
$falhas = 0;

foreach ($msgs as $msg) {
    $msgId = (int)$msg['id'];
    echo "→ msg #$msgId ({$msg['tipo']}, {$msg['created_at']})";

    // Precisa de client_id pra achar o case + pasta Drive
    if (empty($msg['client_id'])) {
        echo " ⚠️ sem client_id → marcado pendente_manual\n";
        $pdo->prepare("UPDATE zapi_mensagens SET backup_status = 'pendente_manual' WHERE id = ?")
            ->execute(array($msgId));
        $pendentesManual++;
        continue;
    }

    // Acha o case mais recente do cliente que tenha pasta Drive
    $stmtCase = $pdo->prepare(
        "SELECT id, title, drive_folder_url FROM cases
         WHERE client_id = ?
           AND drive_folder_url IS NOT NULL
           AND drive_folder_url != ''
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $stmtCase->execute(array($msg['client_id']));
    $caseRow = $stmtCase->fetch();

    if (!$caseRow) {
        echo " ⚠️ cliente #{$msg['client_id']} sem case com pasta Drive → marcado pendente_manual\n";
        $pdo->prepare("UPDATE zapi_mensagens SET backup_status = 'pendente_manual' WHERE id = ?")
            ->execute(array($msgId));
        $pendentesManual++;
        continue;
    }

    // Define nome do arquivo
    $nomeFinal = $msg['arquivo_nome'] ?: ('whatsapp_' . date('Ymd_His', strtotime($msg['created_at'])) . '_' . $msgId);
    if (!pathinfo($nomeFinal, PATHINFO_EXTENSION)) {
        $ext = 'bin';
        if ($msg['tipo'] === 'imagem') $ext = 'jpg';
        elseif ($msg['tipo'] === 'video') $ext = 'mp4';
        elseif ($msg['tipo'] === 'audio') $ext = 'ogg';
        elseif ($msg['arquivo_mime']) $ext = preg_replace('/.*\//', '', $msg['arquivo_mime']);
        $nomeFinal .= '.' . $ext;
    }

    try {
        $r = upload_file_to_drive($caseRow['drive_folder_url'], $nomeFinal, $msg['arquivo_url'], $msg['arquivo_mime'] ?? '');
        if (!empty($r['success'])) {
            $pdo->prepare("UPDATE zapi_mensagens SET arquivo_salvo_drive = 1, drive_file_id = ?, backup_status = 'auto' WHERE id = ?")
                ->execute(array($r['fileId'] ?? '', $msgId));
            echo " ✅ salvo em '{$caseRow['title']}' (Drive)\n";
            $salvas++;
        } else {
            echo " ❌ falha: " . ($r['error'] ?? 'erro desconhecido') . "\n";
            $pdo->prepare("UPDATE zapi_mensagens SET backup_status = 'retry' WHERE id = ?")
                ->execute(array($msgId));
            $falhas++;
        }
    } catch (Exception $e) {
        echo " ❌ exception: " . $e->getMessage() . "\n";
        $pdo->prepare("UPDATE zapi_mensagens SET backup_status = 'retry' WHERE id = ?")
            ->execute(array($msgId));
        $falhas++;
    }

    // Respira 500ms pra não derrubar o Apps Script
    usleep(500000);
}

$tempo = round(microtime(true) - $tIni, 1);

echo "\n=== Resumo ===\n";
echo "✅ Salvas no Drive: {$salvas}\n";
echo "⚠️ Pendente manual (sem cliente/case): {$pendentesManual}\n";
echo "❌ Falhas (retry): {$falhas}\n";
echo "⏱️ Tempo: {$tempo}s\n";

// Se teve pendentes manuais, lista os últimos 20 pra relatório
if ($pendentesManual > 0) {
    echo "\n--- Pendências manuais (últimas 20) ---\n";
    $pd = $pdo->query(
        "SELECT m.id, m.tipo, m.created_at, co.nome_contato, co.telefone
         FROM zapi_mensagens m
         JOIN zapi_conversas co ON co.id = m.conversa_id
         WHERE m.backup_status = 'pendente_manual'
         ORDER BY m.created_at DESC
         LIMIT 20"
    )->fetchAll();
    foreach ($pd as $p) {
        echo "  msg #{$p['id']} {$p['tipo']} {$p['created_at']} — {$p['nome_contato']} ({$p['telefone']})\n";
    }
}

echo "\n=== FIM ===\n";
