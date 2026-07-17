<?php
/**
 * cron/alfredo.php — Processa msgs recebidas em conversas com Alfredo ativo.
 *
 * Cadencia: rodar a cada 60s via cPanel:
 *   curl -s "https://ferreiraesa.com.br/conecta/cron/alfredo.php?key=fsa-hub-deploy-2026"
 */

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); exit('Negado.');
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_alfredo.php';

@set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
alfredo_self_heal($pdo);

$lockKey = 'alfredo_lock';
try {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $st->execute(array($lockKey));
    $lockVal = (string)$st->fetchColumn();
    if ($lockVal && (time() - (int)$lockVal) < 120) {
        echo "[lock] execucao em andamento ha " . (time()-(int)$lockVal) . "s — saindo.\n";
        exit;
    }
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array($lockKey, (string)time()));
} catch (Exception $e) {}
register_shutdown_function(function() use ($pdo, $lockKey) {
    try { $pdo->prepare("UPDATE configuracoes SET valor='' WHERE chave=?")->execute(array($lockKey)); } catch (Exception $e) {}
});

echo "=== Alfredo processar pendentes — " . date('d/m/Y H:i:s') . " ===\n\n";
$r = alfredo_processar_pendentes($pdo, 8);
printf("Processadas: %d\n", $r['processadas']);
printf("Sugestões:   %d\n", $r['geradas']);
printf("Erros:       %d\n", $r['erros']);
if (!empty($r['detalhes'])) {
    echo "\nDetalhes:\n";
    foreach ($r['detalhes'] as $d) echo "  - $d\n";
}
