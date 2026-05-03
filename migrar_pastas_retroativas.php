<?php
/**
 * Cria pastas no Drive pros casos sem drive_folder_url criados nos últimos
 * 14 dias (gap entre 30/abr e 03/mai/2026 quando o gatilho não disparava
 * pelo caso_novo.php).
 *
 * Idempotente — só processa quem ainda não tem pasta.
 * Cada chamada Apps Script demora 7-10s; processa em batch via ?limite=N.
 */
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(300);

require_once __DIR__ . '/core/google_drive.php';
$pdo = db();
$limite = max(1, min(20, (int)($_GET['limite'] ?? 5)));

$st = $pdo->prepare(
    "SELECT c.id, c.title, c.case_type, cl.name AS cliente
     FROM cases c
     LEFT JOIN clients cl ON cl.id = c.client_id
     WHERE (c.drive_folder_url IS NULL OR c.drive_folder_url = '')
       AND c.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
       AND c.status NOT IN ('cancelado','arquivado')
       AND cl.name IS NOT NULL AND cl.name != ''
     ORDER BY c.created_at DESC
     LIMIT " . (int)$limite
);
$st->execute();
$alvos = $st->fetchAll(PDO::FETCH_ASSOC);

echo "=== Casos a processar (limite={$limite}): " . count($alvos) . " ===\n\n";
$ok = 0; $falha = 0;
foreach ($alvos as $c) {
    echo "  #{$c['id']}  {$c['cliente']} — {$c['title']}\n";
    $t0 = microtime(true);
    $r = create_drive_folder($c['cliente'], $c['case_type'], (int)$c['id'], $c['title']);
    $ms = round((microtime(true) - $t0) * 1000);
    if (!empty($r['success'])) {
        echo "    ✅ OK ({$ms}ms) — {$r['folderUrl']}\n";
        $ok++;
    } else {
        echo "    ❌ FALHA ({$ms}ms) — " . substr((string)($r['error'] ?? '?'), 0, 200) . "\n";
        $falha++;
        @error_log('[migrar_pastas_retroativas] case#' . $c['id'] . ': ' . ($r['error'] ?? '?'));
    }
}

echo "\n=== Resumo ===\n";
echo "  Processados: " . count($alvos) . "  OK: $ok  Falha: $falha\n";

// Quantos ainda faltam
$restante = (int)$pdo->query(
    "SELECT COUNT(*) FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id
     WHERE (c.drive_folder_url IS NULL OR c.drive_folder_url = '')
       AND c.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
       AND c.status NOT IN ('cancelado','arquivado')
       AND cl.name IS NOT NULL AND cl.name != ''"
)->fetchColumn();
echo "  Ainda sem pasta: $restante\n";
if ($restante > 0) echo "  → rode novamente: ?key=fsa-hub-deploy-2026&limite=10\n";
