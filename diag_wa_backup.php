<?php
/**
 * diag_wa_backup.php — diagnóstico rápido do backup WhatsApp
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG Backup WhatsApp ===\n\n";

// 1) Cron file existe?
$cronFile = __DIR__ . '/cron/wa_backup_arquivos.php';
echo "1. cron/wa_backup_arquivos.php: " . (file_exists($cronFile) ? 'OK (' . filesize($cronFile) . ' bytes)' : 'FALTA!') . "\n";

// 2) Trigger existe?
$trigFile = __DIR__ . '/api/wa_backup_trigger.php';
echo "2. api/wa_backup_trigger.php: " . (file_exists($trigFile) ? 'OK (' . filesize($trigFile) . ' bytes)' : 'FALTA!') . "\n";

// 3) Coluna backup_status existe?
try {
    $col = $pdo->query("SHOW COLUMNS FROM zapi_mensagens LIKE 'backup_status'")->fetch();
    echo "3. Coluna backup_status: " . ($col ? 'OK' : 'NAO CRIADA') . "\n";
} catch (Exception $e) {
    echo "3. Coluna: ERRO " . $e->getMessage() . "\n";
}

// 4) Quantas mensagens elegíveis?
try {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM zapi_mensagens m
         JOIN zapi_conversas co ON co.id = m.conversa_id
         WHERE m.tipo IN ('imagem','video','audio','documento','sticker')
           AND m.arquivo_url IS NOT NULL AND m.arquivo_url != ''
           AND (m.arquivo_salvo_drive = 0 OR m.arquivo_salvo_drive IS NULL)
           AND (m.backup_status IS NULL OR m.backup_status = 'retry')
           AND m.created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)"
    );
    echo "4. Mensagens elegíveis pra backup: " . $stmt->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "4. Contagem: ERRO " . $e->getMessage() . "\n";
}

// 5) Breakdown por status
try {
    $stmt = $pdo->query(
        "SELECT backup_status, COUNT(*) as total
         FROM zapi_mensagens
         WHERE tipo IN ('imagem','video','audio','documento','sticker')
           AND arquivo_url IS NOT NULL AND arquivo_url != ''
           AND created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
         GROUP BY backup_status"
    );
    echo "\n5. Status das mensagens (últimos 28d):\n";
    foreach ($stmt as $r) {
        echo "   " . ($r['backup_status'] ?: '(NULL)') . ": " . $r['total'] . "\n";
    }
} catch (Exception $e) {
    echo "5. Breakdown: ERRO " . $e->getMessage() . "\n";
}

// 6) Função upload_file_to_drive existe?
try {
    require_once __DIR__ . '/core/google_drive.php';
    echo "\n6. google_drive.php carregado. upload_file_to_drive: " . (function_exists('upload_file_to_drive') ? 'OK' : 'FALTA') . "\n";
} catch (Exception $e) {
    echo "\n6. google_drive.php: ERRO " . $e->getMessage() . "\n";
}

// 7) Log existe?
$log = __DIR__ . '/files/wa_backup.log';
echo "\n7. Log " . $log . ": " . (file_exists($log) ? 'existe (' . filesize($log) . ' bytes)' : 'não existe') . "\n";

// 8) Pasta /files writable?
$filesDir = __DIR__ . '/files';
echo "8. /files existe: " . (is_dir($filesDir) ? 'sim' : 'não') . "\n";
echo "   writable: " . (is_writable($filesDir) ? 'sim' : 'NÃO!') . "\n";
