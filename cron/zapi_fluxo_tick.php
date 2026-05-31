<?php
/**
 * Cron: tick do motor de fluxos do WhatsApp.
 *
 * Varre execucoes em estado='aguardando' com aguardando_ate <= NOW() e avança
 * cada uma. Quando o cliente NÃO responde no prazo do bloco 'esperar', o cron
 * destrava chamando fluxo_avancar(execId, null) — o bloco esperar passa pela
 * saída 'default' e a execução continua.
 *
 * Sugerido configurar no cPanel pra rodar a cada 1 minuto via HTTP:
 *   * * * * *  curl -s "https://ferreiraesa.com.br/conecta/cron/zapi_fluxo_tick.php?key=fsa-hub-deploy-2026"
 *
 * Idempotente, leve, sem efeitos colaterais quando não há execução vencida.
 * Lock leve pra evitar 2 instâncias simultâneas (cap de 5 minutos).
 */

$isCli = php_sapi_name() === 'cli';
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_fluxos.php';

if (!$isCli && !_fluxo_admin_check_key($_GET['key'] ?? '')) { die('Acesso negado.'); }
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

$pdo = db();
$inicio = microtime(true);

// Lock leve
$lockFile = dirname(__DIR__) . '/files/zapi_fluxo_tick.lock';
if (file_exists($lockFile)) {
    $age = time() - @filemtime($lockFile);
    if ($age < 300) { echo "OUTRO TICK EM EXECUCAO (lock ha {$age}s)\n"; exit; }
    @unlink($lockFile);
}
@file_put_contents($lockFile, date('Y-m-d H:i:s'));

echo "=== zapi_fluxo_tick @ " . date('Y-m-d H:i:s') . " ===\n";

try {
    // Pega execucoes vencidas. LIMIT 100 por tick pra ficar leve;
    // se houver volume maior, próximo tick pega o resto.
    $st = $pdo->query(
        "SELECT id, fluxo_id, conversa_id, bloco_atual_id, aguardando_ate
           FROM zapi_fluxo_execucao
          WHERE estado = 'aguardando'
            AND aguardando_ate IS NOT NULL
            AND aguardando_ate <= NOW()
          ORDER BY aguardando_ate ASC
          LIMIT 100"
    );
    $vencidas = $st->fetchAll();
    echo "Execucoes vencidas: " . count($vencidas) . "\n";

    $okCount = 0; $errCount = 0; $concluidoCount = 0; $aguardandoNovo = 0;
    foreach ($vencidas as $e) {
        try {
            // Entrada NULL = timeout do bloco esperar
            $res = fluxo_avancar((int)$e['id'], null);
            $estado = $res['estado'] ?? '?';
            if ($estado === 'concluido') $concluidoCount++;
            elseif ($estado === 'aguardando') $aguardandoNovo++;
            elseif ($estado === 'erro') {
                $errCount++;
                echo "  [ERR] exec#{$e['id']}: " . ($res['erro'] ?? 'sem detalhe') . "\n";
            }
            else $okCount++;
        } catch (Exception $ex) {
            $errCount++;
            echo "  [EXC] exec#{$e['id']}: " . $ex->getMessage() . "\n";
        }
    }

    $dur = round((microtime(true) - $inicio) * 1000);
    echo "Resumo: concluidas=$concluidoCount aguardando_novo=$aguardandoNovo outros=$okCount erros=$errCount duracao={$dur}ms\n";
} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . "\n";
}

@unlink($lockFile);

// Marca timestamp da última execução pra UI mostrar saúde do cron
@file_put_contents(dirname(__DIR__) . '/files/zapi_fluxo_tick.last', date('Y-m-d H:i:s'));

echo "=== Fim ===\n";
