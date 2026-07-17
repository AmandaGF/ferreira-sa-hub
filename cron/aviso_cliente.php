<?php
/**
 * cron/aviso_cliente.php — Processa andamentos pendentes e avisa clientes.
 *
 * Rodar a cada 2min via cPanel:
 *   curl -s "https://ferreiraesa.com.br/conecta/cron/aviso_cliente.php?key=fsa-hub-deploy-2026"
 *
 * SAFE by default: se configuracoes.aviso_cliente_ativo != '1', nao envia
 * nada. Amanda liga em /admin/aviso_cliente.php quando estiver confortavel.
 */

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); exit('Negado.');
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_aviso_cliente.php';

@set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

// Lock: evita 2 execucoes simultaneas
$lockKey = 'aviso_cliente_lock';
try {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $st->execute(array($lockKey));
    $lockVal = (string)$st->fetchColumn();
    if ($lockVal && (time() - (int)$lockVal) < 240) {
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

echo "=== Aviso Cliente — " . date('d/m/Y H:i:s') . " ===\n\n";

$r = aviso_cliente_processar_pendentes($pdo, 15);

printf("Feature ativa:        %s\n", $r['ativo'] ? 'SIM' : 'NAO (killswitch)');
printf("Pendentes na fila:    %d\n", $r['pendentes_total']);
printf("Processados:          %d\n", $r['processados']);
printf("Enviados:             %d\n", $r['enviados']);
printf("Ignorados (silenc):   %d\n", $r['ignorados_silenciado']);
printf("Ignorados (tipo):     %d\n", $r['ignorados_tipo']);
printf("Ignorados (s/fone):   %d\n", $r['ignorados_sem_fone']);
printf("Ignorados (s/client): %d\n", $r['ignorados_sem_cliente']);
printf("Erros:                %d\n", $r['erros']);
if (!empty($r['detalhes'])) {
    echo "\nDetalhes:\n";
    foreach ($r['detalhes'] as $d) echo "  - $d\n";
}
