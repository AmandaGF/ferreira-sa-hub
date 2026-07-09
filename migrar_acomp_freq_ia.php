<?php
/**
 * Migracao 09/07/2026 — msg diaria de acompanhamento:
 * - Nova coluna dias_semana VARCHAR (CSV de weekdays 1-7 ISO, ex '1,5' = seg+sex)
 * - Nova coluna usar_ia TINYINT (gera mensagem unica via Claude Haiku)
 *
 * Rodar: /conecta/migrar_acomp_freq_ia.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migracao msg diaria — frequencia + IA (Amanda 09/07/2026) ===\n\n";

$alters = array(
    "ALTER TABLE acompanhamento_msg_diario ADD COLUMN dias_semana VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5'",
    "ALTER TABLE acompanhamento_msg_diario ADD COLUMN usar_ia TINYINT(1) NOT NULL DEFAULT 0",
);
foreach ($alters as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $sql\n";
    } catch (Throwable $e) {
        // 1060 = duplicate column, OK
        if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), '1060') !== false) {
            echo "SKIP (ja existe): " . substr($sql, 0, 80) . "\n";
        } else {
            echo "ERRO: " . $e->getMessage() . "\n";
        }
    }
}

// Backfill: as configs antigas com dias_uteis_only=1 mantem "1,2,3,4,5" (default),
// as com dias_uteis_only=0 recebem "1,2,3,4,5,6,7" (todos os dias)
try {
    $st = $pdo->prepare("UPDATE acompanhamento_msg_diario SET dias_semana = '1,2,3,4,5,6,7' WHERE dias_uteis_only = 0 AND dias_semana = '1,2,3,4,5'");
    $st->execute();
    echo "\nBackfill: {$st->rowCount()} config(s) sem dias_uteis_only atualizadas pra 'todos os dias'\n";
} catch (Throwable $e) { echo "Backfill nao aplicado: " . $e->getMessage() . "\n"; }

echo "\n=== Concluido ===\n";
